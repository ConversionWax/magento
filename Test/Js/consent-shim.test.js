'use strict';
const test = require('node:test');
const assert = require('node:assert');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const phtml = fs.readFileSync(path.join(__dirname, '../../view/frontend/templates/visitor-sync.phtml'), 'utf8');
const match = /<<<'JS'\n([\s\S]*?)\nJS;/.exec(phtml);
assert.ok(match, 'shim nowdoc not found in visitor-sync.phtml');
const source = match[1];

const SESS = 'mage-cache-sessid=true; ';
const ALLOW = 'user_allowed_save_cookie=%7B%221%22%3A1%7D';

function run({ restriction, storage, cookie = '', gpc, cw }) {
    const win = { cw, navigator: { globalPrivacyControl: gpc } };
    win.window = win;
    win.document = { cookie };
    win.localStorage = {
        getItem: (key) => {
            if (storage === 'throw') { throw new Error('blocked'); }
            return key === 'mage-cache-storage' ? (storage === undefined ? null : storage) : null;
        }
    };
    vm.runInNewContext(source.replace('__RESTRICTION__', String(restriction)), win);
    return win.cw;
}

test('cached granted section is trusted with the session cookie and the allow cookie', () => {
    const cw = run({ restriction: true, cookie: SESS + ALLOW, storage: JSON.stringify({ 'cwax-visitor': { state: 'granted' } }) });
    assert.strictEqual(cw.__mgShim, true);
    assert.strictEqual(cw.consentState(), 'granted');
    assert.strictEqual(cw.__mgLast, 'granted');
});

test('restriction off needs only the session cookie', () => {
    const cw = run({ restriction: false, cookie: SESS, storage: JSON.stringify({ 'cwax-visitor': { state: 'granted' } }) });
    assert.strictEqual(cw.consentState(), 'granted');
});

test('cached granted with restriction on and no allow cookie answers denied (withdrawn consent)', () => {
    const cw = run({ restriction: true, cookie: SESS, storage: JSON.stringify({ 'cwax-visitor': { state: 'granted' } }) });
    assert.strictEqual(cw.consentState(), 'denied');
});

test('cached granted without mage-cache-sessid answers denied (new browser session)', () => {
    const cw = run({ restriction: false, cookie: ALLOW, storage: JSON.stringify({ 'cwax-visitor': { state: 'granted' } }) });
    assert.strictEqual(cw.consentState(), 'denied');
});

test('any other cached state answers denied, even with the allow cookie', () => {
    for (const state of ['denied', 'error', 'off', 'weird']) {
        const cw = run({ restriction: true, cookie: SESS + ALLOW, storage: JSON.stringify({ 'cwax-visitor': { state } }) });
        assert.strictEqual(cw.consentState(), 'denied', state);
    }
});

test('no cached state or unreadable storage: denied, never pending', () => {
    for (const opts of [
        { restriction: false, cookie: SESS },
        { restriction: true, cookie: SESS + ALLOW },
        { restriction: true, cookie: SESS + ALLOW, gpc: true },
        { restriction: false, cookie: SESS, storage: 'not json' },
        { restriction: false, cookie: SESS, storage: 'throw' },
        { restriction: false, cookie: SESS, storage: JSON.stringify({ other: {} }) }
    ]) {
        assert.strictEqual(run(opts).consentState(), 'denied', JSON.stringify(opts));
    }
});

test('an existing consentState implementation is left alone', () => {
    const foreign = { consentState: () => 'granted' };
    const cw = run({ restriction: true, cw: foreign });
    assert.strictEqual(cw, foreign);
    assert.strictEqual(cw.__mgShim, undefined);
});
