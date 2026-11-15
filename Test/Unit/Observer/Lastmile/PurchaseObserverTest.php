<?php

declare(strict_types=1);

namespace Cwax\Banner\Test\Unit\Observer\Lastmile;

use Cwax\Banner\Model\Lastmile\EmailHasher;
use Cwax\Banner\Model\Lastmile\EventQueue;
use Cwax\Banner\Observer\Lastmile\PurchaseObserver;
use Magento\Framework\App\State;
use Magento\Framework\Event;
use Magento\Framework\Event\Observer;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class PurchaseObserverTest extends TestCase
{
    private const AMOUNT_PATTERN = '/^-?\d{1,12}(?:\.\d{1,9})?$/';

    public function testSendsAllAmountsAsDecimalStrings(): void
    {
        $captured = $this->runObserver($this->order([
            'grand' => 1234.5, 'subtotal' => '1200.00', 'discount' => -10.255, 'tax' => 100.0, 'shipping' => 5.0,
        ]));

        $this->assertSame('1234.5', $captured['grossAmount']);
        $this->assertSame('1200', $captured['subtotalAmount']);
        $this->assertSame('10.255', $captured['discountAmount']);
        $this->assertSame('100', $captured['taxAmount']);
        $this->assertSame('5', $captured['shippingAmount']);
        $this->assertSame(1234.5, $captured['value']);
        $this->assertSame('USD', $captured['currency']);
        $this->assertArrayNotHasKey('netSalesAmount', $captured);
        foreach (['grossAmount', 'subtotalAmount', 'discountAmount', 'taxAmount', 'shippingAmount'] as $key) {
            $this->assertMatchesRegularExpression(self::AMOUNT_PATTERN, $captured[$key]);
        }
    }

    public function testNullGettersAreOmitted(): void
    {
        $captured = $this->runObserver($this->order([
            'grand' => 20.0, 'subtotal' => null, 'discount' => null, 'tax' => null, 'shipping' => null,
        ]));

        $this->assertSame('20', $captured['grossAmount']);
        foreach (['subtotalAmount', 'discountAmount', 'taxAmount', 'shippingAmount'] as $key) {
            $this->assertArrayNotHasKey($key, $captured);
        }
    }

    public function testZeroAndNegativeZeroNormalise(): void
    {
        $captured = $this->runObserver($this->order([
            'grand' => 0.0, 'subtotal' => 0.0, 'discount' => -0.0, 'tax' => 0.0, 'shipping' => 0.0,
        ]));

        $this->assertSame('0', $captured['discountAmount']);
        $this->assertSame('0', $captured['grossAmount']);
    }

    public function testLargeAmountHasNoExponentOrSeparator(): void
    {
        $captured = $this->runObserver($this->order([
            'grand' => 1234567890.12, 'subtotal' => 1.0E+7, 'discount' => null, 'tax' => null, 'shipping' => null,
        ]));

        $this->assertSame('1234567890.12', $captured['grossAmount']);
        $this->assertSame('10000000', $captured['subtotalAmount']);
    }

    /**
     * @param array<string, float|string|null> $amounts
     */
    private function order(array $amounts): object
    {
        return new class ($amounts) {
            /** @param array<string, float|string|null> $a */
            public function __construct(private array $a)
            {
            }

            public function getIncrementId(): string
            {
                return '000000042';
            }

            public function getCustomerEmail(): string
            {
                return 'a@example.com';
            }

            public function getCustomerId(): ?int
            {
                return null;
            }

            public function getOrderCurrencyCode(): string
            {
                return 'usd';
            }

            public function getGrandTotal(): float|string|null
            {
                return $this->a['grand'];
            }

            public function getSubtotal(): float|string|null
            {
                return $this->a['subtotal'];
            }

            public function getDiscountAmount(): float|string|null
            {
                return $this->a['discount'];
            }

            public function getTaxAmount(): float|string|null
            {
                return $this->a['tax'];
            }

            public function getShippingAmount(): float|string|null
            {
                return $this->a['shipping'];
            }
        };
    }

    /** @return array<string, mixed> */
    private function runObserver(object $order): array
    {
        $captured = [];
        $queue = $this->createMock(EventQueue::class);
        $queue->expects($this->once())->method('enqueue')->willReturnCallback(
            function (string $type, array $attributes) use (&$captured): void {
                $this->assertSame('purchase', $type);
                $captured = $attributes;
            }
        );
        $state = $this->createMock(State::class);
        $state->method('getAreaCode')->willReturn('frontend');
        $hasher = $this->createMock(EmailHasher::class);
        $hasher->method('hash')->willReturn('hash');

        $event = $this->getMockBuilder(Event::class)->addMethods(['getOrders', 'getOrder'])->getMock();
        $event->method('getOrders')->willReturn(null);
        $event->method('getOrder')->willReturn($order);
        $observer = new Observer(['event' => $event]);

        (new PurchaseObserver($queue, $state, $hasher, $this->createMock(LoggerInterface::class)))
            ->execute($observer);

        return $captured;
    }
}
