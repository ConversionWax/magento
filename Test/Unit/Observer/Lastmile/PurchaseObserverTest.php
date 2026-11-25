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

    public function testSendsOneLinePerVisibleItemThatAddsUpToTheSubtotal(): void
    {
        $items = [
            $this->item(['id' => 7, 'product' => 12, 'sku' => 'MH01-M-Red', 'name' => 'Hoodie', 'qty' => '2.0000',
                'price' => '50.0000', 'discount' => '10.0000', 'type' => 'configurable', 'child' => 15]),
            $this->item(['id' => 8, 'product' => 20, 'sku' => 'MUG', 'name' => 'Mug', 'qty' => 1.0,
                'price' => 12.5, 'discount' => 0.0, 'type' => 'simple']),
        ];
        $captured = $this->runObserver($this->order([
            'grand' => 107.5, 'subtotal' => '112.5', 'discount' => -10.0, 'tax' => 0.0, 'shipping' => 5.0,
        ], $items, 'SAVE10'));

        $this->assertSame([
            ['lineId' => '7', 'quantity' => 2, 'unitPrice' => '50', 'productId' => '12', 'variantId' => '15',
                'sku' => 'MH01-M-Red', 'title' => 'Hoodie', 'lineDiscount' => '10'],
            ['lineId' => '8', 'quantity' => 1, 'unitPrice' => '12.5', 'productId' => '20', 'sku' => 'MUG',
                'title' => 'Mug', 'lineDiscount' => '0'],
        ], $captured['lineItems']);
        $this->assertSame([['code' => 'SAVE10', 'amount' => '10']], $captured['discountCodes']);
        $net = 0.0;
        foreach ($captured['lineItems'] as $line) {
            $net += (float) $line['unitPrice'] * $line['quantity'] - (float) ($line['lineDiscount'] ?? 0);
        }
        $this->assertSame(112.5 - 10.0, $net);
    }

    public function testAFractionalQuantityDropsTheWholeList(): void
    {
        $items = [$this->item(['id' => 9, 'product' => 1, 'sku' => 'RICE', 'name' => 'Rice', 'qty' => 1.5,
            'price' => 4.0, 'discount' => 0.0, 'type' => 'simple'])];
        $captured = $this->runObserver($this->order([
            'grand' => 6.0, 'subtotal' => 6.0, 'discount' => null, 'tax' => null, 'shipping' => null,
        ], $items));

        $this->assertArrayNotHasKey('lineItems', $captured);
        $this->assertArrayNotHasKey('discountCodes', $captured);
        $this->assertSame('RICE', $captured['productId']);
    }

    public function testSeveralCouponCodesCarryNoAmount(): void
    {
        $captured = $this->runObserver($this->order([
            'grand' => 6.0, 'subtotal' => 6.0, 'discount' => -2.0, 'tax' => null, 'shipping' => null,
        ], [], 'A1, B2,A1'));

        $this->assertSame([['code' => 'A1'], ['code' => 'B2']], $captured['discountCodes']);
    }

    /** @param array<string, mixed> $d */
    private function item(array $d): object
    {
        return new class ($d) {
            /** @param array<string, mixed> $d */
            public function __construct(private array $d)
            {
            }

            public function getItemId(): mixed
            {
                return $this->d['id'];
            }

            public function getQuoteItemId(): mixed
            {
                return null;
            }

            public function getProductId(): mixed
            {
                return $this->d['product'];
            }

            public function getSku(): string
            {
                return $this->d['sku'];
            }

            public function getName(): string
            {
                return $this->d['name'];
            }

            public function getQtyOrdered(): mixed
            {
                return $this->d['qty'];
            }

            public function getPrice(): mixed
            {
                return $this->d['price'];
            }

            public function getDiscountAmount(): mixed
            {
                return $this->d['discount'];
            }

            public function getProductType(): string
            {
                return $this->d['type'];
            }

            /** @return list<object> */
            public function getChildrenItems(): array
            {
                if (!isset($this->d['child'])) {
                    return [];
                }
                $childId = $this->d['child'];
                return [new class ($childId) {
                    public function __construct(private int $id)
                    {
                    }

                    public function getProductId(): int
                    {
                        return $this->id;
                    }
                }];
            }
        };
    }

    /**
     * @param array<string, float|string|null> $amounts
     */
    private function order(array $amounts, array $items = [], ?string $coupon = null): object
    {
        return new class ($amounts, $items, $coupon) {
            /**
             * @param array<string, float|string|null> $a
             * @param list<object> $items
             */
            public function __construct(private array $a, private array $items, private ?string $coupon)
            {
            }

            /** @return list<object> */
            public function getAllVisibleItems(): array
            {
                return $this->items;
            }

            public function getCouponCode(): ?string
            {
                return $this->coupon;
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
