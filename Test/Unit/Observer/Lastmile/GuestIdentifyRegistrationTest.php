<?php

declare(strict_types=1);

namespace Cwax\Banner\Test\Unit\Observer\Lastmile;

use PHPUnit\Framework\TestCase;

/**
 * Guest checkout email and newsletter signup identify only in storefront areas. In the admin (an order
 * created for a customer, a subscriber edited) or in cron, the request has no visitor of its own, so
 * an identify there would join the email to the wrong visitor.
 */
class GuestIdentifyRegistrationTest extends TestCase
{
    private const QUOTE = 'Cwax\Banner\Observer\Lastmile\GuestQuoteEmailObserver';
    private const NEWSLETTER = 'Cwax\Banner\Observer\Lastmile\NewsletterSubscriberObserver';

    private function root(): string
    {
        return dirname(__DIR__, 4);
    }

    /** @return array<int, string> "event observer-class" pairs in one events.xml file */
    private function observers(string $file): array
    {
        $path = $this->root() . '/' . $file;
        if (!is_file($path)) {
            return [];
        }
        $xml = simplexml_load_file($path);
        $this->assertNotFalse($xml, $file);
        $found = [];
        foreach ($xml->xpath('/config/event/observer') ?: [] as $observer) {
            $event = $observer->xpath('..')[0];
            $found[] = $event['name'] . ' ' . ltrim((string) $observer['instance'], '\\');
        }

        return $found;
    }

    public function testGuestQuoteEmailIsObservedOnTheStorefrontAndInRest(): void
    {
        $this->assertContains('sales_quote_save_after ' . self::QUOTE, $this->observers('etc/frontend/events.xml'));
        $this->assertContains('sales_quote_save_after ' . self::QUOTE, $this->observers('etc/webapi_rest/events.xml'));
    }

    public function testNewsletterSignupIsObservedOnTheStorefront(): void
    {
        $this->assertContains(
            'newsletter_subscriber_save_after ' . self::NEWSLETTER,
            $this->observers('etc/frontend/events.xml')
        );
    }

    public function testNeitherIsGlobalOrInTheAdmin(): void
    {
        foreach (['etc/events.xml', 'etc/adminhtml/events.xml', 'etc/crontab/events.xml'] as $file) {
            foreach ($this->observers($file) as $registration) {
                $this->assertStringNotContainsString(self::QUOTE, $registration, $file);
                $this->assertStringNotContainsString(self::NEWSLETTER, $registration, $file);
            }
        }
    }

    public function testLumaGuestPaymentEmailPluginIsRegisteredForRest(): void
    {
        $xml = simplexml_load_file($this->root() . '/etc/webapi_rest/di.xml');
        $this->assertNotFalse($xml);
        $plugins = $xml->xpath(
            '/config/type[@name="Magento\Checkout\Api\GuestPaymentInformationManagementInterface"]/plugin'
        ) ?: [];
        $types = array_map(static fn ($plugin): string => ltrim((string) $plugin['type'], '\\'), $plugins);
        $this->assertContains('Cwax\Banner\Plugin\Lastmile\GuestPaymentEmailPlugin', $types);
    }

    public function testTheRecorderGetsALazyCheckoutSession(): void
    {
        // A quote save can happen while the checkout session itself is loading the quote.
        $xml = simplexml_load_file($this->root() . '/etc/di.xml');
        $this->assertNotFalse($xml);
        $arguments = $xml->xpath(
            '/config/type[@name="Cwax\Banner\Model\Lastmile\IdentifyRecorder"]/arguments'
            . '/argument[@name="checkoutSession"]'
        ) ?: [];
        $this->assertCount(1, $arguments);
        $this->assertSame('Magento\Checkout\Model\Session\Proxy', trim((string) $arguments[0]));
    }

    public function testTheNewsletterModuleIsDeclared(): void
    {
        $module = (string) file_get_contents($this->root() . '/etc/module.xml');
        $this->assertStringContainsString('<module name="Magento_Newsletter"/>', $module);
        $composer = json_decode((string) file_get_contents($this->root() . '/composer.json'), true);
        $this->assertArrayHasKey('magento/module-newsletter', $composer['require']);
    }
}
