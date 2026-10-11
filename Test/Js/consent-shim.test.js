'use strict';
const test = require('node:test');
const assert = require('node:assert');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const phtml = fs.readFileSync(path.join(__dirname, '../../view/frontend/templates/visitor-sync.phtml'), 'utf8');
const source = /<<<'JS'\n([\s\S]*?)\nJS;/.exec(phtml)[1];

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

test('cached granted section answers granted', () => {
    const cw = run({ restriction: true, storage: JSON.stringify({ 'cwax-visitor': { state: 'granted' } }) });
    assert.strictEqual(cw.__mgShim, true);
    assert.strictEqual(cw.consentState(), 'granted');
});

test('any other cached state answers denied, even with the allow cookie', () => {
    for (const state of ['denied', 'error', 'off', 'weird']) {
        const cw = run({
            restriction: true,
            cookie: 'user_allowed_save_cookie=%7B%221%22%3A1%7D',
            storage: JSON.stringify({ 'cwax-visitor': { state } })
        });
        assert.strictEqual(cw.consentState(), 'denied', state);
    }
});

test('no cached state: always denied, never pending (server-only inputs cannot be mirrored)', () => {
    for (const opts of [
        { restriction: false },
        { restriction: true, cookie: 'user_allowed_save_cookie=%7B%221%22%3A1%7D' },
        { restriction: true, gpc: true },
        { restriction: false, storage: 'not json' },
        { restriction: false, storage: 'throw' },
        { restriction: false, storage: JSON.stringify({ other: {} }) }
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
