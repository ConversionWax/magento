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
use Magento\Framework\App\RequestInterface;
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
}
