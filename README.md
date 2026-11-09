# ConversionWax for Magento

Magento 2 module `Cwax_Banner` provides the existing ConversionWax banner widget and the native
Last Mile server-event integration.

## Last Mile architecture

Page and product views come from the Last Mile browser snippet (`lm.js`), which the module loads on
the storefront (see "Views come from lm.js" below). Cart, checkout, login and order events stay on
the server: storefront observers only build a small event envelope and publish it to Magento's
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

| Source | Last Mile event | Key data |
| --- | --- | --- |
| `lm.js` in the browser, every page | `page_view` | path, referrer, device (snippet provenance) |
| `lm.js` on a product page | `product_view` | SKU from `cw.push(['context', ...])` |
| `sales_quote_product_add_after` | `add_to_cart` | SKU, including REST and GraphQL quote mutation |
| Checkout controller predispatch | `checkout_start` | path |
| `checkout_submit_all_after` | `purchase` | increment ID, grand total, currency, customer, first SKU |
| `customer_login` | `identify` | customer ID, SHA-256 email hash |
| `customer_register_success` | `identify` | customer ID, SHA-256 email hash |

Raw email addresses and visitor IP addresses are not sent by Magento.

### Views come from lm.js

A page served from Magento's built-in full-page cache, Varnish, Fastly, Cloudflare or another CDN
never runs the product controller, and a CDN hit never reaches PHP at all, so a server observer
cannot see those views. The module therefore adds the Last Mile browser snippet (`lm.js`) to every
storefront page, on Luma and Hyva themes alike, and `lm.js` sends `page_view` and `product_view`
straight to the ConversionWax collector. A page view costs no request to Magento PHP: the tag and
its small inline script are the same for every visitor, so they are safe in any HTML cache.

The tag goes in the page head:

```html
<script>/* queue: window.cw = window.cw || []; cw.push([...]) */</script>
<script async src="https://assets.conversionwax.com/lastmile/v1/lm.js"
        data-cwax-site-id="WEBSITE_ID" data-cwax-integration-id="INTEGRATION_ID"
        data-cwax-gpc="honor"></script>
```

The inline script runs first and only ever uses the array form of `window.cw`, which `lm.js`
drains when it loads and then replaces with its live API:

- On a product page: `cw.push(['context', { productId: '<SKU>', pageType: 'product', platform:
  'magento' }])`. The SKU is the same `productId` the server commerce events use. The product page
  also keeps `<meta name="cwax-product" content="<SKU>">` for any other front end.
- With Magento Cookie Restriction Mode on: `cw.push(['consent', false])` until the visitor allows
  cookies and `cw.push(['consent', true])` once they have. Clicking Allow pushes
  `['consent', true]` at once (Hyva's `user-allowed-save-cookie` window event, Luma's
  `user:allowed:save:cookie` jQuery event), without a page load. With restriction off nothing is
  pushed, and `lm.js` applies the install's own consent mode.

The collector serves Magento installs a config with `serverCommerce: true` and no commerce
patterns, so `lm.js` sends views, `identify` and `track` only. Cart, checkout, login and order
events stay server events, whatever caches the HTML. A visitor whose browser blocks `lm.js` (or
has JavaScript off) sends no views; there is no server fallback for views. Browser prefetches and
prerenders run no snippet code until the page is shown, and server events skip prefetch and
prerender requests (`Sec-Purpose`, `Purpose`, `X-Purpose`, `X-Moz`).

The Staging environment loads the staging build
(`https://dev.assets.conversionwax.com/lastmile/v1/lm.js`), which reports to the staging collector.
`lm.js` does not start on `localhost`, `*.localhost` or `*.local` hosts.

## Visitor identity

`lm.js` owns visitor identity on the storefront. It writes the first-party cookies `_cwid` (visitor
UUID, 400 days), `_cws` (session, URI-encoded JSON with the session id `sid`) and `_cwvs` (the
collector-signed visitor summary), as described in the Last Mile cookie contract (C1). It applies the
install's consent mode (`wait_for_consent` or `capture_immediately`) itself, and deletes the three
cookies when consent is withdrawn.

Server commerce events read `_cwid` and `_cws.sid`, so server and browser events join on the same
visitor and session. They take the session count and has-purchased flag from the `_cwvs` claims.
Magento writes cookies only on responses that are never cacheable (an add to cart, login,
registration or order POST, a REST order call, the checkout page), never on a cacheable page:

- With storage consent and no `_cwid` (because `lm.js` is blocked, or another front end runs it), it
  mints `_cwid` and a session. Its own `_cws` carries an extra `"mg": 1` key; Magento keeps that
  session alive for 30 minutes of inactivity and never rewrites a session `lm.js` started.
- It sends the existing `_cwid` again with `Set-Cookie` (same value, Max-Age 400 days, `Path=/`,
  `SameSite=Lax`, `Secure`, not `HttpOnly`). Safari limits a cookie written by script to 7 days, or 24
  hours after a tracked link, but not one set by an HTTP response.

The module has no private-content section, no `_cwms` cookie and no visitor-sync script any more.

## Install or upgrade

Install this package in `app/code/Cwax/Banner` or through Composer, then run:

```bash
bin/magento module:enable Cwax_Banner
bin/magento setup:upgrade
bin/magento cache:flush
```

The package requires PHP 8.1 or later and Magento 2.4.

## Connect a site

1. In ConversionWax, open the website's Last Mile settings and add a Magento install under
   Integrations.
2. Copy the website's Website ID, then generate the install's connection token and copy it
   immediately (it is shown once).
3. Open the install's Embed code tab and copy its Integration ID (`data-cwax-integration-id`).
4. In Magento Admin, open Stores > Configuration > ConversionWax > Last Mile.
5. Set Enabled to Yes and enter the Website ID, Integration ID and connection token.
6. Leave Environment on Production. Choose Staging only to test against the ConversionWax staging
   service.
7. Leave Load Last Mile on Magento storefront on Yes, unless another front end already loads its
   own `lm.js` for this website. Flush the cache.

Settings (all can be set per store view):

- **ConversionWax Website ID.** The `data-cwax-site-id` of the tag and the `storeId` of every server
  event.
- **Integration ID.** The install's id from the Embed code tab. It must be a UUID (checked at save
  time). While it is empty, Magento adds no `lm.js` tag, so no views are tracked; server commerce
  events do not need it.
- **Connection Token.** Authenticates server events and the consent mode lookup. Stored encrypted.
- **Environment.** Production or Staging. Each maps to a fixed collector host and `lm.js` URL in the
  module; no URL is a setting.
- **Load Last Mile on Magento storefront** (default Yes). One switch for Luma and Hyva. No means
  another front end (for example a headless storefront) runs its own `lm.js`: the module adds no tag
  and no browser script, and keeps sending server commerce events.

The token is shown once by ConversionWax, stored only as a SHA-256 digest centrally, and stored by
Magento using its encrypted configuration backend. Configure values per store view when different
Magento storefronts map to different ConversionWax websites.

## Consent

In the browser, `lm.js` decides. Its `window.cw.consentState()` returns `'granted'`, `'denied'` or
`'pending'` (`pending` under `wait_for_consent` before any decision), and `window` dispatches
`cw:consent` with `{ detail: { state } }` on every change; `granted` is dispatched only once the
cookies exist. Before `lm.js` has loaded, `window.cw` is the plain array queue and has no
`consentState`; readers fall back to cookie presence. The ConversionWax banner embed reads these.

**A store using a third-party CMP (OneTrust, Cookiebot, etc.) under `wait_for_consent` must wire that
CMP to call `cw.consent(true)` (and `cw.consent(false)` on decline)**, or push
`['consent', true|false]` onto `window.cw` before `lm.js` loads. The module only forwards Magento's
own cookie notice. A CMP that never calls `cw.consent()` leaves every visitor `pending`: no views,
no `_cws`, and no server events (see below).

**With Magento Cookie Restriction Mode on, set the install's consent mode to Wait for consent in
ConversionWax.** Under Capture immediately, `lm.js` starts capture (cookies and the session start)
before it reads the queued `['consent', false]`, and only then stops and deletes the cookies.

On the server, a composite of small resolvers (`Cwax\Banner\Api\ConsentResolverInterface`, wired in
`etc/di.xml`) decides whether a server commerce event may be sent. Each resolver either abstains,
grants, or denies two named signals, `storage` (may `_cwid`/`_cws` be read or minted at all) and
`measurement` (may an event be published to the queue). A signal is only ever `true` in the final
decision when at least one resolver granted it and no resolver denied it - any single denial vetoes
that signal for the whole request, no matter how many resolvers granted it.

Resolvers included in this module:

- **Global Privacy Control.** A `Sec-GPC: 1` request header denies both signals unconditionally.
  Its absence proves nothing and is not treated as consent. The tag sets `data-cwax-gpc="honor"`,
  so `lm.js` refuses under GPC too.
- **Magento Cookie Restriction Mode.** Denies both signals only when restriction mode is enabled
  and the visitor has not accepted. It never affirmatively grants on its own; it is a veto, not a
  primary gate.
- **Consent mode.** Reads the store's `consentMode` (`capture_immediately` or `wait_for_consent`)
  from the ConversionWax collector (`GET /v1/integrations/config` on the selected environment's
  host, authenticated with the connection token as `Authorization: Bearer`), cached locally for 5
  minutes on success and 30 seconds when unresolved. Under `capture_immediately` it grants both
  signals, matching `lm.js`. Under `wait_for_consent` it abstains and lets the resolver below
  decide. If the config cannot be resolved at all (network failure, unknown or inactive token,
  website switched off), it denies both signals: there is no fallback policy.
- **Browser snippet identity.** Under a confirmed `wait_for_consent` mode only, this resolver reads
  `lm.js`'s session cookie (`_cws`, not `_cwid`/`_cwvs`, which persist 400 days and only prove
  consent was granted at some point, not that it applies now). An `lm.js` session (a JSON object
  with a `sid` and no `mg` key) means `cw.consent(true)` ran this browser session; anything else is
  treated as an explicit denial. A session Magento minted is never evidence of consent.

**Under `wait_for_consent` a visitor without `lm.js` consent sends no server events.** No cookie is
minted and no event is queued until `lm.js` has written `_cws` after `cw.consent(true)`. This
mirrors `lm.js`'s own behavior, and nothing in this module buffers events across requests to work
around it.

A third party (or a future ConversionWax module) adds its own resolver purely by declaring, in its
own `di.xml`, the same `Cwax\Banner\Model\Consent\CompositeConsentResolver` array-argument node with
one additional `<item>` - no edit to this module, no plugin, no preference override.

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
ConversionWax script, style, image, and connection hosts (`*.conversionwax.com`), which also covers
`lm.js` and the collector it reports to.
