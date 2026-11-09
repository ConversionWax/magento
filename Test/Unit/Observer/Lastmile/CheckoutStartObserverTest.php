<?php

declare(strict_types=1);

namespace Cwax\Banner\Test\Unit\Observer\Lastmile;

use Cwax\Banner\Model\Lastmile\EventQueue;
use Cwax\Banner\Model\Lastmile\VisitorContext;
use Cwax\Banner\Observer\Lastmile\CheckoutStartObserver;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\Event\Observer;
use Magento\Quote\Model\Quote;
use PHPUnit\Framework\TestCase;

class CheckoutStartObserverTest extends TestCase
{
    private function session(int $items, bool $fired = false): CheckoutSession
    {
        $quote = $this->createStub(Quote::class);
        $quote->method('getItemsCount')->willReturn($items);
        $session = $this->createStub(CheckoutSession::class);
        $session->method('getQuote')->willReturn($quote);
        $session->method('getData')->willReturn($fired);

        return $session;
    }

    public function testTheResponseIsDeclaredUncacheableBeforeTheEventIsQueued(): void
    {
        $calls = [];
        // PHPUnit does not run callbacks configured on void methods, so a small spy records the order.
        $context = new class ($calls) extends VisitorContext {
            /** @param string[] $calls */
            public function __construct(private array &$calls)
            {
            }

            public function declareUncacheable(): void
            {
                $this->calls[] = 'declareUncacheable';
            }
        };
        $queue = $this->createMock(EventQueue::class);
        $queue->expects($this->once())->method('enqueue')->with('checkout_start')
            ->willReturnCallback(static function () use (&$calls): void {
                $calls[] = 'enqueue';
            });

        (new CheckoutStartObserver($queue, $this->session(2), $context))->execute(new Observer());

        $this->assertSame(['declareUncacheable', 'enqueue'], $calls);
    }

    public function testAnEmptyCartQueuesNothing(): void
    {
        $context = $this->createMock(VisitorContext::class);
        $context->expects($this->never())->method('declareUncacheable');
        $queue = $this->createMock(EventQueue::class);
        $queue->expects($this->never())->method('enqueue');

        (new CheckoutStartObserver($queue, $this->session(0), $context))->execute(new Observer());
    }

    public function testAReloadQueuesNothing(): void
    {
        $queue = $this->createMock(EventQueue::class);
        $queue->expects($this->never())->method('enqueue');

        (new CheckoutStartObserver($queue, $this->session(2, true), $this->createStub(VisitorContext::class)))
            ->execute(new Observer());
    }
}
