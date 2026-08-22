# ConversionWax for Magento

Magento 2 module `Cwax_Banner` provides the existing ConversionWax banner widget and the native
Last Mile server-event integration.

## Last Mile architecture

Storefront observers only build a small event envelope and publish it to Magento's
`conversionwax.lastmile.events` queue. They never call ConversionWax over HTTP. The queue consumer
performs authenticated delivery, so a slow or unavailable collector is outside page rendering,
cart, login, and checkout requests.

This module declares its queues, publisher, and consumers explicitly on Magento's database queue
(`connection="db"` in `etc/queue_topology.xml`, `etc/queue_publisher.xml`, and
`etc/queue_consumer.xml`). It does not use RabbitMQ or ActiveMQ Artemis and does not auto-detect
one; the database transport is every Magento 2 install's built-in option and needs no broker.
Moving to an AMQP broker would require changing this module's queue XML and adding an AMQP package
dependency, neither of which this release does.

Both the `conversionwax.lastmile.events` queue and the `conversionwax.lastmile.failed` queue are
processed only when Magento's `consumers` cron group is running (see "Run the consumer" below).
Publishing an event only writes a row to `queue_message`; nothing else moves that row until a
consumer process reads it, so a stopped or excluded `consumers` cron group means events queue up
and never ship, even though enqueuing keeps succeeding silently on the storefront.

Transient delivery failures retry eight times with consumer-side backoff. Permanent failures and
exhausted retries move to the `conversionwax.lastmile.failed` queue, where a dedicated consumer
logs the failure metadata (event ID, attempt count, reason, failure time) at error level and lets
the message complete. No event payload, PII, or connection token is ever written to Magento logs,
and none is retained once that log line is written: the failed queue is a drain point for cleanup,
not a replay buffer.

## Events

| Magento server event | Last Mile event | Key data |
| --- | --- | --- |
| Frontend GET pre-response event | `page_view` | path, entry URL, referrer, user agent |
| `catalog_controller_product_view` | `product_view` | SKU |
| `sales_quote_product_add_after` | `add_to_cart` | SKU, including REST and GraphQL quote mutation |
| Checkout controller predispatch | `checkout_start` | path |
| `checkout_submit_all_after` | `purchase` | increment ID, grand total, currency, customer, first SKU |
| `customer_login` | `identify` | customer ID, SHA-256 email hash |
| `customer_register_success` | `identify` | customer ID, SHA-256 email hash |

Raw email addresses and visitor IP addresses are not sent. Visitor and 30-minute session identity
are maintained in first-party `_cwid` and `_cwms` cookies. A session keeps its original entry URL
and referrer, which lets Last Mile show a return visit from a different source as a new session and
source in the order timeline.

Server-side coverage has one infrastructure boundary. The pre-response observer captures page
views served from Magento's built-in full-page cache, but product classification still needs a
controller dispatch. A response served entirely upstream by Varnish, Fastly, or another CDN never
reaches Magento PHP, so neither view event is visible. Cart, checkout, login, and purchase requests
still reach Magento. Exact upstream-cache coverage requires a future server-side edge-log adapter.
The module does not silently add a browser sender.

## Install or upgrade

Install this package in `app/code/Cwax/Banner` or through Composer, then run:

```bash
bin/magento module:enable Cwax_Banner
bin/magento setup:upgrade
bin/magento cache:flush
```

The package requires PHP 8.1 or later and Magento 2.4.

## Connect a site

1. In ConversionWax, open the website's Last Mile tracking settings.
2. Under Magento server events, generate a connection token and copy it immediately.
3. In Magento Admin, open Stores > Configuration > ConversionWax > Last Mile.
4. Enable the integration and enter the ConversionWax Website ID, connection token, and collector
   endpoint.
5. For staging, use
   `https://dev.collect.conversionwax.com/v1/integrations/magento/events`. Production defaults to
   `https://collect.conversionwax.com/v1/integrations/magento/events`.

The token is shown once by ConversionWax, stored only as a SHA-256 digest centrally, and stored by
Magento using its encrypted configuration backend. Configure values per store view when different
Magento storefronts map to different ConversionWax websites.

## Consent

Consent is resolved by a composite of small resolvers (`Cwax\Banner\Api\ConsentResolverInterface`,
wired in `etc/di.xml`), not by Magento's cookie restriction notice alone. Each resolver either
abstains, grants, or denies two named signals, `storage` (may `_cwid`/`_cwms` be minted at all) and
`measurement` (may an event be published to the queue). A signal is only ever `true` in the final
decision when at least one resolver granted it and no resolver denied it - any single denial vetoes
that signal for the whole request, no matter how many resolvers granted it.

Resolvers included in this module:

- **Global Privacy Control.** A `Sec-GPC: 1` request header denies both signals unconditionally.
  Its absence proves nothing and is not treated as consent.
- **Magento Cookie Restriction Mode.** Denies both signals only when restriction mode is enabled
  and the visitor has not accepted. It never affirmatively grants on its own; it is a veto, not a
  primary gate.
- **Consent mode.** Reads the store's `consentMode` (`capture_immediately` or `wait_for_consent`)
  from the ConversionWax collector, cached locally for 5 minutes on success and 30 seconds when
  unresolved. Under `capture_immediately` it grants both signals outright, matching the browser
  snippet's own unconditional behavior in that mode. Under `wait_for_consent` it abstains and lets
  the browser-snippet resolver below decide, unless **Browser Snippet Installed** (see below) is
  set to No, in which case it falls back to the **Server-Only Fallback Policy**. If the collector's
  config cannot be resolved at all (network failure, unknown store), it also falls back to that
  policy.
- **Browser snippet identity.** Under a confirmed `wait_for_consent` mode only, this resolver reads
  the ConversionWax browser snippet's own session cookie (`_cws`, not `_cwid`/`_cwvs`, which persist
  400 days and only prove consent was granted at some point, not that it applies now). Presence
  means `cw.consent(true)` was dispatched earlier this browser session; absence is treated as an
  explicit denial. This resolver never runs under `capture_immediately` and never runs for a store
  with no snippet installed.

**A brand-new visitor's first request under `wait_for_consent` is always denied.** The snippet has
not executed yet, so no `_cws` cookie can exist on that first server-rendered request; no cookie is
minted and no event is queued for it. This is intended, not a defect: it exactly mirrors the browser
snippet's own first-pageview behavior, and nothing in this module buffers state across requests to
work around it. Once the visitor's CMP calls `cw.consent(true)`, the browser's next request to the
Magento origin carries `_cws` and normal capture resumes from there.

**A store using a third-party CMP (OneTrust, Cookiebot, etc.) instead of ConversionWax's own consent
prompt must wire that CMP to call `cw.consent(true)` (and `cw.consent(false)` on decline) from the
ConversionWax browser snippet's public API.** This module has no visibility into any third-party CMP
directly; it only ever observes the resulting `_cws` cookie the snippet mints after that call. A
merchant who runs `wait_for_consent` with a CMP that never calls `cw.consent()` will see the module
behave as if consent is permanently denied on every request, since `_cws` never appears.

A third party (or a future ConversionWax module) adds its own resolver purely by declaring, in its
own `di.xml`, the same `Cwax\Banner\Model\Consent\CompositeConsentResolver` array-argument node with
one additional `<item>` - no edit to this module, no plugin, no preference override.

### New admin fields (Stores > Configuration > ConversionWax > Last Mile > Consent)

- **Browser Snippet Installed** (default Yes). Set to No for a server-only integration with no
  ConversionWax browser snippet on the store, so `wait_for_consent` stores fall back to the policy
  below instead of waiting forever for a cookie that can never appear.
- **Server-Only Fallback Policy** (default Deny). Applies only when the consent mode cannot be
  resolved at all, or when the mode is `wait_for_consent` and no snippet is installed. Options are
  Deny (fail closed, recommended), Grant (fail open), or defer to Magento Cookie Restriction Mode.

There is no setting to disable consent checking entirely.

## Run the consumer

For a direct test:

```bash
bin/magento queue:consumers:start conversionwax.lastmile.events --max-messages=100
bin/magento queue:consumers:start conversionwax.lastmile.failed --max-messages=100
```

In production, supervise both consumers or add them to Magento's cron consumers runner. For
example, the `cron_consumers_runner` section of `app/etc/env.php` can include:

```php
'cron_consumers_runner' => [
    'cron_run' => true,
    'max_messages' => 100,
    'consumers' => [
        'conversionwax.lastmile.events',
        'conversionwax.lastmile.failed',
    ],
],
```

This only takes effect while Magento's own cron scheduler is installed and running
(`bin/magento cron:install`) and the `consumers` cron group is not excluded from that run. If
neither an external process supervisor nor Magento cron is actually executing the `consumers`
group, messages accumulate in `queue_message` and nothing is ever delivered, even though the
storefront keeps enqueuing successfully.

Monitor the `conversionwax.lastmile.events` backlog for delivery health. The
`conversionwax.lastmile.failed` queue is drained automatically: its consumer logs failure metadata
for investigation and completes the message so Magento's queue cleanup cron can purge the row.
There is no replay from this queue; use the logged event ID, attempt count, and reason to diagnose
and correct the underlying configuration or collector problem, then let the storefront's normal
event flow produce a fresh event.

## Banner widget

The original widget remains available. It accepts a `banner_div` value and loads
`https://assets.conversionwax.com/dist/app.js` on storefront pages. The CSP whitelist permits
ConversionWax script, style, image, and connection hosts.
