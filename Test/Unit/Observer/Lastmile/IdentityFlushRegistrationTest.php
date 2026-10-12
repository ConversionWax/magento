<?php

declare(strict_types=1);

namespace Cwax\Banner\Test\Unit\Observer\Lastmile;

use Cwax\Banner\Observer\Lastmile\IdentityFlushObserver;
use Cwax\Banner\Observer\Lastmile\PurchaseObserver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The purchase marker is set wherever an order is placed (PurchaseObserver is global), so the identity
 * flush must run in every area that places Luma orders, or the marker is lost with the request.
 */
class IdentityFlushRegistrationTest extends TestCase
{
    /** @return string[] observer classes registered for the event in the given events.xml */
    private function observers(string $relativePath, string $event): array
    {
        $xml = simplexml_load_file(dirname(__DIR__, 4) . '/' . $relativePath);
        $this->assertNotFalse($xml, $relativePath);
        $instances = [];
        foreach ($xml->xpath(sprintf('/config/event[@name="%s"]/observer', $event)) ?: [] as $observer) {
            $instances[] = ltrim((string) $observer['instance'], '\\');
        }

        return $instances;
    }

    public function testPurchaseObserverIsGlobal(): void
    {
        $this->assertContains(PurchaseObserver::class, $this->observers('etc/events.xml', 'checkout_submit_all_after'));
    }

    /** @return array<string, array{0: string}> */
    public static function orderPlacingAreas(): array
    {
        return [
            'storefront controllers' => ['etc/frontend/events.xml'],
            'REST checkout (payment-information)' => ['etc/webapi_rest/events.xml'],
        ];
    }

    #[DataProvider('orderPlacingAreas')]
    public function testIdentityIsFlushedBeforeTheResponseIsSent(string $eventsFile): void
    {
        $this->assertContains(
            IdentityFlushObserver::class,
            $this->observers($eventsFile, 'controller_front_send_response_before')
        );
    }

    public function testTheRestAreaRegistersNoPageViewTracking(): void
    {
        $this->assertSame(
            [IdentityFlushObserver::class],
            $this->observers('etc/webapi_rest/events.xml', 'controller_front_send_response_before')
        );
    }
}
