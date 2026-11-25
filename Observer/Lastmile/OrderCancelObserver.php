<?php

declare(strict_types=1);

namespace Cwax\Banner\Observer\Lastmile;

use Cwax\Banner\Model\Lastmile\OrderEventQueue;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Psr\Log\LoggerInterface;

/**
 * order_cancel_after (Order::cancel: admin Cancel, REST orders/:id/cancel, pending order cleanup cron).
 * Magento has already set the canceled totals, so the event carries the net after the cancel.
 */
class OrderCancelObserver implements ObserverInterface
{
    public function __construct(
        private readonly OrderEventQueue $queue,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(Observer $observer): void
    {
        try {
            $order = $observer->getEvent()->getOrder();
            if ($order instanceof OrderInterface) {
                $this->queue->enqueueCancel($order);
            }
        } catch (\Throwable $exception) {
            // Analytics must never stop an order cancel.
            $this->logger->error('ConversionWax Last Mile could not build a cancel event.', [
                'exception' => $exception,
            ]);
        }
    }
}
