<?php

declare(strict_types=1);

namespace Cwax\Banner\Test\Unit\Fpc;

use Cwax\Banner\ViewModel\LastmileSnippet;
use Cwax\Banner\ViewModel\ProductView;
use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Helper\SecureHtmlRenderer;
use PHPUnit\Framework\TestCase;

/**
 * Renders view/frontend/templates/lastmile.phtml the way Magento would, with the template's variables.
 */
class LastmileTemplateTest extends TestCase
{
    private const WEBSITE_ID = '0f8fad5b-d9cb-469f-a165-70867728950e';
    private const INTEGRATION_ID = '7c9e6679-7425-40de-944b-e07fc1f90ae7';

    private function render(bool $enabled = true, bool $restrict = false, ?string $sku = null): string
    {
        $viewModel = $this->createStub(LastmileSnippet::class);
        $viewModel->method('isEnabled')->willReturn($enabled);
        $viewModel->method('respectsRestriction')->willReturn($restrict);
        $viewModel->method('getSnippetUrl')->willReturn('https://assets.conversionwax.com/lastmile/v1/lm.js');
        $viewModel->method('getWebsiteId')->willReturn(self::WEBSITE_ID);
        $viewModel->method('getIntegrationId')->willReturn(self::INTEGRATION_ID);
        $productView = null;
        if ($sku !== null) {
            $productView = $this->createStub(ProductView::class);
            $productView->method('getSku')->willReturn($sku);
        }
        $block = $this->createStub(Template::class);
        $block->method('getData')->willReturnCallback(
            static fn (string $key) => ['view_model' => $viewModel, 'product_view' => $productView][$key] ?? null
        );
        $secureRenderer = $this->createStub(SecureHtmlRenderer::class);
        $secureRenderer->method('renderTag')->willReturnCallback(
            static fn (string $tag, array $attributes, string $content): string => "<$tag>$content</$tag>"
        );

        $render = static function (string $file) use ($block, $secureRenderer): string {
            ob_start();
            include $file;
            return (string) ob_get_clean();
        };

        return $render(dirname(__DIR__, 3) . '/view/frontend/templates/lastmile.phtml');
    }

    public function testNothingIsRenderedWhenTheSnippetIsOff(): void
    {
        $this->assertSame('', trim($this->render(false)));
    }

    public function testOnlyTheInlineScriptIsRenderedAndNoStaticLmJsTag(): void
    {
        $html = $this->render();

        $this->assertSame(1, substr_count($html, '<script'));
        $this->assertStringStartsWith('<script>(function(w,d){', trim($html));
        $this->assertDoesNotMatchRegularExpression('/<script[^>]*\ssrc=/', $html);
    }

    public function testTheTagValuesArePassedAsJson(): void
    {
        $html = $this->render();

        $this->assertStringContainsString(
            'var tag={"src":"https://assets.conversionwax.com/lastmile/v1/lm.js","siteId":"' . self::WEBSITE_ID
                . '","integrationId":"' . self::INTEGRATION_ID . '"};',
            $html
        );
        $this->assertStringContainsString("'data-cwax-gpc','honor'", $html);
    }

    public function testTheStoreSettingsAreFilledIn(): void
    {
        $this->assertStringContainsString('var restriction=true;', $this->render(true, true));
        $this->assertStringContainsString('var restriction=false;', $this->render());
        $this->assertStringContainsString('var context=null;', $this->render());
        $this->assertStringNotContainsString('__', $this->render());
    }

    public function testAProductPageNamesItsSku(): void
    {
        $html = $this->render(sku: 'MH01-XS-Black');

        $this->assertStringContainsString(
            'var context={"productId":"MH01-XS-Black","pageType":"product","platform":"magento"};',
            $html
        );
    }

    public function testASkuCannotBreakOutOfTheScript(): void
    {
        $html = $this->render(sku: '</script><script>alert(1)</script>\'"&');

        $this->assertSame(1, substr_count($html, '</script>'));
        $this->assertStringContainsString('\u003C/script\u003E', $html);
    }
}
