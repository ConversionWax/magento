# Changelog

## 0.4.0

- The collector endpoint is no longer a merchant setting. A new Environment select (Production by
  default, or Staging) picks a fixed ConversionWax collector host.
- Server events now go to `/v1/integrations/events` on the selected host. The old
  `/v1/integrations/magento/events` route is no longer used.
- The session call and the consent config lookup use the same selected host.
- The `conversionwax_lastmile/connection/endpoint` setting and its default are removed. Any saved
  value is ignored, so no data patch is needed.
- Admin help text no longer shows a URL.

Releases up to 0.3.1 are recorded in their git tags and release commits.
