<?php

declare(strict_types=1);

namespace Cwax\Banner\Test\Unit\Plugin\Lastmile;

use Cwax\Banner\Model\Lastmile\EventQueue;
use Cwax\Banner\Model\Lastmile\VisitorContext;
use Cwax\Banner\Plugin\Lastmile\CheckoutStartPlugin;
use Magento\Checkout\Controller\Index\Index;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\View\Result\Page;
use Magento\Quote\Model\Quote;
use PHPUnit\Framework\TestCase;

class CheckoutStartPluginTest extends TestCase
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

    public function testRecordsWhenPageRendered(): void
    {
        $queue = $this->createMock(EventQueue::class);
        $queue->expects($this->once())->method('enqueue')->with('checkout_start');
        $context = $this->createMock(VisitorContext::class);
        $context->expects($this->once())->method('declareUncacheable');

        (new CheckoutStartPlugin($queue, $this->session(2), $context))
            ->afterExecute($this->createStub(Index::class), $this->createStub(Page::class));
    }

    public function testSkipsOnRedirect(): void
    {
        $queue = $this->createMock(EventQueue::class);
        $queue->expects($this->never())->method('enqueue');

        (new CheckoutStartPlugin($queue, $this->session(2), $this->createStub(VisitorContext::class)))
            ->afterExecute($this->createStub(Index::class), $this->createStub(Redirect::class));
    }

    public function testSkipsEmptyQuote(): void
    {
        $queue = $this->createMock(EventQueue::class);
        $queue->expects($this->never())->method('enqueue');

        (new CheckoutStartPlugin($queue, $this->session(0), $this->createStub(VisitorContext::class)))
            ->afterExecute($this->createStub(Index::class), $this->createStub(Page::class));
    }

    public function testOncePerSession(): void
    {
        $queue = $this->createMock(EventQueue::class);
        $queue->expects($this->never())->method('enqueue');

        (new CheckoutStartPlugin($queue, $this->session(2, true), $this->createStub(VisitorContext::class)))
            ->afterExecute($this->createStub(Index::class), $this->createStub(Page::class));
    }

    public function testReturnsResultUnchanged(): void
    {
        $result = $this->createStub(Page::class);
        $plugin = new CheckoutStartPlugin(
            $this->createStub(EventQueue::class),
            $this->session(2),
            $this->createStub(VisitorContext::class)
        );

        $this->assertSame($result, $plugin->afterExecute($this->createStub(Index::class), $result));
    }

    public function testRedirectResultIsReturnedUnchanged(): void
    {
        $result = $this->createStub(Redirect::class);
        $plugin = new CheckoutStartPlugin(
            $this->createStub(EventQueue::class),
            $this->session(2),
            $this->createStub(VisitorContext::class)
        );

        $this->assertSame($result, $plugin->afterExecute($this->createStub(Index::class), $result));
    }
}
