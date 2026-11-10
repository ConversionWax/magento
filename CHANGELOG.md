# Changelog

## Unreleased

- Docs and admin text: the Integration ID is on the install's Connect Magento step in
  ConversionWax. The Magento install never had an Embed code tab.

## 0.5.0

Breaking. Needs the Last Mile release with commit ec3b503 or later: the collector must serve
`/v1/config/<integrationId>` for Magento installs with `serverCommerce: true` (an older collector
answers 404, and `lm.js` then tracks nothing), and `lm.js` must support `['context', ...]` and
`serverCommerce` (an older `lm.js` ignores the product context, so no `product_view` is sent, and
could also pick up dataLayer commerce events).

- `page_view` and `product_view` now come from the Last Mile browser snippet (`lm.js`), which the
  module loads on every storefront page on Luma and Hyva themes. Pages served by the
  built-in full-page cache, Varnish or a CDN such as Cloudflare were invisible before: a cache hit
  never ran the product controller (no `product_view`), and a CDN or Varnish hit never reached PHP
  (no `page_view` after the session start). A page view now causes no request to Magento PHP.
- There is no static `lm.js` tag: a small inline script queues the page's product
  (`['context', { productId: '<sku>', pageType: 'product', platform: 'magento' }]`) on the array
  form of `window.cw` and then injects the tag. With Cookie Restriction Mode on, it waits for the
  visitor's Allow (or an existing allow cookie), queues `['consent', true]` and only then injects
  the tag, once, so `lm.js` sets no cookie and calls no collector before consent, whatever the
  install's consent mode. The module no longer installs a `window.cw` object or a `consentState`
  shim of its own: `lm.js` provides `cw.consentState()` and the `cw:consent` event.
- Cart, checkout, login and order events stay server events and read `lm.js`'s `_cwid` and `_cws`
  (session id) cookies, so browser and server events join on visitor and session. Magento mints
  `_cwid` and a session only on uncacheable commerce responses (add to cart, checkout, order
  placement, login, registration) when consent is granted and `_cwid` is missing, and sends an
  existing `_cwid` again on those responses so Safari's 7-day limit on script-written cookies does
  not cut the visitor short. It never sets a cookie on a cacheable response.
- The `PageViewObserver` and `ProductViewObserver` server observers are removed, so an uncached
  page is not counted twice.
- Product pages render `<meta name="cwax-product" content="<sku>">` in the head. It is the same for
  every visitor, so it is safe in the full-page cache, and another front end can read it.
- Browser prefetch and prerender requests (`Sec-Purpose`, `Purpose`, `X-Purpose` or `X-Moz` with
  prefetch, prerender or preview) no longer send Last Mile events or touch visitor cookies.
- New setting: Integration ID (`conversionwax_lastmile/connection/integration_id`), from the
  install's Embed code tab in ConversionWax, checked as a UUID at save time. Without it no `lm.js`
  tag is added and no views are tracked.
- Renamed setting: Enable Hyva Support (`connection/hyva_enabled`) is replaced by Load Last Mile on
  Magento storefront (`connection/storefront_snippet`, default Yes), one switch for Luma and Hyva.
  No means another front end runs its own `lm.js`; server commerce events are sent either way. A
  data patch gives every store view on a Hyva theme (the theme or a parent under `Hyva/`) whose
  effective Enable Hyva Support was No the new setting = No at its store-view scope, so it stays
  off. Luma store views never read the old setting and now load `lm.js` by default. The old rows are
  deleted.
- Removed settings: Browser Snippet Installed (`consent/snippet_installed`) and Server-Only Fallback
  Policy (`consent/fallback_policy`), and the Consent group. A consent mode that cannot be resolved
  from ConversionWax now always denies server events (it was the Deny default before). The stored
  values are deleted by the same data patch.
- Retired: the `_cwms` cookie (lm.js's `_cws` is the only session cookie), the `cwax-visitor`
  private-content section and its `customer/section/load` requests, the visitor-sync scripts (Luma
  RequireJS component and Hyva start-up), the `_cwer` referrer cookie, and the server-side
  `/v1/session` call that minted `_cws` and `_cwvs` from Magento (`lm.js` does that now). The buyer
  claims (`hp`, `lp`) in `_cwvs` are no longer refreshed by Magento right after an order.
- The tag sets `data-cwax-gpc="honor"`, so Global Privacy Control stops `lm.js` as it already
  stopped server events.

## 0.4.1

- Last Mile events now report the URL the visitor requested (for example `/blue-shirt.html`), not
  the internal route the URL rewrite resolves to (`/catalog/product/view/id/27`). Pages without a
  rewrite and REST calls are unchanged, and guest cart ids are still masked.
- Tests now pin that each product page request sends one `product_view` and one `page_view`, that
  a delivered message is never queued again, and that a retry keeps its event id.

## 0.4.0

- The collector endpoint is no longer a merchant setting. A new Environment select (Production by
  default, or Staging) picks a fixed ConversionWax collector host.
- Server events now go to `/v1/integrations/events` on the selected host. The old
  `/v1/integrations/magento/events` route is no longer used.
- The session call uses the same selected host.
- Consent mode is now read from `GET /v1/integrations/config` with the connection token as a Bearer
  header. The old `/v1/config/<websiteId>` lookup is gone. Caching and the fallback on errors are
  unchanged. A store with no token makes no request.
- The `conversionwax_lastmile/connection/endpoint` setting and its default are removed. Any saved
  value is ignored, so no data patch is needed.
- Admin help text no longer shows a URL.

Releases up to 0.3.2 are recorded in their git tags and release commits.
