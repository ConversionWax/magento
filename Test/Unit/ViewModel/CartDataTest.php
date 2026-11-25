<?php

declare(strict_types=1);

namespace Cwax\Banner\Test\Unit\ViewModel;

use Cwax\Banner\ViewModel\CartData;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;

class CartDataTest extends TestCase
{
    private function viewModel(StoreInterface $store): CartData
    {
        $stores = $this->createStub(StoreManagerInterface::class);
        $stores->method('getStore')->willReturn($store);

        return new CartData($stores);
    }

    public function testItIsTheStoreViewCurrentCurrency(): void
    {
        $store = $this->createStub(Store::class);
        $store->method('getCurrentCurrencyCode')->willReturn('EUR');

        $this->assertSame('EUR', $this->viewModel($store)->getCurrencyCode());
    }

    public function testAStoreWithoutCurrencyGivesAnEmptyCode(): void
    {
        $this->assertSame('', $this->viewModel($this->createStub(StoreInterface::class))->getCurrencyCode());
    }
}
