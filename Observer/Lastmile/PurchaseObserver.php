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
    private const MAX_LINE_ITEMS = 500;
    private const MAX_DISCOUNT_CODES = 50;

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

        $lineItems = $this->lineItems($order);
        if ($lineItems !== null) {
            $attributes['lineItems'] = $lineItems;
        }
        $discountCodes = $this->discountCodes($order);
        if ($discountCodes !== null) {
            $attributes['discountCodes'] = $discountCodes;
        }

        $this->eventQueue->enqueue('purchase', $attributes);
    }

    /**
     * CWTASKS-128: one line per visible order item, product and price fields only (no customer
     * data, no custom options). Amounts in the order currency, like the order amounts. A line that
     * cannot be read drops the whole list: a partial list would not add up to the order subtotal.
     *
     * @param mixed $order
     * @return list<array<string, string|int>>|null
     */
    private function lineItems(mixed $order): ?array
    {
        if (!method_exists($order, 'getAllVisibleItems')) {
            return null;
        }
        $items = $order->getAllVisibleItems();
        if (!is_array($items) || $items === [] || count($items) > self::MAX_LINE_ITEMS) {
            return null;
        }
        $lines = [];
        foreach ($items as $item) {
            if (!is_object($item)) {
                return null;
            }
            $lineId = method_exists($item, 'getItemId') ? (string) $item->getItemId() : '';
            if ($lineId === '' && method_exists($item, 'getQuoteItemId')) {
                $lineId = (string) $item->getQuoteItemId();
            }
            $qty = method_exists($item, 'getQtyOrdered') ? $item->getQtyOrdered() : null;
            $unitPrice = method_exists($item, 'getPrice') ? $this->amount($item->getPrice()) : null;
            if ($lineId === '' || !is_numeric($qty) || (float) $qty < 0 || floor((float) $qty) != (float) $qty
                || (float) $qty > 1000000 || $unitPrice === null || isset($lines[$lineId])) {
                return null;
            }
            $line = [
                'lineId' => substr($lineId, 0, 256),
                'quantity' => (int) $qty,
                'unitPrice' => $unitPrice,
            ];
            $optional = [
                'productId' => method_exists($item, 'getProductId') ? $item->getProductId() : null,
                'variantId' => $this->variantId($item),
                'sku' => method_exists($item, 'getSku') ? $item->getSku() : null,
                'title' => method_exists($item, 'getName') ? $item->getName() : null,
            ];
            foreach ($optional as $key => $value) {
                if ($value !== null && (string) $value !== '') {
                    $line[$key] = mb_substr((string) $value, 0, $key === 'title' ? 512 : 256);
                }
            }
            $discount = method_exists($item, 'getDiscountAmount')
                ? $this->amount($item->getDiscountAmount(), true)
                : null;
            if ($discount !== null) {
                $line['lineDiscount'] = $discount;
            }
            $lines[$lineId] = $line;
        }

        return array_values($lines);
    }

    /**
     * The simple product chosen for a configurable item (its child item), else none.
     *
     * @param object $item
     * @return string|null
     */
    private function variantId(object $item): ?string
    {
        if (!method_exists($item, 'getChildrenItems') || !method_exists($item, 'getProductType')
            || $item->getProductType() !== 'configurable') {
            return null;
        }
        $children = $item->getChildrenItems();
        $child = is_array($children) ? reset($children) : false;

        return is_object($child) && method_exists($child, 'getProductId') && $child->getProductId() !== null
            ? (string) $child->getProductId()
            : null;
    }

    /**
     * The order coupon code(s). Adobe Commerce can hold several codes separated by commas. The
     * amount is sent only for a single code, because Magento does not split it by code.
     *
     * @param mixed $order
     * @return list<array<string, string>>|null
     */
    private function discountCodes(mixed $order): ?array
    {
        if (!method_exists($order, 'getCouponCode')) {
            return null;
        }
        $raw = (string) $order->getCouponCode();
        $codes = array_values(array_unique(array_filter(array_map('trim', explode(',', $raw)), 'strlen')));
        if ($codes === []) {
            return null;
        }
        $codes = array_slice($codes, 0, self::MAX_DISCOUNT_CODES);
        $amount = count($codes) === 1 && method_exists($order, 'getDiscountAmount')
            ? $this->amount($order->getDiscountAmount(), true)
            : null;

        return array_map(static function (string $code) use ($amount): array {
            $entry = ['code' => mb_substr($code, 0, 256)];
            if ($amount !== null) {
                $entry['amount'] = $amount;
            }
            return $entry;
        }, $codes);
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
