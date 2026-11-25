<?php

declare(strict_types=1);

namespace Cwax\Banner\Observer\Lastmile;

use Cwax\Banner\Model\Lastmile\OrderEventQueue;
use Cwax\Banner\Model\Lastmile\RefundMarker;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Sales\Api\Data\CreditmemoInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Psr\Log\LoggerInterface;

/**
 * sales_order_creditmemo_save_after: queues the refund event for a credit memo that
 * CreditmemoRefundObserver marked. This save runs inside the refund transaction, and the DB queue uses the
 * same connection, so a refund that rolls back leaves no queued event.
 */
class CreditmemoSaveObserver implements ObserverInterface
{
    public function __construct(
        private readonly OrderEventQueue $queue,
        private readonly RefundMarker $marker,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(Observer $observer): void
    {
        try {
            $creditmemo = $observer->getEvent()->getCreditmemo();
            if (!$creditmemo instanceof CreditmemoInterface || !$this->marker->take($creditmemo)) {
                return;
            }
            $order = method_exists($creditmemo, 'getOrder') ? $creditmemo->getOrder() : null;
            if (!$order instanceof OrderInterface) {
                return;
            }
            $this->queue->enqueueRefund($creditmemo, $order);
        } catch (\Throwable $exception) {
            // Analytics must never stop a refund.
            $this->logger->error('ConversionWax Last Mile could not build a refund event.', [
                'exception' => $exception,
            ]);
        }
    }
}
