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
        $discount = abs($this->number($order->getDiscountAmount()) ?? 0.0)
            - abs($this->number($order->getDiscountCanceled()) ?? 0.0)
            - abs($this->number($order->getDiscountRefunded()) ?? 0.0);

        return $this->format(max(0.0, $products - max(0.0, $discount)));
    }

    /** Products minus discount of one credit memo; no tax, shipping or adjustments. */
    public function refundAmount(CreditmemoInterface $creditmemo): ?string
    {
        $subtotal = $this->number($creditmemo->getSubtotal());
        if ($subtotal === null) {
            return null;
        }
        $discount = abs($this->number($creditmemo->getDiscountAmount()) ?? 0.0);

        return $this->format(max(0.0, $subtotal - $discount));
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
