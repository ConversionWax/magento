<?php

declare(strict_types=1);

namespace Cwax\Banner\Test\Unit\ViewModel;

use Cwax\Banner\Model\Config;
use Cwax\Banner\ViewModel\LastmileSnippet;
use Magento\Cookie\Helper\Cookie as CookieHelper;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;

class LastmileSnippetTest extends TestCase
{
    private function viewModel(bool $load, bool $restrict = false): LastmileSnippet
    {
        $config = $this->createStub(Config::class);
        $config->method('shouldLoadSnippet')->willReturnCallback(
            static fn (int $storeId): bool => $load && $storeId === 3
        );
        $config->method('getSnippetUrl')->willReturn('https://assets.conversionwax.com/lastmile/v1/lm.js');
        $config->method('getWebPropertyId')->willReturn('0f8fad5b-d9cb-469f-a165-70867728950e');
        $config->method('getIntegrationId')->willReturn('7c9e6679-7425-40de-944b-e07fc1f90ae7');
        $cookieHelper = $this->createStub(CookieHelper::class);
        $cookieHelper->method('isCookieRestrictionModeEnabled')->willReturn($restrict);
        $store = $this->createStub(StoreInterface::class);
        $store->method('getId')->willReturn(3);
        $stores = $this->createStub(StoreManagerInterface::class);
        $stores->method('getStore')->willReturn($store);

        return new LastmileSnippet($config, $cookieHelper, $stores);
    }

    public function testItFollowsTheCurrentStoreView(): void
    {
        $this->assertTrue($this->viewModel(true)->isEnabled());
        $this->assertFalse($this->viewModel(false)->isEnabled());
    }

    public function testItExposesTheTagValues(): void
    {
        $viewModel = $this->viewModel(true);

        $this->assertSame('https://assets.conversionwax.com/lastmile/v1/lm.js', $viewModel->getSnippetUrl());
        $this->assertSame('0f8fad5b-d9cb-469f-a165-70867728950e', $viewModel->getWebsiteId());
        $this->assertSame('7c9e6679-7425-40de-944b-e07fc1f90ae7', $viewModel->getIntegrationId());
    }

    public function testRestrictionIsTheStoreSettingOnly(): void
    {
        $this->assertTrue($this->viewModel(true, true)->respectsRestriction());
        $this->assertFalse($this->viewModel(true)->respectsRestriction());
    }
}
