<?php

declare(strict_types=1);

namespace Cwax\Banner\Test\Unit\ViewModel;

use Cwax\Banner\Model\Config;
use Cwax\Banner\ViewModel\ProductView;
use Magento\Catalog\Helper\Data as CatalogHelper;
use Magento\Catalog\Model\Product;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;

class ProductViewTest extends TestCase
{
    private function viewModel(bool $configured, ?string $sku): ProductView
    {
        $config = $this->createStub(Config::class);
        $config->method('isConfigured')->willReturn($configured);
        $product = null;
        if ($sku !== null) {
            $product = $this->createStub(Product::class);
            $product->method('getSku')->willReturn($sku);
        }
        $catalog = $this->createStub(CatalogHelper::class);
        $catalog->method('getProduct')->willReturn($product);
        $store = $this->createStub(StoreInterface::class);
        $store->method('getId')->willReturn(1);
        $stores = $this->createStub(StoreManagerInterface::class);
        $stores->method('getStore')->willReturn($store);

        return new ProductView($config, $catalog, $stores);
    }

    public function testReturnsTheSkuOfTheCurrentProduct(): void
    {
        $this->assertSame('seo', $this->viewModel(true, 'seo')->getSku());
    }

    public function testReturnsNothingWithoutAProductOrWhenNotConfigured(): void
    {
        $this->assertSame('', $this->viewModel(true, null)->getSku());
        $this->assertSame('', $this->viewModel(false, 'seo')->getSku());
    }

    public function testTheProductPageLayoutRendersTheSkuTemplateInTheHead(): void
    {
        $root = dirname(__DIR__, 3);
        $xml = simplexml_load_file($root . '/view/frontend/layout/catalog_product_view.xml');
        $this->assertNotFalse($xml);
        $blocks = $xml->xpath('//referenceContainer[@name="head.additional"]/block[@template="Cwax_Banner::product-view.phtml"]');
        $this->assertCount(1, $blocks);

        $template = (string) file_get_contents($root . '/view/frontend/templates/product-view.phtml');
        $this->assertStringContainsString('name="cwax-product"', $template);
        $this->assertStringContainsString('escapeHtmlAttr($sku)', $template);
    }
}
