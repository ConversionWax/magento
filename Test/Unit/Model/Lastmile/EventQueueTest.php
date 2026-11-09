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

    public function testNoIdentityIsResolvedWithoutStorageConsent(): void
    {
        $context = $this->createMock(VisitorContext::class);
        // get() may mint cookies, so it must not even run.
        $context->expects($this->never())->method('get');

        $this->queue(true, false, $context)->enqueue('add_to_cart', ['productId' => 'SKU']);
    }

    public function testNoIdentityIsResolvedWhenConsentHasNoOpinion(): void
    {
        $context = $this->createMock(VisitorContext::class);
        $context->expects($this->never())->method('get');

        $this->queue(true, null, $context)->enqueue('add_to_cart', ['productId' => 'SKU']);
    }

    public function testNoIdentityIsResolvedWhenNotConfigured(): void
    {
        $context = $this->createMock(VisitorContext::class);
        $context->expects($this->never())->method('get');

        $this->queue(false, true, $context)->enqueue('add_to_cart', ['productId' => 'SKU']);
    }

    public function testIdentityIsResolvedWithStorageConsent(): void
    {
        $context = $this->createMock(VisitorContext::class);
        $context->expects($this->once())->method('get')->willReturn(null);

        $this->queue(true, true, $context)->enqueue('add_to_cart', ['productId' => 'SKU']);
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
        ))->enqueue('add_to_cart', $attributes);

        return $message['payload']['events'][0];
    }

    /**
     * @param array<string, string> $headers
     */
    private function storefrontRequest(string $pathInfo, string $originalPathInfo, array $headers = []): Http
    {
        $request = $this->createStub(Http::class);
        $request->method('getPathInfo')->willReturn($pathInfo);
        $request->method('getOriginalPathInfo')->willReturn($originalPathInfo);
        $request->method('getServer')->willReturn(null);
        $request->method('getHeader')->willReturnCallback(
            static fn (string $name): string|false => $headers[$name] ?? false
        );
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
        $event = $this->published('/checkout/cart/add/', ['path' => '/sale']);

        $this->assertSame('/sale', $event['path']);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function speculativeRequests(): array
    {
        return [
            'speculation rules prefetch' => ['Sec-Purpose', 'prefetch'],
            'speculation rules prerender' => ['Sec-Purpose', 'prefetch;prerender'],
            'legacy link prefetch' => ['Purpose', 'prefetch'],
            'Safari preview' => ['X-Purpose', 'preview'],
            'Firefox prefetch' => ['X-Moz', 'prefetch'],
        ];
    }

    /**
     * @dataProvider speculativeRequests
     */
    public function testASpeculativeLoadIsNotAVisit(string $header, string $value): void
    {
        $request = $this->storefrontRequest('/catalog/product/view/id/27', '/blue-shirt.html', [$header => $value]);
        $context = $this->createMock(VisitorContext::class);
        // No visitor cookie may be minted or extended by a page the shopper has not opened.
        $context->expects($this->never())->method('get');
        $publisher = $this->createMock(MessagePublisher::class);
        $publisher->expects($this->never())->method('publishEvent');

        $this->queueFor($request, $context, $publisher)->enqueue('checkout_start');
    }

    public function testAnUnrelatedPurposeHeaderStillCounts(): void
    {
        $event = $this->published('', [], $this->storefrontRequest('/sale', '/sale', ['Sec-Purpose' => 'other']));

        $this->assertSame('/sale', $event['path']);
    }

    private function queueFor(
        RequestInterface $request,
        VisitorContext $context,
        MessagePublisher $publisher
    ): EventQueue {
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

        return new EventQueue(
            $config,
            $context,
            $publisher,
            $stores,
            $consent,
            $request,
            $this->createStub(LoggerInterface::class)
        );
    }
}
