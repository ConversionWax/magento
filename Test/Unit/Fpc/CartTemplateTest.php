<?php

declare(strict_types=1);

namespace Cwax\Banner\Test\Unit\Fpc;

use Cwax\Banner\ViewModel\CartData;
use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Helper\SecureHtmlRenderer;
use PHPUnit\Framework\TestCase;

/**
 * Renders view/frontend/templates/cart.phtml the way Magento would. The page is cached for every
 * visitor, so the HTML may hold the store currency but never a cart value.
 */
class CartTemplateTest extends TestCase
{
    private const TEMPLATE = __DIR__ . '/../../../view/frontend/templates/cart.phtml';

    private function render(string $currency = 'USD'): string
    {
        $viewModel = $this->createStub(CartData::class);
        $viewModel->method('getCurrencyCode')->willReturn($currency);
        $block = $this->createStub(Template::class);
        $block->method('getData')->willReturnCallback(
            static fn (string $key) => ['view_model' => $viewModel][$key] ?? null
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

        return $render(self::TEMPLATE);
    }

    public function testOneInlineScriptWithTheStoreCurrency(): void
    {
        $html = $this->render('EUR');

        $this->assertSame(1, substr_count($html, '<script'));
        $this->assertStringStartsWith('<script>(function(w,d){', trim($html));
        $this->assertStringContainsString('var currency="EUR";', $html);
        $this->assertStringNotContainsString('__', $html);
    }

    public function testNothingIsRenderedWithoutAValidCurrencyCode(): void
    {
        foreach (['', 'usd', 'US', 'USDX', '</script>'] as $currency) {
            $this->assertSame('', trim($this->render($currency)), $currency);
        }
    }

    public function testTheCartComesFromPrivateContentInTheBrowserOnly(): void
    {
        $template = (string) file_get_contents(self::TEMPLATE);

        $this->assertStringContainsString("'Magento_Customer/js/customer-data'", $template);
        $this->assertStringContainsString("'private-content-loaded'", $template);
        foreach (['getQuote', 'CheckoutSession', 'Checkout\Model\Session', 'CustomerData\Cart', '$_COOKIE',
            'customer/section/load', 'fetch(', 'XMLHttpRequest', 'sendBeacon', '$.ajax', 'localStorage',
            'document.cookie'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $template);
        }
    }
}
