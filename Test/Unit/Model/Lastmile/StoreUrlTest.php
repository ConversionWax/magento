<?php

declare(strict_types=1);

namespace Cwax\Banner\Test\Unit\Model\Lastmile;

use Cwax\Banner\Model\Lastmile\StoreUrl;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;

class StoreUrlTest extends TestCase
{
    private function storeUrl(string $baseUrl): StoreUrl
    {
        $store = $this->createStub(Store::class);
        $store->method('getBaseUrl')->willReturn($baseUrl);
        $stores = $this->createStub(StoreManagerInterface::class);
        $stores->method('getStore')->willReturn($store);

        return new StoreUrl($stores);
    }

    public function testARelativeEntryGetsTheStoreOrigin(): void
    {
        $this->assertSame(
            'https://shop.test/sale?utm_source=x',
            $this->storeUrl('https://shop.test/')->absolute(1, '/sale?utm_source=x')
        );
    }

    public function testABaseUrlPathIsNotAddedTwice(): void
    {
        $this->assertSame(
            'https://shop.test:8443/en/sale',
            $this->storeUrl('https://shop.test:8443/en/')->absolute(1, '/en/sale')
        );
    }

    public function testAnAbsoluteEntryIsLeftAlone(): void
    {
        $this->assertSame(
            'https://other.test/x',
            $this->storeUrl('https://shop.test/')->absolute(1, 'https://other.test/x')
        );
    }
}
