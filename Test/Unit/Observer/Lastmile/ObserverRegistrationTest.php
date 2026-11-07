<?php

declare(strict_types=1);

namespace Cwax\Banner\Test\Unit\Observer\Lastmile;

use Cwax\Banner\Observer\Lastmile\PageViewObserver;
use Cwax\Banner\Observer\Lastmile\ProductViewObserver;
use PHPUnit\Framework\TestCase;

/**
 * A product page sends one product_view and one page_view. Registering either observer in a second
 * area or on a second event would send it twice for the same request.
 */
class ObserverRegistrationTest extends TestCase
{
    /** @return array<int, string> "events-file event" for every registration of the observer */
    private function registrations(string $observerClass): array
    {
        $root = dirname(__DIR__, 4);
        $found = [];
        foreach (glob($root . '/etc{,/*}/events.xml', GLOB_BRACE) ?: [] as $file) {
            $xml = simplexml_load_file($file);
            $this->assertNotFalse($xml, $file);
            foreach ($xml->xpath('/config/event/observer') ?: [] as $observer) {
                if (ltrim((string) $observer['instance'], '\\') === $observerClass) {
                    $event = $observer->xpath('..')[0];
                    $found[] = substr($file, strlen($root) + 1) . ' ' . $event['name'];
                }
            }
        }

        return $found;
    }

    public function testPageViewIsRegisteredOnceForStorefrontResponses(): void
    {
        $this->assertSame(
            ['etc/frontend/events.xml controller_front_send_response_before'],
            $this->registrations(PageViewObserver::class)
        );
    }

    public function testProductViewIsRegisteredOnceForTheProductController(): void
    {
        $this->assertSame(
            ['etc/frontend/events.xml catalog_controller_product_view'],
            $this->registrations(ProductViewObserver::class)
        );
    }
}
