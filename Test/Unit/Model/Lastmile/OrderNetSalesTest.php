<?php

declare(strict_types=1);

namespace Cwax\Banner\Test\Unit\Model\Lastmile;

use Cwax\Banner\Model\Lastmile\OrderNetSales;
use Magento\Sales\Api\Data\CreditmemoInterface;
use Magento\Sales\Api\Data\OrderInterface;
use PHPUnit\Framework\TestCase;

/**
 * Net sales = products after discounts, refunds and cancels, before tax and shipping
 * (conversionwax_lastmile docs/generic-order-events.md, "Net sales").
 */
class OrderNetSalesTest extends TestCase
{
    public function testPlacedOrderIsSubtotalMinusDiscount(): void
    {
        $order = $this->order(['subtotal' => 45.0, 'discount' => -5.0]);

        $this->assertSame('40', (new OrderNetSales())->netAfterChange($order));
    }

    public function testPartialRefundLowersNetByRefundedProductsAndDiscount(): void
    {
        // Magento adds the credit memo totals to the order before the refund event.
        $order = $this->order([
            'subtotal' => 45.0, 'discount' => -5.0,
            'subtotal_refunded' => 15.0, 'discount_refunded' => -2.5,
        ]);

        $this->assertSame('27.5', (new OrderNetSales())->netAfterChange($order));
    }

    public function testFullRefundIsZero(): void
    {
        $order = $this->order([
            'subtotal' => 45.0, 'discount' => -5.0,
            'subtotal_refunded' => 45.0, 'discount_refunded' => -5.0,
        ]);

        $this->assertSame('0', (new OrderNetSales())->netAfterChange($order));
    }

    public function testCancelOfUninvoicedOrderIsZero(): void
    {
        // registerCancellation stores discount_canceled as a positive number.
        $order = $this->order([
            'subtotal' => 45.0, 'discount' => -5.0,
            'subtotal_canceled' => 45.0, 'discount_canceled' => 5.0,
        ]);

        $this->assertSame('0', (new OrderNetSales())->netAfterChange($order));
    }

    public function testCancelOfPartlyInvoicedOrderKeepsTheInvoicedPart(): void
    {
        $order = $this->order([
            'subtotal' => 100.0, 'discount' => -10.0,
            'subtotal_canceled' => 40.0, 'discount_canceled' => 4.0,
        ]);

        $this->assertSame('54', (new OrderNetSales())->netAfterChange($order));
    }

    public function testCancelOfPartlyInvoicedOrderMagento248Form(): void
    {
        // 2.4.7 and later: discount_canceled = abs(discount_amount) - abs(discount_invoiced) = 4.
        $order = $this->order([
            'subtotal' => 100.0, 'discount' => -10.0, 'discount_invoiced' => -6.0,
            'subtotal_canceled' => 40.0, 'discount_canceled' => 4.0,
        ]);

        $this->assertSame('54', (new OrderNetSales())->netAfterChange($order));
    }

    public function testCancelOfPartlyInvoicedOrderMagento246Form(): void
    {
        // 2.4.6: discount_canceled = abs(discount_amount) - discount_invoiced, and discount_invoiced is
        // negative, so Magento stores 16. Only the uninvoiced discount (4) can be canceled.
        $order = $this->order([
            'subtotal' => 100.0, 'discount' => -10.0, 'discount_invoiced' => -6.0,
            'subtotal_canceled' => 40.0, 'discount_canceled' => 16.0,
        ]);

        $this->assertSame('54', (new OrderNetSales())->netAfterChange($order));
    }

    public function testCancelOfUninvoicedOrderIsZeroInBothForms(): void
    {
        foreach ([5.0, 10.0] as $canceled) {
            $order = $this->order([
                'subtotal' => 45.0, 'discount' => -5.0, 'discount_invoiced' => null,
                'subtotal_canceled' => 45.0, 'discount_canceled' => $canceled,
            ]);
            $this->assertSame('0', (new OrderNetSales())->netAfterChange($order));
        }
    }

    public function testDiscountIncludingTaxIsTakenBeforeTax(): void
    {
        // Subtotal 100 net of 20 percent VAT, 10 percent discount on the gross 120: discount_amount 12,
        // of which 2 is tax (discount_tax_compensation_amount). Net before tax is 100 - 10 = 90.
        $order = $this->order(['subtotal' => 100.0, 'discount' => -12.0, 'comp' => 2.0]);

        $this->assertSame('90', (new OrderNetSales())->netAfterChange($order));
    }

    public function testDiscountIncludingTaxAfterPartialRefund(): void
    {
        // Half refunded: subtotal 50, discount 6 of which 1 is tax compensation. Net 50 - 5 = 45.
        $order = $this->order([
            'subtotal' => 100.0, 'discount' => -12.0, 'comp' => 2.0,
            'subtotal_refunded' => 50.0, 'discount_refunded' => -6.0, 'comp_refunded' => 1.0,
        ]);

        $this->assertSame('45', (new OrderNetSales())->netAfterChange($order));
    }

    public function testDiscountIncludingTaxAfterCancelOfPartlyInvoicedOrder(): void
    {
        // Invoiced 60: discount 7.2 (compensation 1.2). Canceled 40: discount 4.8 (compensation 0.8).
        // Net 60 - 6 = 54, in both the 2.4.6 (discount_canceled 12 + 7.2 = 19.2) and 2.4.8 (4.8) forms.
        foreach ([4.8, 19.2] as $canceled) {
            $order = $this->order([
                'subtotal' => 100.0, 'discount' => -12.0, 'comp' => 2.0,
                'discount_invoiced' => -7.2, 'comp_invoiced' => 1.2,
                'subtotal_canceled' => 40.0, 'discount_canceled' => $canceled,
            ]);
            $this->assertSame('54', (new OrderNetSales())->netAfterChange($order));
        }
    }

    public function testRefundAmountWithDiscountIncludingTax(): void
    {
        $creditmemo = $this->createMock(CreditmemoInterface::class);
        $creditmemo->method('getSubtotal')->willReturn(50.0);
        $creditmemo->method('getDiscountAmount')->willReturn(-6.0);
        $creditmemo->method('getDiscountTaxCompensationAmount')->willReturn(1.0);

        $this->assertSame('45', (new OrderNetSales())->refundAmount($creditmemo));
    }

    public function testNeverNegative(): void
    {
        $order = $this->order(['subtotal' => 10.0, 'discount' => -0.0, 'subtotal_refunded' => 10.01]);

        $this->assertSame('0', (new OrderNetSales())->netAfterChange($order));
    }

    public function testFloatNoiseIsRounded(): void
    {
        $order = $this->order(['subtotal' => 0.3, 'discount' => -0.1, 'subtotal_refunded' => 0.1]);

        $this->assertSame('0.1', (new OrderNetSales())->netAfterChange($order));
    }

    public function testUnknownSubtotalGivesNoNet(): void
    {
        $order = $this->order(['subtotal' => null, 'discount' => -5.0]);

        $this->assertNull((new OrderNetSales())->netAfterChange($order));
    }

    public function testRefundAmountIsCreditMemoProductsMinusDiscountWithoutTaxShippingOrAdjustments(): void
    {
        $creditmemo = $this->createMock(CreditmemoInterface::class);
        $creditmemo->method('getSubtotal')->willReturn(15.0);
        $creditmemo->method('getDiscountAmount')->willReturn(-2.5);
        $creditmemo->method('getTaxAmount')->willReturn(1.2);
        $creditmemo->method('getShippingAmount')->willReturn(5.0);
        $creditmemo->method('getAdjustmentPositive')->willReturn(3.0);
        $creditmemo->method('getGrandTotal')->willReturn(21.7);

        $this->assertSame('12.5', (new OrderNetSales())->refundAmount($creditmemo));
    }

    public function testRefundAmountWithUnknownSubtotalIsNull(): void
    {
        $creditmemo = $this->createMock(CreditmemoInterface::class);
        $creditmemo->method('getSubtotal')->willReturn(null);

        $this->assertNull((new OrderNetSales())->refundAmount($creditmemo));
    }

    public function testAmountsMatchTheCollectorDecimalPattern(): void
    {
        $order = $this->order(['subtotal' => 1234567890.12, 'discount' => -0.02]);

        $this->assertMatchesRegularExpression(
            '/^-?\d{1,12}(?:\.\d{1,9})?$/',
            (string) (new OrderNetSales())->netAfterChange($order)
        );
        $this->assertSame('1234567890.1', (new OrderNetSales())->netAfterChange($order));
    }

    /** @param array<string, float|null> $values */
    private function order(array $values): OrderInterface
    {
        $order = $this->createMock(OrderInterface::class);
        $order->method('getSubtotal')->willReturn($values['subtotal'] ?? null);
        $order->method('getDiscountAmount')->willReturn($values['discount'] ?? null);
        $order->method('getSubtotalRefunded')->willReturn($values['subtotal_refunded'] ?? null);
        $order->method('getDiscountRefunded')->willReturn($values['discount_refunded'] ?? null);
        $order->method('getSubtotalCanceled')->willReturn($values['subtotal_canceled'] ?? null);
        $order->method('getDiscountCanceled')->willReturn($values['discount_canceled'] ?? null);
        $order->method('getDiscountInvoiced')->willReturn($values['discount_invoiced'] ?? null);
        $order->method('getDiscountTaxCompensationAmount')->willReturn($values['comp'] ?? null);
        $order->method('getDiscountTaxCompensationInvoiced')->willReturn($values['comp_invoiced'] ?? null);
        $order->method('getDiscountTaxCompensationRefunded')->willReturn($values['comp_refunded'] ?? null);

        return $order;
    }
}
