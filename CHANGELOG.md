# Changelog

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

Releases up to 0.3.1 are recorded in their git tags and release commits.
