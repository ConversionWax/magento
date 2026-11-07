'use strict';
const test = require('node:test');
const assert = require('node:assert');

let factory;
global.define = (deps, fn) => { factory = fn; };
require('../../view/frontend/web/js/visitor-sync.js');

function setup(initial, cookieAllowed, session = new Map(), lastRead, gpc = { value: false }, page = {}) {
    const events = [];
    const reloads = [];
    const reports = [];
    const docHandlers = {};
    const handlers = {};
    let subscriber = null;
    let data = initial;
    const win = new EventTarget();
    // Mirrors the template shim: GPC denies first, then the cached section decides, anything else is denied.
    win.cw = {
        consentState: function () {
            if (win.navigator.globalPrivacyControl === true) { return 'denied'; }
            return data && data.state === 'granted' ? 'granted' : 'denied';
        },
        __mgShim: true
    };
    win.addEventListener('cw:consent', (e) => events.push(e.detail.state));
    if (lastRead !== undefined) { win.cw.__mgLast = lastRead; }
    win.sessionStorage = {
        getItem: (k) => (session.has(k) ? session.get(k) : null),
        setItem: (k, v) => { session.set(k, v); }
    };
    win.navigator = {};
    Object.defineProperty(win.navigator, 'globalPrivacyControl', { get: () => gpc.value });
    win.location = { pathname: page.path || '/blue-shirt.html' };
    global.window = win;
    global.document = {
        prerendering: page.prerendering,
        querySelector: (selector) => (page.sku && selector === 'meta[name="cwax-product"]'
            ? { getAttribute: (name) => (name === 'content' ? page.sku : null) }
            : null),
        addEventListener: (name, fn) => { docHandlers[name] = fn; }
    };
    global.CustomEvent = CustomEvent;
    const $ = (target) => ({ on: (ev, fn) => { handlers[ev] = fn; } });
    $.mage = { cookies: { get: () => (cookieAllowed.value ? '{"1":1}' : null) } };
    // The report request: resolved by the test with respond(), like the server answering the section load.
    $.ajax = (options) => {
        const request = { options, done: null, fail: null };
        reports.push(request);
        const chain = {
            done: (fn) => { request.done = fn; return chain; },
            fail: (fn) => { request.fail = fn; return chain; }
        };
        return chain;
    };
    const section = () => data;
    section.subscribe = (fn) => { subscriber = fn; };
    const customerData = {
        get: () => section,
        reload: (names, force) => { reloads.push([names, force]); },
        set: (name, next) => { data = next; subscriber(next); }
    };
    if (page.initDeferred) {
        customerData.getInitCustomerData = () => ({ done: (fn) => { page.initDeferred.resolve = fn; } });
    }
    const init = factory($, customerData, { build: (path) => 'https://shop.test/' + path });
    return {
        init, events, reloads, reports, handlers, docHandlers, win,
        loads: () => reloads.length + reports.length,
        respond: (next) => { reports[reports.length - 1].done({ 'cwax-visitor': next }); },
        push: (next) => { data = next; subscriber(next); }
    };
}

test('needsReload compares expected consent with the stored state', () => {
    const { init } = setup({}, { value: false });
    assert.strictEqual(init.needsReload({}, true, false), true);
    assert.strictEqual(init.needsReload({ state: 'denied' }, true, false), false);
    assert.strictEqual(init.needsReload({ state: 'denied' }, true, true), true);
    assert.strictEqual(init.needsReload({ state: 'granted' }, true, false), true);
    assert.strictEqual(init.needsReload({ state: 'granted' }, true, true), false);
    assert.strictEqual(init.needsReload({ state: 'denied' }, false, false), true);
    assert.strictEqual(init.needsReload({ state: 'granted' }, false, false), false);
    assert.strictEqual(init.needsReload({ state: 'error' }, false, false), false);
    // GPC on: only denied is current, whatever the cookie choice.
    assert.strictEqual(init.needsReload({ state: 'granted' }, false, false, true), true);
    assert.strictEqual(init.needsReload({ state: 'granted' }, true, true, true), true);
    assert.strictEqual(init.needsReload({ state: 'denied' }, true, true, true), false);
    assert.strictEqual(init.needsReload({ state: 'error' }, true, true, true), false);
});

test('init publishes the stored state and fires cw:consent only on change', () => {
    const ctx = setup({ state: 'granted', synced: true }, { value: true });
    ctx.init({ restriction: true });
    assert.strictEqual(ctx.win.cw.consentState(), 'granted');
    assert.deepStrictEqual(ctx.events, []);
    ctx.push({ state: 'granted', synced: true });
    assert.deepStrictEqual(ctx.events, []);
    assert.strictEqual(ctx.reloads.length, 0);
    assert.strictEqual(ctx.reports.length, 1, 'the page view is reported once');
});

test('withdrawn consent: section still granted but cookie gone -> one reload, denied on the new section', () => {
    const cookie = { value: false };
    const ctx = setup({ state: 'granted' }, cookie);
    ctx.init({ restriction: true });
    assert.strictEqual(ctx.loads(), 1);
    assert.strictEqual(ctx.reports.length, 1, 'the report request refreshes the section');
    assert.deepStrictEqual(ctx.events, []);
    ctx.respond({ state: 'denied' });
    assert.deepStrictEqual(ctx.events, ['denied']);
    assert.strictEqual(ctx.win.cw.consentState(), 'denied');
    ctx.push({ state: 'granted' });
    assert.strictEqual(ctx.loads(), 1, 'no reload loop');
});

test('the Magento allow event re-arms one reload and cw:consent fires when the new section arrives', () => {
    const cookie = { value: false };
    const ctx = setup({ state: 'denied' }, cookie);
    ctx.init({ restriction: true });
    assert.strictEqual(ctx.loads(), 0);
    cookie.value = true;
    ctx.handlers['user:allowed:save:cookie']();
    assert.strictEqual(ctx.reports.length, 1, 'the view not yet reported goes with the reload');
    assert.deepStrictEqual(ctx.events, []);
    ctx.respond({ state: 'granted' });
    assert.deepStrictEqual(ctx.events, ['granted']);
    assert.strictEqual(ctx.win.cw.consentState(), 'granted');
    ctx.push({ state: 'granted' });
    assert.deepStrictEqual(ctx.events, ['granted']);
});

test('a foreign consentState implementation is never touched', () => {
    const ctx = setup({ state: 'granted' }, { value: true });
    ctx.win.cw = { consentState: () => 'granted' };
    ctx.init({ restriction: true });
    ctx.push({ state: 'denied' });
    assert.deepStrictEqual(ctx.events, []);
});

test('a change since the embed last read is published even if the shim is already corrected at init', () => {
    // The embed read 'granted' from a stale cache; by init the cache is cleared so the shim says denied.
    const ctx = setup({ state: 'denied' }, { value: false }, new Map(), 'granted');
    ctx.init({ restriction: true });
    assert.deepStrictEqual(ctx.events, ['denied']);
});

test('a server-denied visitor with unchanged browser inputs causes no reload on the next page', () => {
    const session = new Map();
    const cookie = { value: true };
    const first = setup({ state: 'denied' }, cookie, session);
    first.init({ restriction: true });
    assert.strictEqual(first.loads(), 1, 'first page loads once and records the inputs');
    const second = setup({ state: 'denied' }, cookie, session);
    second.init({ restriction: true });
    assert.strictEqual(second.loads(), 0);
    cookie.value = false;
    const third = setup({ state: 'granted' }, cookie, session);
    third.init({ restriction: true });
    assert.strictEqual(third.loads(), 1, 'a changed input loads again');
});

test('an empty section always reloads once per page', () => {
    const session = new Map([['cwax-visitor-inputs', '10']]);
    const ctx = setup({}, { value: true }, session);
    ctx.init({ restriction: true });
    assert.strictEqual(ctx.loads(), 1);
});

test('GPC turned on after a cached grant triggers one corrective reload and publishes denied', () => {
    const session = new Map();
    const cookie = { value: true };
    const gpc = { value: false };
    // An earlier page recorded the inputs for a grant with GPC off.
    const before = setup({ state: 'granted' }, cookie, session, undefined, gpc);
    before.init({ restriction: true });
    assert.strictEqual(before.reloads.length, 0);
    assert.strictEqual(before.reports.length, 1);
    session.set('cwax-visitor-inputs', '10');

    gpc.value = true;
    const ctx = setup({ state: 'granted' }, cookie, session, 'granted', gpc);
    ctx.init({ restriction: true });
    assert.strictEqual(ctx.loads(), 1, 'stale grant is loaded again');
    assert.strictEqual(session.get('cwax-visitor-inputs'), '11');
    assert.deepStrictEqual(ctx.events, ['denied']);
    ctx.respond({ state: 'denied' });
    assert.deepStrictEqual(ctx.events, ['denied']);
    assert.strictEqual(ctx.loads(), 1, 'no reload loop');

    const next = setup({ state: 'denied' }, cookie, session, undefined, gpc);
    next.init({ restriction: true });
    assert.strictEqual(next.loads(), 0, 'unchanged inputs on the next page: no request');
});

test('the report carries the page path and, on a product page, the SKU', () => {
    const ctx = setup({ state: 'granted' }, { value: true }, new Map(), undefined, { value: false },
        { path: '/ocmlabs/seo.html', sku: 'seo' });
    ctx.init({ restriction: true });
    assert.strictEqual(ctx.reports.length, 1);
    assert.strictEqual(ctx.reports[0].options.url, 'https://shop.test/customer/section/load/');
    assert.strictEqual(ctx.reports[0].options.type, 'GET');
    assert.deepStrictEqual(ctx.reports[0].options.data,
        { sections: 'cwax-visitor', cw_path: '/ocmlabs/seo.html', cw_product: 'seo' });
});

test('a page that is not a product page reports only its path', () => {
    const ctx = setup({ state: 'granted' }, { value: true });
    ctx.init({ restriction: true });
    assert.deepStrictEqual(ctx.reports[0].options.data, { sections: 'cwax-visitor', cw_path: '/blue-shirt.html' });
});

test('off and error sections report nothing', () => {
    const off = setup({ state: 'off' }, { value: true });
    off.init({ restriction: true });
    const error = setup({ state: 'error' }, { value: true });
    error.init({ restriction: true });
    assert.strictEqual(off.loads() + error.loads(), 0);
});

test('a prerendered page reports only once it is shown', () => {
    const page = { prerendering: true };
    const ctx = setup({ state: 'granted' }, { value: true }, new Map(), undefined, { value: false }, page);
    ctx.init({ restriction: true });
    assert.strictEqual(ctx.loads(), 0);
    global.document.prerendering = false;
    ctx.docHandlers.prerenderingchange();
    assert.strictEqual(ctx.reports.length, 1);
});

test('the report waits for customer-data to finish its own start-up', () => {
    const deferred = {};
    const ctx = setup({ state: 'granted' }, { value: true }, new Map(), undefined, { value: false },
        { initDeferred: deferred });
    ctx.init({ restriction: true });
    assert.strictEqual(ctx.loads(), 0);
    deferred.resolve();
    assert.strictEqual(ctx.reports.length, 1);
});

test('after a granted report the allow event reloads without a second view', () => {
    const ctx = setup({ state: 'granted' }, { value: true });
    ctx.init({ restriction: false });
    ctx.respond({ state: 'granted' });
    ctx.handlers['user:allowed:save:cookie']();
    assert.strictEqual(ctx.reports.length, 1);
    assert.strictEqual(ctx.reloads.length, 1);
});
