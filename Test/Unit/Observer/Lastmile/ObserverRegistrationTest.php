<?php

declare(strict_types=1);

namespace Cwax\Banner\Test\Unit\Observer\Lastmile;

use PHPUnit\Framework\TestCase;

/**
 * page_view and product_view come only from lm.js in the browser, which runs even when the page came from
 * a cache. A server observer for either would count an uncached view twice.
 */
class ObserverRegistrationTest extends TestCase
{
    /** @return array<int, string> every "events-file event observer-class" registration in the module */
    private function registrations(): array
    {
        $root = dirname(__DIR__, 4);
        $found = [];
        foreach (glob($root . '/etc{,/*}/events.xml', GLOB_BRACE) ?: [] as $file) {
            $xml = simplexml_load_file($file);
            $this->assertNotFalse($xml, $file);
            foreach ($xml->xpath('/config/event/observer') ?: [] as $observer) {
                $event = $observer->xpath('..')[0];
                $found[] = substr($file, strlen($root) + 1) . ' ' . $event['name'] . ' '
                    . ltrim((string) $observer['instance'], '\\');
            }
        }

        return $found;
    }

    public function testNoServerObserverReportsPageOrProductViews(): void
    {
        $registrations = $this->registrations();
        $this->assertNotEmpty($registrations);
        foreach ($registrations as $registration) {
            $this->assertDoesNotMatchRegularExpression('/PageView|ProductView|catalog_controller_product_view/', $registration);
        }
    }
}
