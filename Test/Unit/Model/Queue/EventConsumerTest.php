<?php

declare(strict_types=1);

namespace Cwax\Banner\Test\Unit\Model\Queue;

use Cwax\Banner\Model\Config;
use Cwax\Banner\Model\Lastmile\StoreUrl;
use Cwax\Banner\Model\Queue\EventConsumer;
use Cwax\Banner\Model\Queue\MessagePublisher;
use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\HTTP\Client\CurlFactory;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class EventConsumerTest extends TestCase
{
    public function testAQueuedRelativeEntryIsDeliveredAsAnAbsoluteUrl(): void
    {
        $config = $this->createStub(Config::class);
        $config->method('isConfigured')->willReturn(true);
        $config->method('getEventsUrl')->willReturn('https://collect.test/v1/integrations/events');
        $config->method('getApiToken')->willReturn('token');
        $posted = null;
        $curl = $this->createStub(Curl::class);
        $curl->method('post')->willReturnCallback(
            static function (string $url, $body) use (&$posted): void {
                $posted = json_decode((string) $body, true);
            }
        );
        $curl->method('getStatus')->willReturn(200);
        $curls = $this->createStub(CurlFactory::class);
        $curls->method('create')->willReturn($curl);
        $store = $this->createStub(Store::class);
        $store->method('getBaseUrl')->willReturn('https://shop.test/');
        $stores = $this->createStub(StoreManagerInterface::class);
        $stores->method('getStore')->willReturn($store);

        (new EventConsumer(
            $config,
            $this->createStub(MessagePublisher::class),
            $curls,
            $this->createStub(LoggerInterface::class),
            new StoreUrl($stores, $this->createStub(\Magento\Framework\App\RequestInterface::class))
        ))->process((string) json_encode([
            'version' => 1,
            'attempt' => 0,
            'storeViewId' => 1,
            'eventId' => 'e',
            'payload' => ['session' => ['entryUrl' => '/sale?utm_source=x']],
        ]));

        $this->assertSame('https://shop.test/sale?utm_source=x', $posted['session']['entryUrl']);
    }

    /** @return array{0: EventConsumer, 1: MessagePublisher&\PHPUnit\Framework\MockObject\MockObject} */
    private function consumerAnswering(int $status): array
    {
        $config = $this->createStub(Config::class);
        $config->method('isConfigured')->willReturn(true);
        $config->method('getEventsUrl')->willReturn('https://collect.test/v1/integrations/events');
        $config->method('getApiToken')->willReturn('token');
        $curl = $this->createStub(Curl::class);
        $curl->method('getStatus')->willReturn($status);
        $curls = $this->createStub(CurlFactory::class);
        $curls->method('create')->willReturn($curl);
        $publisher = $this->createMock(MessagePublisher::class);

        return [
            new EventConsumer(
                $config,
                $publisher,
                $curls,
                $this->createStub(LoggerInterface::class),
                new StoreUrl(
                    $this->createStub(StoreManagerInterface::class),
                    $this->createStub(\Magento\Framework\App\RequestInterface::class)
                )
            ),
            $publisher,
        ];
    }

    private function message(): string
    {
        return (string) json_encode([
            'version' => 1,
            'attempt' => 0,
            'storeViewId' => 1,
            'eventId' => 'e1',
            'payload' => ['events' => [['eventId' => 'e1', 'type' => 'product_view']]],
        ]);
    }

    public function testADeliveredEventIsNeverQueuedAgain(): void
    {
        [$consumer, $publisher] = $this->consumerAnswering(202);
        $publisher->expects($this->never())->method('publishEvent');
        $publisher->expects($this->never())->method('publishFailed');

        $consumer->process($this->message());
    }

    public function testARetryKeepsTheEventIdSoTheCollectorCollapsesIt(): void
    {
        [$consumer, $publisher] = $this->consumerAnswering(503);
        $publisher->expects($this->once())->method('publishEvent')->with($this->callback(
            static fn (array $retry): bool => $retry['attempt'] === 1
                && $retry['payload']['events'][0]['eventId'] === 'e1'
        ));

        $consumer->process($this->message());
    }
}
