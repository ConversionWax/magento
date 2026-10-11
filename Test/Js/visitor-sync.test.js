'use strict';
const test = require('node:test');
const assert = require('node:assert');

let factory;
global.define = (deps, fn) => { factory = fn; };
require('../../view/frontend/web/js/visitor-sync.js');

function setup(initial, cookieAllowed, session = new Map(), lastRead) {
    const events = [];
    const reloads = [];
    const handlers = {};
    let subscriber = null;
    let data = initial;
    const win = new EventTarget();
    // Mirrors the template shim: the cached section decides, anything else is denied.
    win.cw = { consentState: function () { return data && data.state === 'granted' ? 'granted' : 'denied'; }, __mgShim: true };
    win.addEventListener('cw:consent', (e) => events.push(e.detail.state));
    if (lastRead !== undefined) { win.cw.__mgLast = lastRead; }
    win.sessionStorage = {
        getItem: (k) => (session.has(k) ? session.get(k) : null),
        setItem: (k, v) => { session.set(k, v); }
    };
    win.navigator = {};
    global.window = win;
    global.document = {};
    global.CustomEvent = CustomEvent;
    const $ = (target) => ({ on: (ev, fn) => { handlers[ev] = fn; } });
    $.mage = { cookies: { get: () => (cookieAllowed.value ? '{"1":1}' : null) } };
    const section = () => data;
    section.subscribe = (fn) => { subscriber = fn; };
    const customerData = {
        get: () => section,
        reload: (names, force) => { reloads.push([names, force]); }
    };
    const init = factory($, customerData);
    return {
        init, events, reloads, handlers, win,
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
});

test('init publishes the stored state and fires cw:consent only on change', () => {
    const ctx = setup({ state: 'granted', synced: true }, { value: true });
    ctx.init({ restriction: true });
    assert.strictEqual(ctx.win.cw.consentState(), 'granted');
    assert.deepStrictEqual(ctx.events, []);
    ctx.push({ state: 'granted', synced: true });
    assert.deepStrictEqual(ctx.events, []);
    assert.strictEqual(ctx.reloads.length, 0);
});

test('withdrawn consent: section still granted but cookie gone -> one reload, denied on the new section', () => {
    const cookie = { value: false };
    const ctx = setup({ state: 'granted' }, cookie);
    ctx.init({ restriction: true });
    assert.strictEqual(ctx.reloads.length, 1);
    assert.deepStrictEqual(ctx.reloads[0], [['cwax-visitor'], true]);
    assert.deepStrictEqual(ctx.events, []);
    ctx.push({ state: 'denied' });
    assert.deepStrictEqual(ctx.events, ['denied']);
    assert.strictEqual(ctx.win.cw.consentState(), 'denied');
    ctx.push({ state: 'granted' });
    assert.strictEqual(ctx.reloads.length, 1, 'no reload loop');
});

test('the Magento allow event re-arms one reload and cw:consent fires when the new section arrives', () => {
    const cookie = { value: false };
    const ctx = setup({ state: 'denied' }, cookie);
    ctx.init({ restriction: true });
    assert.strictEqual(ctx.reloads.length, 0);
    cookie.value = true;
    ctx.handlers['user:allowed:save:cookie']();
    assert.strictEqual(ctx.reloads.length, 1);
    assert.deepStrictEqual(ctx.events, []);
    ctx.push({ state: 'granted' });
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
    assert.strictEqual(first.reloads.length, 1, 'first page reloads once and records the inputs');
    const second = setup({ state: 'denied' }, cookie, session);
    second.init({ restriction: true });
    assert.strictEqual(second.reloads.length, 0);
    cookie.value = false;
    const third = setup({ state: 'granted' }, cookie, session);
    third.init({ restriction: true });
    assert.strictEqual(third.reloads.length, 1, 'a changed input reloads again');
});

test('an empty section always reloads once per page', () => {
    const session = new Map([['cwax-visitor-inputs', '10']]);
    const ctx = setup({}, { value: true }, session);
    ctx.init({ restriction: true });
    assert.strictEqual(ctx.reloads.length, 1);
});
