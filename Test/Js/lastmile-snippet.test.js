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
const TAG = {
    src: 'https://assets.conversionwax.com/lastmile/v1/lm.js',
    siteId: '0f8fad5b-d9cb-469f-a165-70867728950e',
    integrationId: '7c9e6679-7425-40de-944b-e07fc1f90ae7',
};
const ALLOWED = 'a=1; user_allowed_save_cookie=%7B%221%22%3A1%7D';
const plain = (value) => JSON.parse(JSON.stringify(value));

// Runs the inline script the way the browser would. Every network API throws, so a test fails if the
// script ever calls Magento (or anything else); the only thing it may load is the lm.js element.
function start({ restriction = false, context = null, cookie = '', readyState = 'complete', cw, jquery = false }) {
    const handlers = {};
    const docHandlers = {};
    const jqueryHandlers = {};
    const injected = [];
    const network = () => { throw new Error('the snippet script must not make requests'); };
    const win = {};
    const doc = {
        cookie,
        readyState,
        addEventListener: (name, fn) => { docHandlers[name] = fn; },
        createElement: (name) => {
            assert.strictEqual(name, 'script');
            return { tagName: 'SCRIPT', attributes: {}, setAttribute(key, value) { this.attributes[key] = value; } };
        },
        head: {
            appendChild: (element) => {
                // Snapshot of the queue at injection time: lm.js drains whatever is there when it runs.
                injected.push({ element, queue: plain(win.cw || []) });
            },
        },
    };
    Object.assign(win, {
        document: doc,
        addEventListener: (name, fn) => { handlers[name] = fn; },
        fetch: network,
        XMLHttpRequest: network,
        navigator: { sendBeacon: network },
    });
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
        .replace('__CONTEXT__', context ? JSON.stringify(context) : 'null')
        .replace('__TAG__', JSON.stringify(TAG));
    vm.runInNewContext(script, win);
    return { win, handlers, docHandlers, jqueryHandlers, injected };
}

test('window.cw stays a plain array for lm.js to drain', () => {
    const page = start({ context: PRODUCT });
    assert.ok(Array.isArray(page.win.cw));
    assert.strictEqual(page.win.cw.consentState, undefined, 'the module installs no consent API of its own');
});

test('the injected tag carries src, async and the data attributes', () => {
    const page = start({});
    assert.strictEqual(page.injected.length, 1);
    const { element } = page.injected[0];
    assert.strictEqual(element.src, TAG.src);
    assert.strictEqual(element.async, true);
    assert.deepStrictEqual(plain(element.attributes), {
        'data-cwax-site-id': TAG.siteId,
        'data-cwax-integration-id': TAG.integrationId,
        'data-cwax-gpc': 'honor',
    });
});

test('restriction off: context is queued, then lm.js is injected at once with no consent push', () => {
    const page = start({ context: PRODUCT });
    assert.strictEqual(page.injected.length, 1);
    assert.deepStrictEqual(page.injected[0].queue, [['context', PRODUCT]]);
    assert.deepStrictEqual(Object.keys(page.handlers), []);
});

test('restriction off without a product page queues nothing and still injects', () => {
    const page = start({});
    assert.strictEqual(page.injected.length, 1);
    assert.deepStrictEqual(page.injected[0].queue, []);
});

test('restriction on and already allowed: context and consent true are queued before injection', () => {
    const page = start({ restriction: true, context: PRODUCT, cookie: ALLOWED, jquery: true });
    assert.strictEqual(page.injected.length, 1);
    assert.deepStrictEqual(page.injected[0].queue, [['context', PRODUCT], ['consent', true]]);
    assert.strictEqual(page.handlers['user-allowed-save-cookie'], undefined);
    assert.deepStrictEqual(page.jqueryHandlers, {});
});

test('restriction on and not allowed: only context is queued, no consent false, lm.js is not loaded', () => {
    const page = start({ restriction: true, context: PRODUCT, cookie: 'mage-cache-sessid=true' });
    assert.strictEqual(page.injected.length, 0);
    assert.deepStrictEqual(plain(page.win.cw), [['context', PRODUCT]]);
});

test('an empty allow cookie is not consent', () => {
    const page = start({ restriction: true, cookie: 'user_allowed_save_cookie=' });
    assert.strictEqual(page.injected.length, 0);
});

test('Hyva: accepting the cookie notice queues consent true and injects lm.js', () => {
    const page = start({ restriction: true, context: PRODUCT });
    page.handlers['user-allowed-save-cookie']();
    assert.strictEqual(page.injected.length, 1);
    assert.deepStrictEqual(page.injected[0].queue, [['context', PRODUCT], ['consent', true]]);
});

test('Luma: the jQuery cookie notice event queues consent true and injects lm.js', () => {
    const page = start({ restriction: true, jquery: true, readyState: 'loading' });
    assert.strictEqual(page.jqueryHandlers['user:allowed:save:cookie'], undefined, 'waits for RequireJS');
    page.docHandlers.DOMContentLoaded();
    page.jqueryHandlers['user:allowed:save:cookie']();
    assert.strictEqual(page.injected.length, 1);
    assert.deepStrictEqual(page.injected[0].queue, [['consent', true]]);
});

test('both events, or one event twice, inject once and push consent once', () => {
    const page = start({ restriction: true, jquery: true });
    page.handlers['user-allowed-save-cookie']();
    page.jqueryHandlers['user:allowed:save:cookie']();
    page.handlers['user-allowed-save-cookie']();
    assert.strictEqual(page.injected.length, 1);
    assert.deepStrictEqual(plain(page.win.cw), [['consent', true]]);
});

test('existing queued commands are kept and the queue is appended to', () => {
    const queued = [['identify', 'c1']];
    const page = start({ context: PRODUCT, cw: queued });
    assert.strictEqual(page.win.cw, queued);
    assert.deepStrictEqual(page.injected[0].queue, [['identify', 'c1'], ['context', PRODUCT]]);
});

test('Hyva: no RequireJS is needed', () => {
    const page = start({ restriction: true, readyState: 'loading' });
    assert.doesNotThrow(() => page.docHandlers.DOMContentLoaded());
});

test('the template renders no static lm.js tag', () => {
    const markup = phtml.slice(phtml.indexOf('\nJS;'));
    assert.ok(!/<script[^>]*\ssrc=/.test(markup));
    assert.ok(!markup.includes('lm.js'));
});

test('the script never touches the Last Mile cookies, storage or Magento sections', () => {
    for (const forbidden of ['_cwid', '_cws', '_cwvs', '_cwms', 'section/load', 'cwax-visitor', 'localStorage']) {
        assert.ok(!source.includes(forbidden), forbidden);
    }
});
