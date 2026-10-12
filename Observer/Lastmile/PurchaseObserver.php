<?php

declare(strict_types=1);

namespace Cwax\Banner\Observer\Lastmile;

use Cwax\Banner\Model\Lastmile\EmailHasher;
use Cwax\Banner\Model\Lastmile\EventQueue;
use Magento\Framework\App\State;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Psr\Log\LoggerInterface;

class PurchaseObserver implements ObserverInterface
{
    public function __construct(
        private readonly EventQueue $eventQueue,
        private readonly State $appState,
        private readonly EmailHasher $emailHasher,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(Observer $observer): void
    {
        try {
            $this->executeInternal($observer);
        } catch (\Throwable $exception) {
            // Analytics must never stop order placement.
            $this->logger->error('ConversionWax Last Mile could not build a purchase event.', [
                'exception' => $exception,
            ]);
        }
    }

    private function executeInternal(Observer $observer): void
    {
        try {
            $area = $this->appState->getAreaCode();
        } catch (\Throwable) {
            // Area code is not always set this early (eg CLI/setup context); expected, not an error.
            $this->logger->debug('ConversionWax Last Mile skipped a purchase event: area code not set.');
            return;
        }
        if (!in_array($area, ['frontend', 'webapi_rest', 'graphql'], true)) {
            return;
        }

        $orders = $observer->getEvent()->getOrders();
        if (!is_array($orders) || $orders === []) {
            $orders = [$observer->getEvent()->getOrder()];
        }
        $purchase = null;
        foreach ($orders as $order) {
            $enqueued = $this->enqueueOrder($order);
            if ($enqueued !== null) {
                $purchase = $enqueued;
            }
        }
        if ($purchase !== null) {
            $this->eventQueue->markPurchased($purchase);
        }
    }

    /**
     * @return array{orderId:string,value:float,currency:string}|null
     */
    private function enqueueOrder(mixed $order): ?array
    {
        if (!is_object($order) || !method_exists($order, 'getIncrementId')) {
            return null;
        }
        $orderId = (string) $order->getIncrementId();
        if ($orderId === '') {
            return null;
        }

        $email = method_exists($order, 'getCustomerEmail') ? (string) $order->getCustomerEmail() : '';
        $attributes = [
            'orderId' => $orderId,
            'value' => (float) $order->getGrandTotal(),
            'currency' => strtoupper((string) $order->getOrderCurrencyCode()),
            'customerId' => $order->getCustomerId() !== null
                ? (string) $order->getCustomerId()
                : null,
            'emailHash' => $this->emailHasher->hash($email),
        ];
        if (method_exists($order, 'getAllVisibleItems')) {
            $items = $order->getAllVisibleItems();
            $firstItem = is_array($items) ? reset($items) : false;
            if (is_object($firstItem) && method_exists($firstItem, 'getSku')) {
                $attributes['productId'] = (string) $firstItem->getSku();
            }
        }

        $this->eventQueue->enqueue('purchase', $attributes);
        return [
            'orderId' => $orderId,
            'value' => $attributes['value'],
            'currency' => $attributes['currency'],
        ];
    }
}
