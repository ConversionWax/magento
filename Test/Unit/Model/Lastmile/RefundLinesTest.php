<?php

declare(strict_types=1);

namespace Cwax\Banner\Test\Unit\Model\Lastmile;

use Cwax\Banner\Model\Lastmile\OrderNetSales;
use Magento\Sales\Api\Data\CreditmemoInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Creditmemo\Item as CreditmemoItem;
use Magento\Sales\Model\Order\Item as OrderItem;
use PHPUnit\Framework\TestCase;

/**
 * CWTASKS-128: refundLines of the collector refund event (conversionwax_lastmile
 * docs/generic-order-events.md, services/collector/src/orderLines.ts): lineId = the purchase lineId (visible
 * order item id), quantity = refunded quantity, amount = line subtotal after discount, before tax. The
 * amounts add up to OrderNetSales::refundAmount.
 */
class RefundLinesTest extends TestCase
{
    public function testFullRefundSendsEveryLine(): void
    {
        $creditmemo = $this->creditmemo([
            $this->item($this->orderItem(11), 1, 25.0, 5.0),
            $this->item($this->orderItem(12), 2, 20.0),
        ], 45.0, -5.0);

        $sales = new OrderNetSales();
        $this->assertSame([
            ['lineId' => '11', 'quantity' => 1, 'amount' => '20'],
            ['lineId' => '12', 'quantity' => 2, 'amount' => '20'],
        ], $sales->refundLines($creditmemo));
        $this->assertSame('40', $sales->refundAmount($creditmemo));
    }

    public function testPartialRefundOfOneOfTwoLinesSendsOnlyThatLine(): void
    {
        $creditmemo = $this->creditmemo([
            $this->item($this->orderItem(11), 0, 0.0),
            $this->item($this->orderItem(12), 1, 10.0, 1.25),
        ], 10.0, -1.25);

        $sales = new OrderNetSales();
        $this->assertSame(
            [['lineId' => '12', 'quantity' => 1, 'amount' => '8.75']],
            $sales->refundLines($creditmemo)
        );
        $this->assertSame('8.75', $sales->refundAmount($creditmemo));
    }

    public function testConfigurableChildIsSentOnceOnTheParentLine(): void
    {
        $parent = $this->orderItem(21);
        $child = $this->orderItem(22, 21, true);
        $creditmemo = $this->creditmemo([
            $this->item($parent, 1, 30.0, 3.0),
            $this->item($child, 1, 0.0),
        ], 30.0, -3.0);

        $this->assertSame(
            [['lineId' => '21', 'quantity' => 1, 'amount' => '27']],
            (new OrderNetSales())->refundLines($creditmemo)
        );
    }

    public function testDynamicBundleAmountsComeFromTheChildren(): void
    {
        // A dynamic bundle parent is the dummy item; its children carry the prices.
        $parent = $this->orderItem(31, null, true);
        $creditmemo = $this->creditmemo([
            $this->item($parent, 1, 99.0),
            $this->item($this->orderItem(32, 31), 1, 15.0),
            $this->item($this->orderItem(33, 31), 2, 10.0, 2.0),
        ], 25.0, -2.0);

        $sales = new OrderNetSales();
        $this->assertSame(
            [['lineId' => '31', 'quantity' => 1, 'amount' => '23']],
            $sales->refundLines($creditmemo)
        );
        $this->assertSame('23', $sales->refundAmount($creditmemo));
    }

    public function testDiscountTaxCompensationIsNotTakenOff(): void
    {
        $creditmemo = $this->creditmemo([$this->item($this->orderItem(11), 1, 50.0, 6.0, 1.0)], 50.0, -6.0, 1.0);

        $sales = new OrderNetSales();
        $this->assertSame([['lineId' => '11', 'quantity' => 1, 'amount' => '45']], $sales->refundLines($creditmemo));
        $this->assertSame('45', $sales->refundAmount($creditmemo));
    }

    public function testZeroAmountRefundSendsNoLines(): void
    {
        // An adjustment-only credit memo: no item is refunded.
        $creditmemo = $this->creditmemo([
            $this->item($this->orderItem(11), 0, 0.0),
            $this->item($this->orderItem(12), 0, 0.0),
        ], 0.0, 0.0);

        $this->assertNull((new OrderNetSales())->refundLines($creditmemo));
    }

    public function testFreeLineRefundedSendsAZeroAmount(): void
    {
        $creditmemo = $this->creditmemo([$this->item($this->orderItem(11), 1, 10.0, 10.0)], 10.0, -10.0);

        $this->assertSame(
            [['lineId' => '11', 'quantity' => 1, 'amount' => '0']],
            (new OrderNetSales())->refundLines($creditmemo)
        );
    }

    public function testUnreadableLineGivesNoLines(): void
    {
        $sales = new OrderNetSales();
        // Fractional quantity.
        $this->assertNull($sales->refundLines($this->creditmemo([
            $this->item($this->orderItem(11), 1, 10.0),
            $this->item($this->orderItem(12), 1.5, 15.0),
        ], 25.0, 0.0)));
        // Row total that is not a number.
        $this->assertNull($sales->refundLines($this->creditmemo([
            $this->item($this->orderItem(11), 1, 10.0),
            $this->item($this->orderItem(12), 1, null),
        ], 10.0, 0.0)));
        // Order item that cannot be found.
        $this->assertNull($sales->refundLines($this->creditmemo([
            $this->item($this->orderItem(11), 1, 10.0),
            $this->item(null, 1, 15.0, 0.0, 0.0, 12),
        ], 25.0, 0.0)));
        // Child item without its parent line.
        $this->assertNull($sales->refundLines($this->creditmemo([
            $this->item($this->orderItem(22, 21, true), 1, 0.0),
        ], 0.0, 0.0)));
    }

    public function testOrderItemIsReadFromTheOrderWhenTheItemHasNone(): void
    {
        $order = $this->createMock(Order::class);
        $order->method('getItemById')->with('12')->willReturn($this->orderItem(12));
        $creditmemo = $this->creditmemo([$this->item(null, 1, 15.0, 0.0, 0.0, 12)], 15.0, 0.0);

        $this->assertSame(
            [['lineId' => '12', 'quantity' => 1, 'amount' => '15']],
            (new OrderNetSales())->refundLines($creditmemo, $order)
        );
    }

    public function testMoreThan500LinesGivesNoLines(): void
    {
        $items = [];
        for ($i = 1; $i <= 501; $i++) {
            $items[] = $this->item($this->orderItem($i), 1, 1.0);
        }

        $this->assertNull((new OrderNetSales())->refundLines($this->creditmemo($items, 501.0, 0.0)));
    }

    /** @param list<CreditmemoItem> $items */
    private function creditmemo(array $items, float $subtotal, float $discount, float $compensation = 0.0): CreditmemoInterface
    {
        $creditmemo = $this->createMock(CreditmemoInterface::class);
        $creditmemo->method('getItems')->willReturn($items);
        $creditmemo->method('getSubtotal')->willReturn($subtotal);
        $creditmemo->method('getDiscountAmount')->willReturn($discount);
        $creditmemo->method('getDiscountTaxCompensationAmount')->willReturn($compensation);

        return $creditmemo;
    }

    private function orderItem(int $id, ?int $parentId = null, bool $dummy = false): OrderItem
    {
        $item = $this->createMock(OrderItem::class);
        $item->method('getItemId')->willReturn((string) $id);
        $item->method('getParentItemId')->willReturn($parentId);
        $item->method('isDummy')->willReturn($dummy);

        return $item;
    }

    private function item(
        ?OrderItem $orderItem,
        float|int $qty,
        ?float $rowTotal,
        float $discount = 0.0,
        float $compensation = 0.0,
        ?int $orderItemId = null
    ): CreditmemoItem {
        $item = $this->createMock(CreditmemoItem::class);
        $item->method('getOrderItem')->willReturn($orderItem);
        $item->method('getOrderItemId')->willReturn($orderItemId ?? (int) $orderItem?->getItemId());
        $item->method('getQty')->willReturn((float) $qty);
        $item->method('getRowTotal')->willReturn($rowTotal);
        $item->method('getDiscountAmount')->willReturn($discount);
        $item->method('getDiscountTaxCompensationAmount')->willReturn($compensation);

        return $item;
    }
}
