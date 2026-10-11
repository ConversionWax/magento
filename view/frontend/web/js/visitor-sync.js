/**
 * Keeps window.cw.consentState() and the cwax-visitor section in step with the Magento cookie notice.
 * The section PHP does the real work (cookies, collector). This file only:
 *  - fires cw:consent when the synchronous window.cw.consentState() changes (the shim is in the template),
 *  - forces one section reload when the stored section disagrees with the visitor's cookie choice or
 *    Global Privacy Control.
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
    var ALLOW_EVENT = 'user:allowed:save:cookie';
    var MARKER = 'cwax-visitor-inputs';

    // gpc: navigator.globalPrivacyControl is on. The server then denies (Sec-GPC), so a stored grant is stale.
    function needsReload(data, restriction, cookieAllowed, gpc) {
        var state = data && data.state;
        var expected = !gpc && (restriction ? cookieAllowed : true);

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

        function gpc() {
            return !!(window.navigator && window.navigator.globalPrivacyControl === true);
        }

        function inputs() {
            return (cookieAllowed() ? '1' : '0') + (gpc() ? '1' : '0');
        }

        // True when a browser-visible input differs from the one the last reload ran for. Server-only
        // reasons for a denial (collector mode, fallback policy) never change here, so they cause no
        // reload loop on later pages. Storage failure counts as changed (still once per page).
        function inputsChanged() {
            try {
                return window.sessionStorage.getItem(MARKER) !== inputs();
            } catch (e) {
                return true;
            }
        }

        function remember() {
            try {
                window.sessionStorage.setItem(MARKER, inputs());
            } catch (e) {
                // ignore
            }
        }

        // Start from what the embed last read, so a change since then is always published.
        var last = null;
        if (window.cw && window.cw.__mgShim === true) {
            last = window.cw.__mgLast !== undefined ? window.cw.__mgLast : window.cw.consentState();
        }

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
            remember();
            customerData.reload([SECTION], true);
        }

        function apply(data) {
            publish();
            if (!reloaded && needsReload(data, restriction, cookieAllowed(), gpc())
                && (!(data && data.state) || inputsChanged())) {
                reload();
            }
        }

        section.subscribe(apply);
        $(document).on(ALLOW_EVENT, function () {
            // Magento's cookie notice triggers this after it stored the allow cookie.
            reloaded = false;
            publish();
            reload();
        });
        apply(section());
    }

    init.needsReload = needsReload;

    return init;
});
