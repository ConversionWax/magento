<?php

declare(strict_types=1);

namespace Cwax\Banner\Test\Unit\Fpc;

use PHPUnit\Framework\TestCase;

/**
 * The lm.js block is plain JavaScript, so one default.xml block serves Luma and Hyva alike.
 */
class LayoutTest extends TestCase
{
    private const LAYOUT = __DIR__ . '/../../../view/frontend/layout/';

    public function testEveryStorefrontPageGetsTheLmJsBlockInTheHead(): void
    {
        $layout = simplexml_load_file(self::LAYOUT . 'default.xml');
        $block = $layout->xpath('//referenceContainer[@name="head.additional"]/block[@name="cwax.lastmile"]');

        $this->assertCount(1, $block);
        $this->assertSame('Cwax_Banner::lastmile.phtml', (string) $block[0]['template']);
    }

    public function testNoHyvaSpecificLayoutIsNeeded(): void
    {
        $this->assertFileDoesNotExist(self::LAYOUT . 'hyva_default.xml');
    }

    public function testTheBannerEmbedIsStillLoaded(): void
    {
        $layout = (string) file_get_contents(self::LAYOUT . 'default.xml');

        $this->assertStringContainsString('https://assets.conversionwax.com/dist/app.js', $layout);
    }

    public function testAProductPageHandsItsSkuToTheLmJsBlock(): void
    {
        $layout = simplexml_load_file(self::LAYOUT . 'catalog_product_view.xml');
        $argument = $layout->xpath('//referenceBlock[@name="cwax.lastmile"]/arguments/argument[@name="product_view"]');

        $this->assertCount(1, $argument);
        $this->assertSame('Cwax\Banner\ViewModel\ProductView', trim((string) $argument[0]));
    }
}
