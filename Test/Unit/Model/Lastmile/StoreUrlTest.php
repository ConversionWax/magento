<?php

declare(strict_types=1);

namespace Cwax\Banner\Test\Unit\Model\Lastmile;

use Cwax\Banner\Model\Lastmile\StoreUrl;
use Magento\Framework\App\Request\Http;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;

class StoreUrlTest extends TestCase
{
    private function storeUrl(string $baseUrl, ?string $referer = null): StoreUrl
    {
        $store = $this->createStub(Store::class);
        $store->method('getBaseUrl')->willReturn($baseUrl);
        $stores = $this->createStub(StoreManagerInterface::class);
        $stores->method('getStore')->willReturn($store);

        $request = $this->createStub(Http::class);
        $request->method('getServer')->willReturn($referer);

        return new StoreUrl($stores, $request);
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

    public function testBasePathFromStoreUrl(): void
    {
        $this->assertSame(
            '/ocmlabs',
            $this->storeUrl('https://www.orangecollarmedia.com/ocmlabs/')->basePath()
        );
    }

    public function testRootStoreBasePathEmpty(): void
    {
        $this->assertSame('', $this->storeUrl('https://shop.test/')->basePath());
    }

    public function testSamePageReferer(): void
    {
        $url = $this->storeUrl(
            'https://www.orangecollarmedia.com/ocmlabs/',
            'https://www.orangecollarmedia.com/ocmlabs/fees.html?x=1'
        );

        $this->assertSame('/ocmlabs/fees.html', $url->samePageReferer());
    }

    public function testAddToCartIgnoresForeignReferer(): void
    {
        $this->assertNull(
            $this->storeUrl('https://shop.test/', 'https://evil.test/a')->samePageReferer()
        );
    }

    public function testMissingRefererIsNull(): void
    {
        $this->assertNull($this->storeUrl('https://shop.test/')->samePageReferer());
    }
}
