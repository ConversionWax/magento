/**
 * Keeps window.cw.consentState() and the cwax-visitor section in step with the Magento cookie notice.
 * The section PHP does the real work (cookies, collector). This file only:
 *  - fires cw:consent when the synchronous window.cw.consentState() changes (the shim is in the template),
 *  - forces one section reload when the stored section disagrees with the visitor's cookie choice.
 * It sends no events of its own.
 */
define([
    'jquery',
    'Magento_Customer/js/customer-data',
    'mage/cookies'
], function ($, customerData) {
    'use strict';

    var SECTION = 'cwax-visitor';
    var ALLOW_COOKIE = 'user_allowed_save_cookie';

    function needsReload(data, restriction, cookieAllowed) {
        var state = data && data.state;
        var expected = restriction ? cookieAllowed : true;

        if (!state) {
            return true;
        }
        if (state === 'error' || state === 'off') {
            return false;
        }

        return expected ? state !== 'granted' : state === 'granted';
    }

    function init(config) {
        var restriction = !!(config && config.restriction);
        var section = customerData.get(SECTION);
        var reloaded = false;

        function cookieAllowed() {
            return !!$.mage.cookies.get(ALLOW_COOKIE);
        }

        var last = window.cw && window.cw.__mgShim === true ? window.cw.consentState() : null;

        function publish() {
            var cw = window.cw;
            var next;

            // Only drive the API this module installed. Never override another implementation.
            if (!cw || cw.__mgShim !== true) {
                return;
            }
            next = cw.consentState();
            if (next === last) {
                return;
            }
            last = next;
            window.dispatchEvent(new CustomEvent('cw:consent', { detail: { state: next } }));
        }

        function reload() {
            reloaded = true;
            customerData.reload([SECTION], true);
        }

        function apply(data) {
            publish();
            if (!reloaded && needsReload(data, restriction, cookieAllowed())) {
                reload();
            }
        }

        section.subscribe(apply);
        $(document).on('click', '#btn-cookie-allow', function () {
            // Magento's own handler already stored the cookie (it runs on the button, before document).
            reloaded = false;
            publish();
            reload();
        });
        apply(section());
    }

    init.needsReload = needsReload;

    return init;
});
