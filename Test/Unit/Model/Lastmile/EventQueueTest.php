<?php

declare(strict_types=1);

namespace Cwax\Banner\Test\Unit\Model\Lastmile;

use Cwax\Banner\Api\ConsentResolverInterface;
use Cwax\Banner\Api\Data\ConsentDecisionInterface;
use Cwax\Banner\Model\Config;
use Cwax\Banner\Model\Consent\ConsentDecision;
use Cwax\Banner\Model\Lastmile\EventQueue;
use Cwax\Banner\Model\Lastmile\VisitorContext;
use Cwax\Banner\Model\Queue\MessagePublisher;
use Magento\Framework\App\Request\Http;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Webapi\Rest\Request as RestRequest;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class EventQueueTest extends TestCase
{
    private const PURCHASE = ['orderId' => '1', 'value' => 5.0, 'currency' => 'USD'];

    private function queue(bool $configured, ?bool $storage, VisitorContext $context): EventQueue
    {
        $config = $this->createStub(Config::class);
        $config->method('isConfigured')->willReturn($configured);
        $signals = $storage === null ? [] : [ConsentDecisionInterface::SIGNAL_STORAGE => $storage];
        $consent = $this->createStub(ConsentResolverInterface::class);
        $consent->method('resolve')->willReturn(new ConsentDecision($signals));
        $store = $this->createStub(StoreInterface::class);
        $store->method('getId')->willReturn(1);
        $stores = $this->createStub(StoreManagerInterface::class);
        $stores->method('getStore')->willReturn($store);

        return new EventQueue(
            $config,
            $context,
            $this->createStub(MessagePublisher::class),
            $stores,
            $consent,
            $this->createStub(RequestInterface::class),
            $this->createStub(LoggerInterface::class)
        );
    }

    public function testMarkPurchasedDoesNotCreateCookiesWithoutStorageConsent(): void
    {
        $context = $this->createMock(VisitorContext::class);
        $context->expects($this->never())->method('markPurchased');

        $this->queue(true, false, $context)->markPurchased(self::PURCHASE);
    }

    public function testMarkPurchasedDoesNothingWhenConsentHasNoOpinion(): void
    {
        $context = $this->createMock(VisitorContext::class);
        $context->expects($this->never())->method('markPurchased');

        $this->queue(true, null, $context)->markPurchased(self::PURCHASE);
    }

    public function testMarkPurchasedDoesNothingWhenNotConfigured(): void
    {
        $context = $this->createMock(VisitorContext::class);
        $context->expects($this->never())->method('markPurchased');

        $this->queue(false, true, $context)->markPurchased(self::PURCHASE);
    }

    public function testMarkPurchasedForwardsTheDetailsWhenAllowed(): void
    {
        $context = $this->createMock(VisitorContext::class);
        $context->expects($this->once())->method('markPurchased')->with(self::PURCHASE);

        $this->queue(true, true, $context)->markPurchased(self::PURCHASE);
    }

    /**
     * @param array<string, mixed> $attributes
     * @return array<string, mixed> the event that was published
     */
    private function published(string $path, array $attributes = [], ?RequestInterface $request = null): array
    {
        $config = $this->createStub(Config::class);
        $config->method('isConfigured')->willReturn(true);
        $consent = $this->createStub(ConsentResolverInterface::class);
        $consent->method('resolve')->willReturn(new ConsentDecision([
            ConsentDecisionInterface::SIGNAL_STORAGE => true,
            ConsentDecisionInterface::SIGNAL_MEASUREMENT => true,
        ]));
        $store = $this->createStub(StoreInterface::class);
        $store->method('getId')->willReturn(1);
        $stores = $this->createStub(StoreManagerInterface::class);
        $stores->method('getStore')->willReturn($store);
        $context = $this->createStub(VisitorContext::class);
        $context->method('get')->willReturn([
            'visitorId' => 'v',
            'sessionId' => 's',
            'startedAt' => 1,
            'lastActivityAt' => 1,
            'entryUrl' => '/',
            'entryReferrer' => null,
            'isNew' => false,
            'number' => 1,
            'hadPurchase' => false,
            'geo' => null,
        ]);
        $request ??= $this->storefrontRequest($path, $path);
        $message = null;
        $publisher = $this->createStub(MessagePublisher::class);
        $publisher->method('publishEvent')->willReturnCallback(
            static function (array $sent) use (&$message): void {
                $message = $sent;
            }
        );

        (new EventQueue(
            $config,
            $context,
            $publisher,
            $stores,
            $consent,
            $request,
            $this->createStub(LoggerInterface::class)
        ))->enqueue('page_view', $attributes);

        return $message['payload']['events'][0];
    }

    private function storefrontRequest(string $pathInfo, string $originalPathInfo): Http
    {
        $request = $this->createStub(Http::class);
        $request->method('getPathInfo')->willReturn($pathInfo);
        $request->method('getOriginalPathInfo')->willReturn($originalPathInfo);
        $request->method('getServer')->willReturn(null);
        return $request;
    }

    public function testAProductPageReportsItsPublicUrlNotTheRewriteTarget(): void
    {
        // The URL rewrite router replaces the path info with the internal route.
        $event = $this->published('', [], $this->storefrontRequest('/catalog/product/view/id/27', '/blue-shirt.html'));

        $this->assertSame('/blue-shirt.html', $event['path']);
    }

    public function testAPageWithoutARewriteKeepsItsPath(): void
    {
        $event = $this->published('', [], $this->storefrontRequest('/checkout/cart/', '/checkout/cart/'));

        $this->assertSame('/checkout/cart/', $event['path']);
    }

    public function testARestRequestStillMasksTheGuestCartId(): void
    {
        $request = $this->createStub(RestRequest::class);
        $request->method('getPathInfo')->willReturn('/rest/default/V1/guest-carts/rIX3Jabc123/items');
        $request->method('getServer')->willReturn(null);

        $event = $this->published('', [], $request);

        $this->assertSame('/rest/default/V1/guest-carts/-/items', $event['path']);
    }

    public function testAGuestCartIdNeverLeavesTheStoreInAnEventPath(): void
    {
        $event = $this->published('/rest/default/V1/guest-carts/rIX3Jabc123/items');

        $this->assertSame('/rest/default/V1/guest-carts/-/items', $event['path']);
    }

    public function testAnExplicitPathReplacesTheRequestPath(): void
    {
        $event = $this->published('/customer/section/load/', ['path' => '/sale']);

        $this->assertSame('/sale', $event['path']);
    }
}
