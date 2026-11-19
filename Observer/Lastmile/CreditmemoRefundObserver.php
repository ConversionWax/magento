<?php

declare(strict_types=1);

namespace Cwax\Banner\Observer\Lastmile;

use Cwax\Banner\Model\Lastmile\RefundMarker;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Psr\Log\LoggerInterface;

/**
 * sales_order_creditmemo_refund (RefundOperation, every refund path: admin, REST refund order or invoice,
 * full or partial). Only marks the credit memo; CreditmemoSaveObserver sends the event once it has an id.
 */
class CreditmemoRefundObserver implements ObserverInterface
{
    public function __construct(
        private readonly RefundMarker $marker,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(Observer $observer): void
    {
        try {
            $creditmemo = $observer->getEvent()->getCreditmemo();
            if (is_object($creditmemo)) {
                $this->marker->mark($creditmemo);
            }
        } catch (\Throwable $exception) {
            // Analytics must never stop a refund.
            $this->logger->error('ConversionWax Last Mile could not mark a refund.', [
                'exception' => $exception,
            ]);
        }
    }
}
