<?php

declare(strict_types=1);

namespace Cwax\Banner\Test\Unit\ViewModel;

use Cwax\Banner\Model\Config;
use Cwax\Banner\ViewModel\CategoryView;
use Magento\Catalog\Helper\Data as CatalogHelper;
use Magento\Catalog\Model\Category;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;

class CategoryViewTest extends TestCase
{
    private function viewModel(bool $configured, ?array $category): CategoryView
    {
        $config = $this->createStub(Config::class);
        $config->method('isConfigured')->willReturn($configured);
        $model = null;
        if ($category !== null) {
            $model = $this->createStub(Category::class);
            $model->method('getId')->willReturn($category['id']);
            $model->method('getName')->willReturn($category['name']);
            $model->method('getUrl')->willReturn($category['url']);
        }
        $catalog = $this->createStub(CatalogHelper::class);
        $catalog->method('getCategory')->willReturn($model);
        $store = $this->createStub(StoreInterface::class);
        $store->method('getId')->willReturn(1);
        $stores = $this->createStub(StoreManagerInterface::class);
        $stores->method('getStore')->willReturn($store);

        return new CategoryView($config, $catalog, $stores);
    }

    public function testContextHasIdNameAndPathOfTheCategory(): void
    {
        $context = $this->viewModel(true, [
            'id' => 12,
            'name' => '  Men   Tops ',
            'url' => 'https://shop.example.com/men/tops.html?p=2',
        ])->getContext();
        $this->assertSame([
            'category' => '12',
            'categoryName' => 'Men Tops',
            'categoryUrl' => '/men/tops.html?p=2',
            'pageType' => 'category',
            'platform' => 'magento',
        ], $context);
    }

    public function testInvalidNameOrPathLeavesOnlyTheId(): void
    {
        $expected = ['category' => '5', 'pageType' => 'category', 'platform' => 'magento'];
        $this->assertSame($expected, $this->viewModel(true, [
            'id' => '5', 'name' => 'Shoes <b>', 'url' => 'https://shop.example.com/shoes.html',
        ])->getContext());
        $this->assertSame($expected, $this->viewModel(true, [
            'id' => '5', 'name' => 'Shoes', 'url' => 'https://shop.example.com/' . str_repeat('a', 70),
        ])->getContext());
    }

    public function testNoContextWithoutACategoryOrWhenNotConfigured(): void
    {
        $this->assertSame([], $this->viewModel(true, null)->getContext());
        $this->assertSame([], $this->viewModel(true, ['id' => '', 'name' => 'A', 'url' => '/a'])->getContext());
        $this->assertSame([], $this->viewModel(false, ['id' => 1, 'name' => 'A', 'url' => '/a'])->getContext());
    }

    /** @dataProvider names */
    public function testCleanName(string $input, string $expected): void
    {
        $this->assertSame($expected, CategoryView::cleanName($input));
    }

    public static function names(): array
    {
        return [
            'plain' => ['Shoes', 'Shoes'],
            'folds whitespace' => ["  Men\t\n  Tops ", 'Men Tops'],
            'empty' => ['   ', ''],
            '40 chars ok' => [str_repeat('a', 40), str_repeat('a', 40)],
            '41 chars refused' => [str_repeat('a', 41), ''],
            'non ascii ok' => ['Chaussures d\'ete', 'Chaussures d\'ete'],
            'angle' => ['a<b', ''],
            'brace' => ['a{b', ''],
            'quote' => ['a"b', ''],
            'backslash' => ['a\\b', ''],
            'control' => ["a\x07b", ''],
            'ampersand ok' => ['Hats & Caps', 'Hats & Caps'],
        ];
    }

    /** @dataProvider urls */
    public function testCleanPath(string $input, string $expected): void
    {
        $this->assertSame($expected, CategoryView::cleanPath($input));
    }

    public static function urls(): array
    {
        return [
            'drops host' => ['https://shop.example.com/men.html', '/men.html'],
            'keeps query' => ['https://shop.example.com/men.html?color=red', '/men.html?color=red'],
            'drops fragment' => ['https://shop.example.com/men.html#top', '/men.html'],
            'path only' => ['/men', '/men'],
            'root' => ['https://shop.example.com/', '/'],
            'no path' => ['https://shop.example.com', ''],
            'double slash' => ['https://shop.example.com//evil.com', ''],
            'space' => ['https://shop.example.com/a b', ''],
            'non ascii' => ["https://shop.example.com/caf\u{e9}", ''],
            '64 ok' => ['/' . str_repeat('a', 63), '/' . str_repeat('a', 63)],
            '65 refused' => ['/' . str_repeat('a', 64), ''],
            'quote refused' => ['/a"b', ''],
        ];
    }

    public function testTheCategoryLayoutPassesTheViewModelToTheLastMileBlock(): void
    {
        $root = dirname(__DIR__, 3);
        $xml = simplexml_load_file($root . '/view/frontend/layout/catalog_category_view.xml');
        $this->assertNotFalse($xml);
        $args = $xml->xpath('//referenceBlock[@name="cwax.lastmile"]/arguments/argument[@name="category_view"]');
        $this->assertCount(1, $args);
        $this->assertSame('Cwax\Banner\ViewModel\CategoryView', trim((string) $args[0]));

        $template = (string) file_get_contents($root . '/view/frontend/templates/lastmile.phtml');
        $this->assertStringContainsString("getData('category_view')", $template);
    }
}
