'use strict';
const test = require('node:test');
const assert = require('node:assert');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const phtml = fs.readFileSync(path.join(__dirname, '../../view/frontend/templates/lastmile.phtml'), 'utf8');
const match = /<<<'JS'\n([\s\S]*?)\nJS;/.exec(phtml);
assert.ok(match, 'script nowdoc not found in lastmile.phtml');
const source = match[1];

const PRODUCT = { productId: 'MH01-XS-Black', pageType: 'product', platform: 'magento' };
const plain = (value) => JSON.parse(JSON.stringify(value));

// Runs the inline script the way the browser would, before lm.js. Every network API throws, so a test
// fails if the script ever calls Magento (or anything else).
function start({ restriction = false, context = null, cookie = '', readyState = 'complete', cw, jquery = false }) {
    const handlers = {};
    const docHandlers = {};
    const jqueryHandlers = {};
    const network = () => { throw new Error('the snippet script must not make requests'); };
    const doc = {
        cookie,
        readyState,
        addEventListener: (name, fn) => { docHandlers[name] = fn; },
    };
    const win = {
        document: doc,
        addEventListener: (name, fn) => { handlers[name] = fn; },
        fetch: network,
        XMLHttpRequest: network,
        navigator: { sendBeacon: network },
    };
    if (cw !== undefined) {
        win.cw = cw;
    }
    if (jquery) {
        win.require = (deps, fn) => {
            assert.deepStrictEqual(plain(deps), ['jquery']);
            fn((target) => ({ on: (name, cb) => { assert.strictEqual(target, doc); jqueryHandlers[name] = cb; } }));
        };
    }
    win.window = win;
    const script = source
        .replace('__RESTRICTION__', restriction ? 'true' : 'false')
        .replace('__CONTEXT__', context ? JSON.stringify(context) : 'null');
    vm.runInNewContext(script, win);
    return { win, handlers, docHandlers, jqueryHandlers };
}

// What lm.js does on load (packages/snippet/src/index.ts installLiveApi): drain the array, then replace it.
function loadLmJs(win) {
    const received = [];
    const pending = Array.isArray(win.cw) ? win.cw : [];
    win.cw = { push: (command) => received.push(command), consentState: () => 'pending' };
    pending.forEach((command) => received.push(command));
    return received;
}

test('window.cw stays a plain array for lm.js to drain', () => {
    const page = start({ restriction: true });
    assert.ok(Array.isArray(page.win.cw));
    assert.strictEqual(page.win.cw.consentState, undefined, 'the module installs no consent API of its own');
});

test('a page without restriction or product pushes nothing, so lm.js applies its own consent mode', () => {
    const page = start({});
    assert.deepStrictEqual(plain(page.win.cw || []), []);
    assert.deepStrictEqual(Object.keys(page.handlers), []);
});

test('a product page pushes its SKU as context', () => {
    const page = start({ context: PRODUCT });
    assert.deepStrictEqual(plain(page.win.cw), [['context', PRODUCT]]);
});

test('with restriction on and no allow cookie the queue says consent false', () => {
    const page = start({ restriction: true, cookie: 'mage-cache-sessid=true' });
    assert.deepStrictEqual(plain(page.win.cw), [['consent', false]]);
});

test('with restriction on and the allow cookie the queue says consent true', () => {
    const page = start({ restriction: true, context: PRODUCT, cookie: 'a=1; user_allowed_save_cookie=%7B%221%22%3A1%7D' });
    assert.deepStrictEqual(plain(page.win.cw), [['context', PRODUCT], ['consent', true]]);
});

test('an empty allow cookie is not consent', () => {
    const page = start({ restriction: true, cookie: 'user_allowed_save_cookie=' });
    assert.deepStrictEqual(plain(page.win.cw), [['consent', false]]);
});

test('existing queued commands are kept and the queue is appended to', () => {
    const queued = [['identify', 'c1']];
    const page = start({ restriction: true, cw: queued });
    assert.strictEqual(page.win.cw, queued);
    assert.deepStrictEqual(plain(page.win.cw), [['identify', 'c1'], ['consent', false]]);
});

test('lm.js drains the queued commands in order', () => {
    const page = start({ restriction: true, context: PRODUCT });
    const received = loadLmJs(page.win);
    assert.deepStrictEqual(plain(received), [['context', PRODUCT], ['consent', false]]);
});

test('Hyva: accepting the cookie notice pushes consent true to the queue before lm.js loads', () => {
    const page = start({ restriction: true });
    page.handlers['user-allowed-save-cookie']();
    assert.deepStrictEqual(plain(page.win.cw), [['consent', false], ['consent', true]]);
});

test('Hyva: accepting after lm.js loaded pushes consent true to the live API', () => {
    const page = start({ restriction: true });
    const received = loadLmJs(page.win);
    page.handlers['user-allowed-save-cookie']();
    assert.deepStrictEqual(plain(received), [['consent', false], ['consent', true]]);
});

test('Luma: the jQuery cookie notice event pushes consent true once the page has loaded', () => {
    const page = start({ restriction: true, jquery: true, readyState: 'loading' });
    assert.strictEqual(page.jqueryHandlers['user:allowed:save:cookie'], undefined, 'waits for RequireJS');
    page.docHandlers.DOMContentLoaded();
    const received = loadLmJs(page.win);
    page.jqueryHandlers['user:allowed:save:cookie']();
    assert.deepStrictEqual(plain(received), [['consent', false], ['consent', true]]);
});

test('Luma: an already parsed page binds the jQuery event at once', () => {
    const page = start({ restriction: true, jquery: true });
    assert.strictEqual(typeof page.jqueryHandlers['user:allowed:save:cookie'], 'function');
});

test('Hyva: no RequireJS is needed', () => {
    const page = start({ restriction: true, readyState: 'loading' });
    assert.doesNotThrow(() => page.docHandlers.DOMContentLoaded());
});

test('without restriction no cookie notice listener is added', () => {
    const page = start({ jquery: true });
    assert.strictEqual(page.handlers['user-allowed-save-cookie'], undefined);
    assert.deepStrictEqual(page.jqueryHandlers, {});
});

test('the script never touches the Last Mile cookies, storage or Magento sections', () => {
    for (const forbidden of ['_cwid', '_cws', '_cwvs', '_cwms', 'section/load', 'cwax-visitor', 'localStorage']) {
        assert.ok(!source.includes(forbidden), forbidden);
    }
});
