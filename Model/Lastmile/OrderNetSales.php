<?php

declare(strict_types=1);

namespace Cwax\Banner\Model\Lastmile;

use Magento\Sales\Api\Data\CreditmemoInterface;
use Magento\Sales\Api\Data\OrderInterface;

/**
 * Net sales of a Magento order for Last Mile: products after discounts, refunds and cancels, before tax and
 * shipping. Credit memo adjustments (adjustment refund, adjustment fee) are not products and are left out,
 * the same as Shopify current_subtotal_price. Amounts are in the order currency, like the purchase event.
 * Magento stores discount_amount and discount_refunded as negative numbers and discount_canceled as a
 * positive number, so every discount is read as its magnitude.
 */
class OrderNetSales
{
    /** Same cap as the purchase lineItems and the collector (MAX_ORDER_LINE_ITEMS). */
    public const MAX_REFUND_LINES = 500;
    /** Child items count too, so a credit memo of 500 lines can have more items. */
    private const MAX_CREDITMEMO_ITEMS = 5000;
    private const MAX_QUANTITY = 1000000;

    /**
     * Net after the latest change, read from the order totals that Magento has already updated for that
     * change (RefundOperation before sales_order_creditmemo_refund, registerCancellation before
     * order_cancel_after). Null when the order has no subtotal.
     */
    public function netAfterChange(OrderInterface $order): ?string
    {
        $subtotal = $this->number($order->getSubtotal());
        if ($subtotal === null) {
            return null;
        }
        $products = $subtotal
            - ($this->number($order->getSubtotalCanceled()) ?? 0.0)
            - ($this->number($order->getSubtotalRefunded()) ?? 0.0);
        $discount = $this->abs($order->getDiscountAmount());
        $compensation = $this->abs($order->getDiscountTaxCompensationAmount());

        // Only the discount not yet invoiced can be canceled. Magento 2.4.6 stores discount_canceled as
        // abs(discount_amount) - discount_invoiced with a negative discount_invoiced, which is too large
        // on a partly invoiced order; 2.4.8 stores abs(discount_amount) - abs(discount_invoiced).
        // The bound gives the same result for both forms.
        $canceled = min(
            $this->abs($order->getDiscountCanceled()),
            max(0.0, $discount - $this->abs($order->getDiscountInvoiced()))
        );
        $canceledCompensation = $canceled > 0.0
            ? min($canceled, max(0.0, $compensation - $this->abs($order->getDiscountTaxCompensationInvoiced())))
            : 0.0;
        $refunded = max(
            0.0,
            $this->abs($order->getDiscountRefunded()) - $this->abs($order->getDiscountTaxCompensationRefunded())
        );

        // With "Apply Discount On Prices: Including Tax" the discount includes tax; the tax part is the
        // discount tax compensation. The subtotal excludes tax, so the discount is taken before tax too.
        $remaining = ($discount - $compensation) - ($canceled - $canceledCompensation) - $refunded;

        return $this->format(max(0.0, $products - max(0.0, $remaining)));
    }

    /** Products minus discount of one credit memo; no tax, shipping or adjustments. */
    public function refundAmount(CreditmemoInterface $creditmemo): ?string
    {
        $subtotal = $this->number($creditmemo->getSubtotal());
        if ($subtotal === null) {
            return null;
        }
        $discount = max(
            0.0,
            $this->abs($creditmemo->getDiscountAmount()) - $this->abs($creditmemo->getDiscountTaxCompensationAmount())
        );

        return $this->format(max(0.0, $subtotal - $discount));
    }

    /**
     * CWTASKS-128: the refunded lines of one credit memo, for the collector field refundLines. lineId is the
     * order item id of the visible line, the same lineId as the purchase event; a child item (configurable
     * simple, bundle option) is added to its parent line, so each line is sent once. quantity is the
     * refunded quantity of the visible line. amount is the row total minus the discount, before tax, in the
     * order currency, read from the items that Magento counts in the credit memo totals (not the dummy
     * items), so the amounts add up to refundAmount. Lines with no quantity and no amount are not refunded
     * and are left out. Null when the credit memo has no refunded line or any item cannot be read: a partial
     * list would not add up to refundAmount.
     *
     * @return list<array{lineId: string, quantity: int, amount: string}>|null
     */
    public function refundLines(CreditmemoInterface $creditmemo, ?OrderInterface $order = null): ?array
    {
        $items = $creditmemo->getItems();
        if (!is_array($items) || $items === [] || count($items) > self::MAX_CREDITMEMO_ITEMS) {
            return null;
        }
        /** @var array<string, array{quantity: float|null, amount: float}> $lines */
        $lines = [];
        foreach ($items as $item) {
            if (!is_object($item) || !method_exists($item, 'getOrderItemId')) {
                return null;
            }
            $orderItemId = (string) $item->getOrderItemId();
            $orderItem = $orderItemId === '' ? null : $this->orderItem($item, $orderItemId, $order);
            if ($orderItem === null) {
                return null;
            }
            $parentId = method_exists($orderItem, 'getParentItemId') ? (string) $orderItem->getParentItemId() : '';
            $lineId = $parentId !== '' ? $parentId : $orderItemId;
            $lines[$lineId] ??= ['quantity' => null, 'amount' => 0.0];

            $qty = $this->number($item->getQty());
            if ($qty === null || $qty < 0) {
                return null;
            }
            if ($lineId === $orderItemId) {
                if ($lines[$lineId]['quantity'] !== null) {
                    return null;
                }
                $lines[$lineId]['quantity'] = $qty;
            }
            $dummy = method_exists($orderItem, 'isDummy') && $orderItem->isDummy();
            if ($dummy || $qty == 0.0) {
                continue;
            }
            $rowTotal = $this->number($item->getRowTotal());
            if ($rowTotal === null) {
                return null;
            }
            $lines[$lineId]['amount'] += $rowTotal - max(
                0.0,
                $this->abs($item->getDiscountAmount()) - $this->abs($item->getDiscountTaxCompensationAmount())
            );
        }

        $out = [];
        foreach ($lines as $lineId => $line) {
            $qty = $line['quantity'];
            // A child item without its parent line, or a fractional quantity, cannot be sent.
            if ($qty === null || floor($qty) != $qty || $qty > self::MAX_QUANTITY) {
                return null;
            }
            $amount = max(0.0, $line['amount']);
            if ($qty == 0.0 && $amount == 0.0) {
                continue;
            }
            $text = $this->format($amount);
            if ($text === null || strlen((string) $lineId) > 256) {
                return null;
            }
            $out[] = ['lineId' => (string) $lineId, 'quantity' => (int) $qty, 'amount' => $text];
        }

        return $out === [] || count($out) > self::MAX_REFUND_LINES ? null : $out;
    }

    /** The order item of a credit memo item, from the item itself or else from the order. */
    private function orderItem(object $item, string $orderItemId, ?OrderInterface $order): ?object
    {
        $orderItem = method_exists($item, 'getOrderItem') ? $item->getOrderItem() : null;
        if (!is_object($orderItem) && $order !== null && method_exists($order, 'getItemById')) {
            $orderItem = $order->getItemById($orderItemId);
        }
        if (!is_object($orderItem)) {
            return null;
        }
        // The item must be the one the credit memo item points to.
        $id = method_exists($orderItem, 'getItemId') ? (string) $orderItem->getItemId() : '';

        return $id === '' || $id === $orderItemId ? $orderItem : null;
    }

    /** Magnitude of a Magento amount; 0 when it is unset or not a number. */
    private function abs(mixed $value): float
    {
        return abs($this->number($value) ?? 0.0);
    }

    private function number(mixed $value): ?float
    {
        if ($value === null || $value === '' || !is_numeric($value)) {
            return null;
        }

        return (float) $value;
    }

    /** Collector decimal string (same format as the purchase amounts), or null when it does not fit. */
    private function format(float $number): ?string
    {
        $text = number_format($number, 4, '.', '');
        $text = rtrim(rtrim($text, '0'), '.');
        if ($text === '' || $text === '-0') {
            $text = '0';
        }

        return preg_match('/^-?\d{1,12}(?:\.\d{1,9})?$/', $text) === 1 ? $text : null;
    }
}
