<?php

declare(strict_types=1);

namespace Cwax\Banner\Test\Unit\Setup\Patch\Data;

use Cwax\Banner\Setup\Patch\Data\MigrateHyvaSupportSetting;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use PHPUnit\Framework\TestCase;

class MigrateHyvaSupportSettingTest extends TestCase
{
    private const HYVA = 'conversionwax_lastmile/connection/hyva_enabled';
    private const SNIPPET = 'conversionwax_lastmile/connection/storefront_snippet';
    private const THEME = 'design/theme/theme_id';

    // Store views 1 and 2 on website 1, 3 on website 2.
    private const STORES = [
        ['store_id' => 1, 'website_id' => 1],
        ['store_id' => 2, 'website_id' => 1],
        ['store_id' => 3, 'website_id' => 2],
    ];

    // 3 Magento/luma, 4 Hyva/default, 5 a child of Hyva/default, 6 a child of Luma.
    private const THEMES = [
        ['theme_id' => 1, 'parent_id' => null, 'theme_path' => 'Magento/blank'],
        ['theme_id' => 3, 'parent_id' => 1, 'theme_path' => 'Magento/luma'],
        ['theme_id' => 4, 'parent_id' => null, 'theme_path' => 'Hyva/default'],
        ['theme_id' => 5, 'parent_id' => 4, 'theme_path' => 'OrangeCollar/default'],
        ['theme_id' => 6, 'parent_id' => 3, 'theme_path' => 'Acme/luma'],
    ];

    /**
     * @param array<int, array{0: string, 1: int, 2: string, 3: string}> $rows scope, scope id, path, value
     * @return int[]
     */
    private function plan(array $rows): array
    {
        $patch = new MigrateHyvaSupportSetting($this->createStub(ModuleDataSetupInterface::class));
        $configRows = array_map(
            static fn (array $row): array => [
                'scope' => $row[0], 'scope_id' => $row[1], 'path' => $row[2], 'value' => $row[3],
            ],
            $rows
        );

        return $patch->storesToSwitchOff($configRows, self::STORES, self::THEMES);
    }

    public function testADefaultScopeNoSwitchesOffOnlyTheHyvaStoreViews(): void
    {
        $this->assertSame([2, 3], $this->plan([
            ['default', 0, self::HYVA, '0'],
            ['default', 0, self::THEME, '3'],
            ['stores', 2, self::THEME, '4'],
            ['websites', 2, self::THEME, '5'],
        ]));
    }

    public function testAStoreViewNoAppliesToThatStoreViewOnly(): void
    {
        $this->assertSame([2], $this->plan([
            ['default', 0, self::THEME, '4'],
            ['stores', 2, self::HYVA, '0'],
        ]));
    }

    public function testAMoreSpecificYesWins(): void
    {
        $this->assertSame([1], $this->plan([
            ['default', 0, self::THEME, '4'],
            ['websites', 1, self::HYVA, '0'],
            ['stores', 2, self::HYVA, '1'],
        ]));
    }

    public function testLumaStoreViewsAreNeverSwitchedOff(): void
    {
        $this->assertSame([], $this->plan([
            ['default', 0, self::HYVA, '0'],
            ['default', 0, self::THEME, '6'],
        ]));
    }

    public function testTheDefaultYesNeedsNoMigration(): void
    {
        $this->assertSame([], $this->plan([['default', 0, self::THEME, '4']]));
    }

    public function testAStoreViewWithItsOwnNewValueIsLeftAlone(): void
    {
        $this->assertSame([1, 3], $this->plan([
            ['default', 0, self::HYVA, '0'],
            ['default', 0, self::THEME, '4'],
            ['stores', 2, self::SNIPPET, '1'],
        ]));
    }

    public function testAnUnknownThemeIsNotTreatedAsHyva(): void
    {
        $this->assertSame([], $this->plan([
            ['default', 0, self::HYVA, '0'],
            ['default', 0, self::THEME, '99'],
        ]));
    }
}
