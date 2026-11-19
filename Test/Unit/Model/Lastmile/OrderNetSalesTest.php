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

        return $order;
    }
}
