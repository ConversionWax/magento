/**
 * Keeps window.cw.consentState() and the cwax-visitor section in step with the Magento cookie notice.
 * The section PHP does the real work (cookies, collector). This file only:
 *  - fires cw:consent when the synchronous window.cw.consentState() changes (the shim is in the template),
 *  - forces one section reload when the stored section disagrees with the visitor's cookie choice or
 *    Global Privacy Control.
 *  - reports each page view: once customer-data is ready it loads the section with the page path and, on a
 *    product page, the SKU from product-view.phtml (the server turns them into page_view and product_view).
 *    A prerendered page waits until it is shown. A stored denial nothing has changed sends nothing.
 */
define([
    'jquery',
    'Magento_Customer/js/customer-data',
    'mage/url',
    'mage/cookies'
], function ($, customerData, urlBuilder) {
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
        var reportSent = false;

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

        function view() {
            var meta = document.querySelector('meta[name="cwax-product"]');
            var product = meta && meta.getAttribute('content');
            var params = { sections: SECTION, cw_path: window.location.pathname };

            if (product) {
                params.cw_product = product;
            }

            return params;
        }

        // The section load with this page's view. It also refreshes the section, so no reload is needed after it.
        function report() {
            reloaded = true;
            reportSent = true;
            remember();
            $.ajax({
                url: urlBuilder.build('customer/section/load/'),
                type: 'GET',
                dataType: 'json',
                data: view()
            }).done(function (data) {
                var next = data && data[SECTION];

                if (!(next && next.state === 'granted')) {
                    reportSent = false;
                }
                if (next) {
                    customerData.set(SECTION, next);
                }
            }).fail(function () {
                reportSent = false;
            });
        }

        function wantsReport(data) {
            var state = data && data.state;

            if (state === 'off' || state === 'error') {
                return false;
            }

            return state === 'granted'
                || (needsReload(data, restriction, cookieAllowed(), gpc()) && (!state || inputsChanged()));
        }

        function begin() {
            var data = section();

            if (document.prerendering) {
                document.addEventListener('prerenderingchange', begin, { once: true });
                return;
            }
            if (wantsReport(data)) {
                publish();
                report();
            } else {
                apply(data);
            }
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
            if (reportSent) {
                reload();
            } else {
                report();
            }
        });
        // customer-data may reload sections of its own first; reporting after it keeps one session start.
        if (typeof customerData.getInitCustomerData === 'function') {
            customerData.getInitCustomerData().done(begin);
        } else {
            begin();
        }
    }

    init.needsReload = needsReload;

    return init;
});
