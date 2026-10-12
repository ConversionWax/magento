<?php

declare(strict_types=1);

namespace Cwax\Banner\Test\Unit\ViewModel;

use Cwax\Banner\Model\Config;
use Cwax\Banner\ViewModel\VisitorSync;
use Magento\Cookie\Helper\Cookie as CookieHelper;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;

class VisitorSyncTest extends TestCase
{
    private function viewModel(bool $configured, bool $restrict): VisitorSync
    {
        $config = $this->createMock(Config::class);
        $config->method('isConfigured')->willReturn($configured);
        $cookieHelper = $this->createMock(CookieHelper::class);
        $cookieHelper->method('isCookieRestrictionModeEnabled')->willReturn($restrict);
        $store = $this->createMock(StoreInterface::class);
        $store->method('getId')->willReturn(1);
        $stores = $this->createMock(StoreManagerInterface::class);
        $stores->method('getStore')->willReturn($store);

        return new VisitorSync($config, $cookieHelper, $stores);
    }

    public function testEnabledFollowsTheConfiguration(): void
    {
        $this->assertTrue($this->viewModel(true, true)->isEnabled());
        $this->assertFalse($this->viewModel(false, true)->isEnabled());
    }

    public function testConfigJsonCarriesOnlyTheRestrictionFlag(): void
    {
        $this->assertSame('{"restriction":true}', $this->viewModel(true, true)->getConfigJson());
        $this->assertSame('{"restriction":false}', $this->viewModel(true, false)->getConfigJson());
    }
}
