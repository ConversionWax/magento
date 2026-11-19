<?php

declare(strict_types=1);

namespace Cwax\Banner\Test\Unit\Observer\Lastmile;

use Cwax\Banner\Model\Lastmile\OrderEventQueue;
use Cwax\Banner\Model\Lastmile\RefundMarker;
use Cwax\Banner\Observer\Lastmile\CreditmemoRefundObserver;
use Cwax\Banner\Observer\Lastmile\CreditmemoSaveObserver;
use Cwax\Banner\Observer\Lastmile\OrderCancelObserver;
use Magento\Framework\Event;
use Magento\Framework\Event\Observer;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Creditmemo;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * A refund is reported once, when its credit memo is first saved (it then has an id). The marker set by
 * sales_order_creditmemo_refund separates that save from later saves of the same credit memo (comments,
 * email flags), which must not send the refund again.
 */
class RefundCancelObserverTest extends TestCase
{
    public function testRefundIsSentOnTheSaveAfterTheRefund(): void
    {
        $order = $this->createMock(Order::class);
        $creditmemo = $this->createMock(Creditmemo::class);
        $creditmemo->method('getOrder')->willReturn($order);

        $queue = $this->createMock(OrderEventQueue::class);
        $queue->expects($this->once())->method('enqueueRefund')->with($creditmemo, $order);

        $marker = new RefundMarker();
        $this->refundObserver($marker)->execute($this->creditmemoEvent($creditmemo));
        $save = $this->saveObserver($queue, $marker);
        $save->execute($this->creditmemoEvent($creditmemo));
        // A later save of the same credit memo sends nothing.
        $save->execute($this->creditmemoEvent($creditmemo));
    }

    public function testSaveWithoutRefundSendsNothing(): void
    {
        $creditmemo = $this->createMock(Creditmemo::class);
        $queue = $this->createMock(OrderEventQueue::class);
        $queue->expects($this->never())->method('enqueueRefund');

        $this->saveObserver($queue, new RefundMarker())->execute($this->creditmemoEvent($creditmemo));
    }

    public function testTwoCreditMemosOfOneOrderAreSentSeparately(): void
    {
        $order = $this->createMock(Order::class);
        $first = $this->createMock(Creditmemo::class);
        $first->method('getOrder')->willReturn($order);
        $second = $this->createMock(Creditmemo::class);
        $second->method('getOrder')->willReturn($order);

        $queue = $this->createMock(OrderEventQueue::class);
        $queue->expects($this->exactly(2))->method('enqueueRefund');

        $marker = new RefundMarker();
        $refund = $this->refundObserver($marker);
        $save = $this->saveObserver($queue, $marker);
        $refund->execute($this->creditmemoEvent($first));
        $save->execute($this->creditmemoEvent($first));
        $refund->execute($this->creditmemoEvent($second));
        $save->execute($this->creditmemoEvent($second));
    }

    public function testRefundFailureNeverStopsTheCreditMemoSave(): void
    {
        $creditmemo = $this->createMock(Creditmemo::class);
        $creditmemo->method('getOrder')->willThrowException(new \RuntimeException('no order'));
        $queue = $this->createMock(OrderEventQueue::class);
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error');

        $marker = new RefundMarker();
        $this->refundObserver($marker)->execute($this->creditmemoEvent($creditmemo));
        (new CreditmemoSaveObserver($queue, $marker, $logger))->execute($this->creditmemoEvent($creditmemo));
    }

    public function testMissingCreditMemoIsIgnored(): void
    {
        $queue = $this->createMock(OrderEventQueue::class);
        $queue->expects($this->never())->method('enqueueRefund');
        $event = $this->getMockBuilder(Event::class)->addMethods(['getCreditmemo'])->getMock();
        $event->method('getCreditmemo')->willReturn(null);

        $marker = new RefundMarker();
        $this->refundObserver($marker)->execute(new Observer(['event' => $event]));
        $this->saveObserver($queue, $marker)->execute(new Observer(['event' => $event]));
    }

    public function testCancelIsSent(): void
    {
        $order = $this->createMock(Order::class);
        $queue = $this->createMock(OrderEventQueue::class);
        $queue->expects($this->once())->method('enqueueCancel')->with($order);

        (new OrderCancelObserver($queue, $this->createMock(LoggerInterface::class)))
            ->execute($this->orderEvent($order));
    }

    public function testCancelFailureNeverStopsTheCancel(): void
    {
        $order = $this->createMock(Order::class);
        $queue = $this->createMock(OrderEventQueue::class);
        $queue->method('enqueueCancel')->willThrowException(new \RuntimeException('broken'));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error');

        (new OrderCancelObserver($queue, $logger))->execute($this->orderEvent($order));
    }

    public function testCancelWithoutOrderIsIgnored(): void
    {
        $queue = $this->createMock(OrderEventQueue::class);
        $queue->expects($this->never())->method('enqueueCancel');

        (new OrderCancelObserver($queue, $this->createMock(LoggerInterface::class)))
            ->execute($this->orderEvent(null));
    }

    private function refundObserver(RefundMarker $marker): CreditmemoRefundObserver
    {
        return new CreditmemoRefundObserver($marker, $this->createMock(LoggerInterface::class));
    }

    private function saveObserver(OrderEventQueue $queue, RefundMarker $marker): CreditmemoSaveObserver
    {
        return new CreditmemoSaveObserver($queue, $marker, $this->createMock(LoggerInterface::class));
    }

    private function creditmemoEvent(?Creditmemo $creditmemo): Observer
    {
        $event = $this->getMockBuilder(Event::class)->addMethods(['getCreditmemo'])->getMock();
        $event->method('getCreditmemo')->willReturn($creditmemo);

        return new Observer(['event' => $event]);
    }

    private function orderEvent(?Order $order): Observer
    {
        $event = $this->getMockBuilder(Event::class)->addMethods(['getOrder'])->getMock();
        $event->method('getOrder')->willReturn($order);

        return new Observer(['event' => $event]);
    }
}
