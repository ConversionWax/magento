# Changelog

## Unreleased

- Browser prefetch and prerender requests (`Sec-Purpose`, `Purpose`, `X-Purpose` or `X-Moz` with
  prefetch, prerender or preview) no longer send Last Mile events or touch visitor cookies. Hyva
  prefetches product links by default, so each prefetched product page reported a `product_view`
  and a `page_view` the shopper never saw.

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
