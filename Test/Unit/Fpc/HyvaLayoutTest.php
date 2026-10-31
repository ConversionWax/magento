<?php

declare(strict_types=1);

namespace Cwax\Banner\Test\Unit\Fpc;

use PHPUnit\Framework\TestCase;

class HyvaLayoutTest extends TestCase
{
    public function testTheHyvaHandleSwapsOnlyTheStartUpTemplate(): void
    {
        $layout = simplexml_load_file(__DIR__ . '/../../../view/frontend/layout/hyva_default.xml');
        $block = $layout->xpath('//referenceBlock[@name="cwax.visitor.sync.init"]');

        $this->assertCount(1, $block);
        $this->assertSame('Cwax_Banner::visitor-sync/hyva-init.phtml', (string) $block[0]['template']);
    }
}
