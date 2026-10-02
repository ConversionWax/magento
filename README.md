# ConversionWax for Magento

Magento 2 module `Cwax_Banner` provides the existing ConversionWax banner widget and the native
Last Mile server-event integration.

## Last Mile architecture

Storefront observers only build a small event envelope and publish it to Magento's
`conversionwax.lastmile.events` queue. They never call ConversionWax over HTTP. The queue consumer
performs authenticated delivery, so a slow or unavailable collector is outside page rendering,
cart, login, and checkout requests.

Magento automatically selects RabbitMQ, ActiveMQ Artemis, or its database queue from `env.php`.
Use an external broker for production volume. The database transport is a functional fallback,
not the recommended high-volume configuration.

Transient delivery failures retry eight times with consumer-side backoff. Permanent failures and
exhausted retries move to the retained `conversionwax.lastmile.failed` queue. No event payload or
connection token is written to Magento logs.

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

When Respect Magento Cookie Restriction is enabled, the module does not create visitor cookies or
queue events until Magento reports that the visitor may save cookies.

## Run the consumer

For a direct test:

```bash
bin/magento queue:consumers:start conversionwax.lastmile.events --max-messages=100
```

In production, supervise this consumer or add it to Magento's cron consumers runner. For example,
the `cron_consumers_runner` section of `app/etc/env.php` can include:

```php
'cron_consumers_runner' => [
    'cron_run' => true,
    'max_messages' => 100,
    'consumers' => ['conversionwax.lastmile.events'],
],
```

Monitor both `conversionwax.lastmile.events` backlog and `conversionwax.lastmile.failed`. Failed
messages remain in the broker for investigation and controlled replay after configuration or
collector problems are corrected.

## Banner widget

The original widget remains available. It accepts a `banner_div` value and loads
`https://assets.conversionwax.com/dist/app.js` on storefront pages. The CSP whitelist permits
ConversionWax script, style, image, and connection hosts.

### Replace mode (body text)

Set the widget option `Mode` to `Replace the original text below` and enter the text that is on the page
now in `Original text HTML`. The widget outputs the placeholder with `data-cwax-mode="replace"` around
that text. The embed hides it (keeping its space) while the content loads, for 1.5 seconds at most, then
swaps in the matching body text variant. If no variant applies, the original text stays. The widget can
only replace text entered in the widget, not other text in the layout. Test: `php tests/test-banner-markup.php`.
