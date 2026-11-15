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
        foreach ($orders as $order) {
            $this->enqueueOrder($order);
        }
    }

    private function enqueueOrder(mixed $order): void
    {
        if (!is_object($order) || !method_exists($order, 'getIncrementId')) {
            return;
        }
        $orderId = (string) $order->getIncrementId();
        if ($orderId === '') {
            return;
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
        $amounts = [
            'grossAmount' => $this->amount($order->getGrandTotal()),
            'subtotalAmount' => $this->amount($order->getSubtotal()),
            // Magento stores the discount as a negative number; the collector wants the magnitude.
            'discountAmount' => $this->amount($order->getDiscountAmount(), true),
            'taxAmount' => $this->amount($order->getTaxAmount()),
            'shippingAmount' => $this->amount($order->getShippingAmount()),
        ];
        foreach ($amounts as $key => $amount) {
            if ($amount !== null) {
                $attributes[$key] = $amount;
            }
        }
        if (method_exists($order, 'getAllVisibleItems')) {
            $items = $order->getAllVisibleItems();
            $firstItem = is_array($items) ? reset($items) : false;
            if (is_object($firstItem) && method_exists($firstItem, 'getSku')) {
                $attributes['productId'] = (string) $firstItem->getSku();
            }
        }

        $this->eventQueue->enqueue('purchase', $attributes);
    }

    /**
     * Format an amount as a collector decimal string, or null when unusable.
     *
     * @param mixed $value
     * @param bool $absolute
     * @return string|null
     */
    private function amount(mixed $value, bool $absolute = false): ?string
    {
        if ($value === null || $value === '' || !is_numeric($value)) {
            return null;
        }
        $number = (float) $value;
        if ($absolute) {
            $number = abs($number);
        }
        $text = number_format($number, 4, '.', '');
        $text = rtrim(rtrim($text, '0'), '.');
        if ($text === '' || $text === '-0') {
            $text = '0';
        }

        return preg_match('/^-?\d{1,12}(?:\.\d{1,9})?$/', $text) === 1 ? $text : null;
    }
}
