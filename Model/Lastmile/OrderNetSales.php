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
