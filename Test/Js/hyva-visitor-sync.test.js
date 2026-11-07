'use strict';
const test = require('node:test');
const assert = require('node:assert');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const phtml = fs.readFileSync(path.join(__dirname, '../../view/frontend/templates/visitor-sync/hyva-init.phtml'), 'utf8');
const match = /<<<'JS'\n([\s\S]*?)\nJS;/.exec(phtml);
assert.ok(match, 'script nowdoc not found in hyva-init.phtml');
const source = match[1];

const NOW = 1800000000;

function start({ section, restriction = false, cookie = '', gpc, marker = null, response = { state: 'granted', data_id: NOW },
    path = '/blue-shirt.html', sku = null, prerendering = false, timers = null }) {
    const store = { 'mage-cache-storage': JSON.stringify(section ? { cart: { x: 1 }, 'cwax-visitor': section } : { cart: { x: 1 } }) };
    const handlers = {};
    const fetches = [];
    const docHandlers = {};
    const consentEvents = [];
    const win = {
        BASE_URL: 'https://shop.test/',
        navigator: { globalPrivacyControl: gpc },
        localStorage: { getItem: (k) => (k in store ? store[k] : null), setItem: (k, v) => { store[k] = v; } },
        sessionStorage: { getItem: () => marker, setItem: () => {} },
        location: { pathname: path },
        document: {
            cookie,
            readyState: 'complete',
            prerendering,
            querySelector: (selector) => (sku && selector === 'meta[name="cwax-product"]'
                ? { getAttribute: (name) => (name === 'content' ? sku : null) }
                : null),
            addEventListener: (name, fn) => { docHandlers[name] = fn; },
        },
        addEventListener: (name, fn) => { handlers[name] = fn; },
        dispatchEvent: (event) => consentEvents.push(event.detail.state),
        CustomEvent: function (type, init) { this.type = type; this.detail = init.detail; },
        // timers: an array to hold callbacks the test runs itself; otherwise every timer fires at once.
        setTimeout: (fn, ms) => (timers ? timers.push({ fn, ms }) : fn()),
        Date: { now: () => NOW * 1000 },
        Promise,
        fetch: (url, options) => {
            fetches.push({ url, options });
            return Promise.resolve({ ok: true, json: () => Promise.resolve({ 'cwax-visitor': response }) });
        },
        cw: { __mgShim: true, consentState: () => JSON.parse(store['mage-cache-storage'])['cwax-visitor']?.state === 'granted' ? 'granted' : 'denied' },
    };
    win.window = win;
    vm.runInNewContext(source.replace('__CONFIG__', JSON.stringify({ restriction })), win);
    return { win, store, handlers, docHandlers, fetches, consentEvents, settled: () => new Promise((r) => setImmediate(r)) };
}

test('a first visit loads the section with no help from Hyva', async () => {
    const page = start({});
    await page.settled();
    assert.strictEqual(page.fetches.length, 1);
    assert.strictEqual(page.fetches[0].url, 'https://shop.test/customer/section/load/?sections=cwax-visitor&cw_path=%2Fblue-shirt.html');
    assert.strictEqual(page.fetches[0].options.headers['X-Requested-With'], 'XMLHttpRequest');
});

test('the result is merged into the Hyva section storage', async () => {
    const page = start({});
    await page.settled();
    const stored = JSON.parse(page.store['mage-cache-storage']);
    assert.strictEqual(stored['cwax-visitor'].state, 'granted');
    assert.deepStrictEqual(JSON.parse(JSON.stringify(stored.cart)), { x: 1 });
});

test('a change of consent state is published', async () => {
    const page = start({});
    await page.settled();
    assert.deepStrictEqual(page.consentEvents, ['granted']);
});

test('a fresh granted section sends one request, the view report', async () => {
    const page = start({ section: { state: 'granted', data_id: NOW - 60 } });
    await page.settled();
    assert.strictEqual(page.fetches.length, 1);
    assert.match(page.fetches[0].url, /&cw_path=%2Fblue-shirt\.html$/);
});

test('a section older than the 30-minute session is loaded again', async () => {
    const page = start({ section: { state: 'granted', data_id: NOW - 1801 } });
    await page.settled();
    assert.strictEqual(page.fetches.length, 1);
});

test('a stored grant is reloaded when Global Privacy Control turned on', async () => {
    const page = start({ section: { state: 'granted', data_id: NOW - 60 }, gpc: true });
    await page.settled();
    assert.strictEqual(page.fetches.length, 1);
});

test('a stored denial stays quiet while the inputs are the same', async () => {
    const page = start({ section: { state: 'denied', data_id: NOW - 60 }, marker: '00' });
    await page.settled();
    assert.strictEqual(page.fetches.length, 0);
});

test('off and error states cause no request', async () => {
    const off = start({ section: { state: 'off', data_id: NOW } });
    const error = start({ section: { state: 'error', data_id: NOW } });
    await off.settled();
    assert.strictEqual(off.fetches.length + error.fetches.length, 0);
});

test('accepting the Hyva cookie notice loads the section, with the view only if none was reported', async () => {
    const page = start({ section: { state: 'granted', data_id: NOW - 60 } });
    await page.settled();
    page.handlers['user-allowed-save-cookie']();
    await page.settled();
    assert.strictEqual(page.fetches.length, 2);
    assert.strictEqual(page.fetches[1].url, 'https://shop.test/customer/section/load/?sections=cwax-visitor');

    const denied = start({ section: { state: 'denied', data_id: NOW - 60 }, marker: '00', restriction: true });
    await denied.settled();
    assert.strictEqual(denied.fetches.length, 0);
    denied.handlers['user-allowed-save-cookie']();
    await denied.settled();
    assert.match(denied.fetches[0].url, /&cw_path=/);
});

test('section data loaded by Hyva publishes the new state', async () => {
    const page = start({ section: { state: 'denied', data_id: NOW - 60 }, restriction: true });
    await page.settled();
    page.store['mage-cache-storage'] = JSON.stringify({ 'cwax-visitor': { state: 'granted', data_id: NOW } });
    page.handlers['private-content-loaded']();
    assert.deepStrictEqual(page.consentEvents, ['granted']);
});

test('a product page reports its SKU with the path', async () => {
    const page = start({ path: '/ocmlabs/seo.html', sku: 'seo & co' });
    await page.settled();
    assert.strictEqual(
        page.fetches[0].url,
        'https://shop.test/customer/section/load/?sections=cwax-visitor&cw_path=%2Focmlabs%2Fseo.html&cw_product=seo%20%26%20co'
    );
});

test('the report waits for Hyva private content, then fires once', async () => {
    const timers = [];
    const page = start({ section: { state: 'granted', data_id: NOW - 60 }, timers });
    assert.strictEqual(page.fetches.length, 0);
    page.handlers['private-content-loaded']();
    // Deferred past Hyva's own storage write.
    timers.filter((t) => t.ms === 0 || t.ms === undefined).forEach((t) => t.fn());
    timers.filter((t) => t.ms === 3000).forEach((t) => t.fn());
    page.handlers['private-content-loaded']();
    timers.forEach((t) => t.fn());
    await page.settled();
    assert.strictEqual(page.fetches.length, 1);
});

test('without Hyva private content the report still goes after the fallback delay', async () => {
    const timers = [];
    const page = start({ section: { state: 'granted', data_id: NOW - 60 }, timers });
    assert.deepStrictEqual(timers.map((t) => t.ms), [3000]);
    timers[0].fn();
    await page.settled();
    assert.strictEqual(page.fetches.length, 1);
});

test('a prerendered page reports only once it is shown', async () => {
    const page = start({ section: { state: 'granted', data_id: NOW - 60 }, prerendering: true });
    await page.settled();
    assert.strictEqual(page.fetches.length, 0);
    page.win.document.prerendering = false;
    page.docHandlers.prerenderingchange();
    await page.settled();
    assert.strictEqual(page.fetches.length, 1);
});

test('a denied report lets the cookie notice report the view again', async () => {
    const page = start({ response: { state: 'denied', data_id: NOW } });
    await page.settled();
    page.handlers['user-allowed-save-cookie']();
    await page.settled();
    assert.strictEqual(page.fetches.length, 2);
    assert.match(page.fetches[1].url, /&cw_path=/);
});
