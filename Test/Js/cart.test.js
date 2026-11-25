'use strict';
const test = require('node:test');
const assert = require('node:assert');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const phtml = fs.readFileSync(path.join(__dirname, '../../view/frontend/templates/cart.phtml'), 'utf8');
const match = /<<<'JS'\n([\s\S]*?)\nJS;/.exec(phtml);
assert.ok(match, 'script nowdoc not found in cart.phtml');
const source = match[1];
const plain = (value) => JSON.parse(JSON.stringify(value));

// A Knockout-like observable, the shape customerData.get() returns.
function observable(value) {
    const subscribers = [];
    const read = () => value;
    read.subscribe = (fn) => { subscribers.push(fn); };
    read.set = (next) => { value = next; subscribers.forEach((fn) => fn(next)); };
    return read;
}

// Runs the inline script the way the browser would. Every network and storage API throws, so a test
// fails if the script ever calls a server or stores the cart.
function start({ luma = null, readyState = 'complete', currency = 'USD' } = {}) {
    const handlers = {};
    const docHandlers = {};
    const events = [];
    const forbidden = () => { throw new Error('the cart script must not make requests or store data'); };
    const win = {
        document: { readyState, addEventListener: (name, fn) => { docHandlers[name] = fn; } },
        addEventListener: (name, fn) => { handlers[name] = fn; },
        dispatchEvent: (event) => { events.push({ type: event.type, detail: plain(event.detail) }); return true; },
        CustomEvent: class { constructor(type, init) { this.type = type; this.detail = init.detail; } },
        fetch: forbidden,
        XMLHttpRequest: forbidden,
        navigator: { sendBeacon: forbidden },
    };
    Object.defineProperty(win, 'localStorage', { get: forbidden });
    if (luma) {
        win.require = (deps, fn) => {
            assert.deepStrictEqual(plain(deps), ['Magento_Customer/js/customer-data']);
            fn({ get: (name) => { assert.strictEqual(name, 'cart'); return luma; } });
        };
    }
    win.window = win;
    vm.runInNewContext(source.replace('__CURRENCY__', JSON.stringify(currency)), win);
    const hyva = (cart) => handlers['private-content-loaded']({ detail: { data: { cart } } });
    return { win, events, docHandlers, hyva };
}

const SECTION = { summary_count: 2, subtotalAmount: 42.5, subtotal: '<span class="price">$42.50</span>' };
const CART = { total: 42.5, currency: 'USD', items: 2 };

test('Luma: the cart section already in storage is sent at once', () => {
    const page = start({ luma: observable(SECTION) });
    assert.deepStrictEqual(plain(page.win.cwaxCart), CART);
    assert.deepStrictEqual(page.events, [{ type: 'cwax:cart', detail: CART }]);
});

test('Luma: each cart section update sets cwaxCart again and dispatches cwax:cart', () => {
    const cart = observable({});
    const page = start({ luma: cart });
    assert.strictEqual(page.win.cwaxCart, undefined, 'an empty section is unknown, not an empty cart');
    cart.set({ summary_count: 1, subtotalAmount: 20 });
    cart.set({ summary_count: 3, subtotalAmount: 80 });
    cart.set({ summary_count: 0, subtotalAmount: 0 });
    assert.deepStrictEqual(page.events.map((e) => e.detail), [
        { total: 20, currency: 'USD', items: 1 },
        { total: 80, currency: 'USD', items: 3 },
        { total: 0, currency: 'USD', items: 0 },
    ]);
    assert.deepStrictEqual(plain(page.win.cwaxCart), { total: 0, currency: 'USD', items: 0 });
});

test('Luma: waits for DOMContentLoaded before it asks RequireJS', () => {
    const page = start({ luma: observable(SECTION), readyState: 'loading' });
    assert.strictEqual(page.events.length, 0);
    page.docHandlers.DOMContentLoaded();
    assert.deepStrictEqual(page.events.map((e) => e.detail), [CART]);
});

test('the same cart twice is sent once', () => {
    const cart = observable(SECTION);
    const page = start({ luma: cart });
    cart.set({ ...SECTION, data_id: 2 });
    assert.strictEqual(page.events.length, 1);
});

test('Hyva: private-content-loaded sends the cart section', () => {
    const page = start();
    page.hyva(SECTION);
    assert.deepStrictEqual(page.events.map((e) => e.detail), [CART]);
    assert.deepStrictEqual(plain(page.win.cwaxCart), CART);
});

test('Hyva: an event without data, or without a cart, does not throw and sends nothing', () => {
    const page = start();
    assert.doesNotThrow(() => page.hyva(undefined));
    assert.doesNotThrow(() => page.win.addEventListener && page.hyva({}));
    assert.strictEqual(page.win.cwaxCart, undefined);
    assert.strictEqual(page.events.length, 0);
});

test('no RequireJS and no event: nothing is set and nothing throws', () => {
    const page = start();
    assert.strictEqual(page.win.cwaxCart, undefined);
    assert.strictEqual(page.events.length, 0);
});

test('mapping: an empty cart with no subtotal is total 0', () => {
    const page = start();
    page.hyva({ summary_count: 0, subtotalAmount: null });
    assert.deepStrictEqual(page.events.map((e) => e.detail), [{ total: 0, currency: 'USD', items: 0 }]);
});

test('mapping: numeric strings are read as numbers, a decimal quantity rounds up', () => {
    const page = start();
    page.hyva({ summary_count: '1.5', subtotalAmount: '12.30' });
    assert.deepStrictEqual(page.events.map((e) => e.detail), [{ total: 12.3, currency: 'USD', items: 2 }]);
});

test('mapping: bad sections keep the cart unknown', () => {
    const page = start();
    [
        null,
        'cart',
        {},
        { data_id: 1 },
        { summary_count: 2 },
        { summary_count: 2, subtotalAmount: null },
        { summary_count: -1, subtotalAmount: 10 },
        { summary_count: 'two', subtotalAmount: 10 },
        { summary_count: 1, subtotalAmount: -5 },
        { summary_count: 1, subtotalAmount: '' },
        { summary_count: 1, subtotalAmount: 'abc' },
        { summary_count: 1, subtotalAmount: Infinity },
        { summary_count: true, subtotalAmount: 10 },
    ].forEach((section) => page.hyva(section));
    assert.strictEqual(page.win.cwaxCart, undefined);
    assert.strictEqual(page.events.length, 0);
});

test('a bad section after a good one keeps the last good cart', () => {
    const page = start();
    page.hyva(SECTION);
    page.hyva({});
    assert.deepStrictEqual(plain(page.win.cwaxCart), CART);
    assert.strictEqual(page.events.length, 1);
});

test('the currency is the store value the template fills in', () => {
    const page = start({ currency: 'EUR' });
    page.hyva(SECTION);
    assert.deepStrictEqual(page.events[0].detail, { total: 42.5, currency: 'EUR', items: 2 });
});

test('a failing dispatch does not break the customer-data subscriber', () => {
    const cart = observable({});
    const page = start({ luma: cart });
    page.win.dispatchEvent = () => { throw new Error('listener failed'); };
    assert.doesNotThrow(() => cart.set(SECTION));
});

test('only the three contract fields are sent, never line items or HTML', () => {
    const page = start();
    page.hyva({ ...SECTION, items: [{ product_name: 'Bag', qty: 2 }], extra_actions: '<button/>' });
    assert.deepStrictEqual(Object.keys(page.events[0].detail).sort(), ['currency', 'items', 'total']);
});
