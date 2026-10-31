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
        $config->method('getEndpoint')->willReturn('https://collect.test/v1/integrations/magento/events');
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
            new StoreUrl($stores)
        ))->process((string) json_encode([
            'version' => 1,
            'attempt' => 0,
            'storeViewId' => 1,
            'eventId' => 'e',
            'payload' => ['session' => ['entryUrl' => '/sale?utm_source=x']],
        ]));

        $this->assertSame('https://shop.test/sale?utm_source=x', $posted['session']['entryUrl']);
    }
}
