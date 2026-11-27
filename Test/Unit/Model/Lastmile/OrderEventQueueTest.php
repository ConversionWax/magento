<?php

declare(strict_types=1);

namespace Cwax\Banner\Test\Unit\Model\Lastmile;

use Cwax\Banner\Model\Config;
use Cwax\Banner\Model\Lastmile\OrderEventQueue;
use Cwax\Banner\Model\Lastmile\OrderNetSales;
use Cwax\Banner\Model\Queue\MessagePublisher;
use Magento\Sales\Api\Data\CreditmemoInterface;
use Magento\Sales\Api\Data\OrderInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Refund and cancel events use the server-event shape that the collector accepts on
 * /v1/integrations/events (conversionwax_lastmile docs/generic-order-events.md).
 */
class OrderEventQueueTest extends TestCase
{
    private const WEB_PROPERTY_ID = '11111111-2222-4333-8444-555555555555';

    /** @var array<int, array<string, mixed>> */
    private array $published = [];

    public function testRefundMessageShape(): void
    {
        $before = (int) round(microtime(true) * 1000);
        $this->queue()->enqueueRefund($this->creditmemo(), $this->order());

        $this->assertCount(1, $this->published);
        $message = $this->published[0];
        $this->assertSame(1, $message['version']);
        $this->assertSame(0, $message['attempt']);
        $this->assertSame(3, $message['storeViewId']);
        $this->assertSame('refund-77', $message['eventId']);
        $this->assertArrayNotHasKey('clientIp', $message);

        $payload = $message['payload'];
        $this->assertSame(1, $payload['schemaVersion']);
        $this->assertSame(self::WEB_PROPERTY_ID, $payload['storeId']);
        $this->assertSame('server', $payload['visitorId']);
        $this->assertSame('server-order-events', $payload['sessionId']);
        $this->assertSame('magento', $payload['platform']);
        $this->assertSame('hook', $payload['capture']);
        $this->assertFalse($payload['session']['isNew']);
        $this->assertTrue($payload['session']['hadPurchase']);
        $this->assertSame(1, $payload['session']['number']);
        $this->assertNull($payload['session']['entryReferrer']);
        $this->assertSame('/', $payload['session']['entryUrl']);
        $this->assertArrayNotHasKey('device', $payload);
        $this->assertArrayNotHasKey('geo', $payload);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $payload['batchId']);

        $this->assertCount(1, $payload['events']);
        $event = $payload['events'][0];
        $this->assertSame([
            'eventId' => 'refund-77',
            'type' => 'refund',
            'ts' => $event['ts'],
            'orderId' => '000000042',
            'adjustmentId' => '000000077',
            'refundAmount' => '12.5',
            'netSalesAmount' => '27.5',
            'currency' => 'USD',
        ], $event);
        $this->assertIsInt($event['ts']);
        $this->assertGreaterThanOrEqual($before, $event['ts']);
        $this->assertSame($event['ts'], $payload['session']['startedAt']);
        $this->assertSame($event['ts'], $payload['session']['lastActivityAt']);
        $this->assertSame($event['ts'], $payload['sentAt']);
    }

    public function testRetryOfTheSameRefundHasTheSameIds(): void
    {
        $this->queue()->enqueueRefund($this->creditmemo(), $this->order());
        $this->queue()->enqueueRefund($this->creditmemo(), $this->order());

        $this->assertSame($this->published[0]['eventId'], $this->published[1]['eventId']);
        $this->assertSame($this->published[0]['payload']['batchId'], $this->published[1]['payload']['batchId']);
    }

    public function testRefundSendsNoPersonalData(): void
    {
        $this->queue()->enqueueRefund($this->creditmemo(), $this->order());

        $json = json_encode($this->published[0], JSON_THROW_ON_ERROR);
        foreach (['customerId', 'emailHash', 'buyer@example.com', 'clientIp', '"ua"'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $json);
        }
    }

    public function testRefundWithoutKnownNetStillSendsTheRefundAmount(): void
    {
        $this->queue()->enqueueRefund($this->creditmemo(), $this->order(['subtotal' => null]));

        $event = $this->published[0]['payload']['events'][0];
        $this->assertSame('12.5', $event['refundAmount']);
        $this->assertArrayNotHasKey('netSalesAmount', $event);
    }

    public function testRefundWithoutCreditMemoIdIsSkipped(): void
    {
        $this->queue()->enqueueRefund($this->creditmemo(['entity_id' => null]), $this->order());

        $this->assertSame([], $this->published);
    }

    public function testRefundCarriesRefundLinesAfterTheRefundAmount(): void
    {
        $lines = [['lineId' => '11', 'quantity' => 1, 'amount' => '12.5']];
        $netSales = $this->createMock(OrderNetSales::class);
        $netSales->method('refundAmount')->willReturn('12.5');
        $netSales->method('netAfterChange')->willReturn('27.5');
        $netSales->method('refundLines')->willReturn($lines);

        $this->queue(true, null, $netSales)->enqueueRefund($this->creditmemo(), $this->order());

        $event = $this->published[0]['payload']['events'][0];
        $this->assertSame($lines, $event['refundLines']);
        $this->assertSame('12.5', $event['refundAmount']);
    }

    public function testRefundLinesFailureStillSendsTheRefund(): void
    {
        $netSales = $this->createMock(OrderNetSales::class);
        $netSales->method('refundAmount')->willReturn('12.5');
        $netSales->method('netAfterChange')->willReturn('27.5');
        $netSales->method('refundLines')->willThrowException(new \RuntimeException('bad item'));

        $this->queue(true, null, $netSales)->enqueueRefund($this->creditmemo(), $this->order());

        $this->assertCount(1, $this->published);
        $event = $this->published[0]['payload']['events'][0];
        $this->assertSame('12.5', $event['refundAmount']);
        $this->assertArrayNotHasKey('refundLines', $event);
    }

    public function testCancelMessageShape(): void
    {
        $order = $this->order([
            'subtotal_refunded' => null, 'discount_refunded' => null,
            'subtotal_canceled' => 45.0, 'discount_canceled' => 5.0,
        ]);
        $this->queue()->enqueueCancel($order);

        $this->assertCount(1, $this->published);
        $message = $this->published[0];
        $this->assertSame('cancel-000000042', $message['eventId']);
        $this->assertSame(3, $message['storeViewId']);
        $event = $message['payload']['events'][0];
        $this->assertSame([
            'eventId' => 'cancel-000000042',
            'type' => 'cancel',
            'ts' => $event['ts'],
            'orderId' => '000000042',
            'cancelledAt' => $event['ts'],
            'netSalesAmount' => '0',
            'currency' => 'USD',
        ], $event);
    }

    public function testNotConfiguredStoreSendsNothing(): void
    {
        $this->queue(false)->enqueueRefund($this->creditmemo(), $this->order());
        $this->queue(false)->enqueueCancel($this->order());

        $this->assertSame([], $this->published);
    }

    public function testConfigIsReadForTheOrderStoreNotTheAdminStore(): void
    {
        $config = $this->createMock(Config::class);
        $config->expects($this->atLeastOnce())->method('isConfigured')->with(3)->willReturn(true);
        $config->method('getWebPropertyId')->with(3)->willReturn(self::WEB_PROPERTY_ID);

        $this->queue(true, $config)->enqueueCancel($this->order());

        $this->assertCount(1, $this->published);
    }

    public function testOrderWithoutIncrementIdIsSkipped(): void
    {
        $this->queue()->enqueueCancel($this->order(['increment_id' => '']));
        $this->queue()->enqueueRefund($this->creditmemo(), $this->order(['increment_id' => '']));

        $this->assertSame([], $this->published);
    }

    public function testPublishFailureIsLoggedAndNeverThrown(): void
    {
        $publisher = $this->createMock(MessagePublisher::class);
        $publisher->method('publishEvent')->willThrowException(new \RuntimeException('queue down'));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->exactly(2))->method('error');

        $queue = new OrderEventQueue($this->config(true), $publisher, new OrderNetSales(), $logger);
        $queue->enqueueRefund($this->creditmemo(), $this->order());
        $queue->enqueueCancel($this->order());
    }

    public function testCurrencyIsUpperCaseAndAnInvalidOneIsLeftOut(): void
    {
        $this->queue()->enqueueCancel($this->order(['currency' => 'eur']));
        $this->queue()->enqueueCancel($this->order(['currency' => 'not-a-code']));

        $this->assertSame('EUR', $this->published[0]['payload']['events'][0]['currency']);
        $this->assertArrayNotHasKey('currency', $this->published[1]['payload']['events'][0]);
    }

    public function testEventIdFitsTheCollectorLimit(): void
    {
        $this->queue()->enqueueCancel($this->order(['increment_id' => str_repeat('9', 200)]));

        $this->assertSame([], $this->published);
    }

    private function queue(
        bool $configured = true,
        ?Config $config = null,
        ?OrderNetSales $netSales = null
    ): OrderEventQueue
    {
        /** @var MessagePublisher&MockObject $publisher */
        $publisher = $this->createMock(MessagePublisher::class);
        $publisher->method('publishEvent')->willReturnCallback(function (array $message): void {
            $this->published[] = $message;
        });

        return new OrderEventQueue(
            $config ?? $this->config($configured),
            $publisher,
            $netSales ?? new OrderNetSales(),
            $this->createMock(LoggerInterface::class)
        );
    }

    private function config(bool $configured): Config
    {
        $config = $this->createMock(Config::class);
        $config->method('isConfigured')->willReturn($configured);
        $config->method('getWebPropertyId')->willReturn(self::WEB_PROPERTY_ID);

        return $config;
    }

    /** @param array<string, mixed> $values */
    private function creditmemo(array $values = []): CreditmemoInterface
    {
        $values += ['entity_id' => 77, 'increment_id' => '000000077'];
        $creditmemo = $this->createMock(CreditmemoInterface::class);
        $creditmemo->method('getEntityId')->willReturn($values['entity_id']);
        $creditmemo->method('getIncrementId')->willReturn($values['increment_id']);
        $creditmemo->method('getSubtotal')->willReturn(15.0);
        $creditmemo->method('getDiscountAmount')->willReturn(-2.5);
        $creditmemo->method('getTaxAmount')->willReturn(1.2);
        $creditmemo->method('getShippingAmount')->willReturn(5.0);
        $creditmemo->method('getGrandTotal')->willReturn(18.7);

        return $creditmemo;
    }

    /** @param array<string, mixed> $values */
    private function order(array $values = []): OrderInterface
    {
        $values += [
            'increment_id' => '000000042',
            'store_id' => 3,
            'currency' => 'usd',
            'subtotal' => 45.0,
            'discount' => -5.0,
            'subtotal_refunded' => 15.0,
            'discount_refunded' => -2.5,
            'subtotal_canceled' => null,
            'discount_canceled' => null,
        ];
        $order = $this->createMock(OrderInterface::class);
        $order->method('getIncrementId')->willReturn($values['increment_id']);
        $order->method('getStoreId')->willReturn($values['store_id']);
        $order->method('getOrderCurrencyCode')->willReturn($values['currency']);
        $order->method('getCustomerId')->willReturn(9);
        $order->method('getCustomerEmail')->willReturn('buyer@example.com');
        $order->method('getSubtotal')->willReturn($values['subtotal']);
        $order->method('getDiscountAmount')->willReturn($values['discount']);
        $order->method('getSubtotalRefunded')->willReturn($values['subtotal_refunded']);
        $order->method('getDiscountRefunded')->willReturn($values['discount_refunded']);
        $order->method('getSubtotalCanceled')->willReturn($values['subtotal_canceled']);
        $order->method('getDiscountCanceled')->willReturn($values['discount_canceled']);

        return $order;
    }
}
