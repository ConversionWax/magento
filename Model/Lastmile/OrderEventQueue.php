<?php

declare(strict_types=1);

namespace Cwax\Banner\Model\Lastmile;

use Cwax\Banner\Model\Config;
use Cwax\Banner\Model\Queue\MessagePublisher;
use Magento\Sales\Api\Data\CreditmemoInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Psr\Log\LoggerInterface;

/**
 * Queues the server order events "refund" and "cancel" for Last Mile. They use the fixed server envelope of
 * conversionwax_lastmile docs/generic-order-events.md: these changes happen in the admin, in REST calls or in
 * cron, never in the shopper's browser session, so there is no visitor, session, device, consent cookie or IP.
 * The event carries no customer data. The same queue consumer delivers them (retries, failed topic).
 *
 * Each event has a stable eventId (refund-<credit memo id>, cancel-<order number>): a queue retry or a
 * second publish of the same change is deduplicated by the collector.
 */
class OrderEventQueue
{
    public const VISITOR_ID = 'server';
    public const SESSION_ID = 'server-order-events';
    private const MAX_EVENT_ID_LENGTH = 128;

    public function __construct(
        private readonly Config $config,
        private readonly MessagePublisher $publisher,
        private readonly OrderNetSales $netSales,
        private readonly LoggerInterface $logger
    ) {
    }

    public function enqueueRefund(CreditmemoInterface $creditmemo, OrderInterface $order): void
    {
        try {
            $creditmemoId = (string) $creditmemo->getEntityId();
            if ($creditmemoId === '') {
                return;
            }
            $event = [
                'eventId' => 'refund-' . $creditmemoId,
                'type' => 'refund',
                'adjustmentId' => (string) $creditmemo->getIncrementId() ?: $creditmemoId,
                'refundAmount' => $this->netSales->refundAmount($creditmemo),
                'netSalesAmount' => $this->netSales->netAfterChange($order),
            ];
            $this->publish($order, $event);
        } catch (\Throwable $exception) {
            // Analytics must never stop a refund.
            $this->logger->error('ConversionWax Last Mile could not queue a refund event.', [
                'exception' => $exception,
            ]);
        }
    }

    public function enqueueCancel(OrderInterface $order): void
    {
        try {
            $event = [
                'eventId' => 'cancel-' . (string) $order->getIncrementId(),
                'type' => 'cancel',
                'cancelledAt' => true,
                'netSalesAmount' => $this->netSales->netAfterChange($order),
            ];
            $this->publish($order, $event);
        } catch (\Throwable $exception) {
            // Analytics must never stop an order cancel.
            $this->logger->error('ConversionWax Last Mile could not queue a cancel event.', [
                'exception' => $exception,
            ]);
        }
    }

    /** @param array<string, mixed> $fields */
    private function publish(OrderInterface $order, array $fields): void
    {
        $orderId = (string) $order->getIncrementId();
        $storeId = $order->getStoreId();
        if ($orderId === '' || $storeId === null || !is_numeric($storeId)) {
            return;
        }
        $storeId = (int) $storeId;
        if (strlen($fields['eventId']) > self::MAX_EVENT_ID_LENGTH || strlen($orderId) > 256) {
            return;
        }
        // Config of the order's store view (website scope), not of the admin store.
        if (!$this->config->isConfigured($storeId)) {
            return;
        }

        $ts = (int) round(microtime(true) * 1000);
        $currency = strtoupper((string) $order->getOrderCurrencyCode());
        $event = [
            'eventId' => $fields['eventId'],
            'type' => $fields['type'],
            'ts' => $ts,
            'orderId' => $orderId,
        ];
        if (isset($fields['adjustmentId'])) {
            $event['adjustmentId'] = substr($fields['adjustmentId'], 0, 256);
        }
        if (isset($fields['refundAmount'])) {
            $event['refundAmount'] = $fields['refundAmount'];
        }
        if (isset($fields['cancelledAt'])) {
            $event['cancelledAt'] = $ts;
        }
        if (isset($fields['netSalesAmount'])) {
            $event['netSalesAmount'] = $fields['netSalesAmount'];
        }
        if (preg_match('/^[A-Z]{3}$/', $currency) === 1) {
            $event['currency'] = $currency;
        }

        $webPropertyId = $this->config->getWebPropertyId($storeId);
        $this->publisher->publishEvent([
            'version' => 1,
            'attempt' => 0,
            'storeViewId' => $storeId,
            'eventId' => $event['eventId'],
            'payload' => [
                // Stable per change, so a retry is the same batch.
                'batchId' => substr(hash('sha256', $webPropertyId . '|' . $event['eventId']), 0, 32),
                'schemaVersion' => 1,
                'sentAt' => $ts,
                'storeId' => $webPropertyId,
                'visitorId' => self::VISITOR_ID,
                'sessionId' => self::SESSION_ID,
                'session' => [
                    'startedAt' => $ts,
                    'lastActivityAt' => $ts,
                    // The consumer makes this the store base URL (StoreUrl::absolute).
                    'entryUrl' => '/',
                    'entryReferrer' => null,
                    // Must be false: a server event must not create an attribution touchpoint.
                    'isNew' => false,
                    'number' => 1,
                    'hadPurchase' => true,
                ],
                'platform' => 'magento',
                'capture' => 'hook',
                'sdk' => ['v' => EventQueue::SDK_VERSION],
                'events' => [$event],
            ],
        ]);
    }
}
