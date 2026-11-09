<?php

declare(strict_types=1);

namespace Cwax\Banner\Setup\Patch\Data;

use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;

/**
 * 0.5.0 replaces "Enable Hyva Support" (connection/hyva_enabled, Hyva store views only) with "Load Last Mile
 * on Magento storefront" (connection/storefront_snippet, every theme). A store view on a Hyva theme whose
 * effective hyva_enabled was No gets storefront_snippet = No at its own store-view scope, so it stays off. A
 * Luma store view never read hyva_enabled, so it is left alone and loads lm.js by default. The retired
 * settings (hyva_enabled, consent/snippet_installed, consent/fallback_policy) are then deleted.
 */
class MigrateHyvaSupportSetting implements DataPatchInterface
{
    public const OLD_PATH = 'conversionwax_lastmile/connection/hyva_enabled';
    public const NEW_PATH = 'conversionwax_lastmile/connection/storefront_snippet';
    public const THEME_PATH = 'design/theme/theme_id';
    private const RETIRED_PATHS = [
        self::OLD_PATH,
        'conversionwax_lastmile/consent/snippet_installed',
        'conversionwax_lastmile/consent/fallback_policy',
    ];
    // hyva_enabled defaulted to Yes in config.xml.
    private const OLD_DEFAULT = '1';

    public function __construct(
        private readonly ModuleDataSetupInterface $moduleDataSetup
    ) {
    }

    /**
     * @inheritDoc
     */
    public function apply()
    {
        $connection = $this->moduleDataSetup->getConnection();
        $configTable = $this->moduleDataSetup->getTable('core_config_data');

        $configRows = $connection->fetchAll(
            $connection->select()
                ->from($configTable, ['scope', 'scope_id', 'path', 'value'])
                ->where('path IN (?)', [self::OLD_PATH, self::NEW_PATH, self::THEME_PATH])
        );
        $stores = $connection->fetchAll(
            $connection->select()
                ->from($this->moduleDataSetup->getTable('store'), ['store_id', 'website_id'])
                ->where('store_id > ?', 0)
        );
        $themes = $connection->fetchAll(
            $connection->select()->from(
                $this->moduleDataSetup->getTable('theme'),
                ['theme_id', 'parent_id', 'theme_path']
            )
        );

        foreach ($this->storesToSwitchOff($configRows, $stores, $themes) as $storeId) {
            $connection->insertOnDuplicate(
                $configTable,
                ['scope' => 'stores', 'scope_id' => $storeId, 'path' => self::NEW_PATH, 'value' => '0'],
                ['value']
            );
        }
        $connection->delete($configTable, ['path IN (?)' => self::RETIRED_PATHS]);

        return $this;
    }

    /**
     * Store views on a Hyva theme (the theme or any parent under Hyva/) whose effective hyva_enabled is No
     * and that have no storefront_snippet value of their own yet.
     *
     * @param array<int, array<string, mixed>> $configRows core_config_data rows: scope, scope_id, path, value
     * @param array<int, array<string, mixed>> $stores store rows: store_id, website_id
     * @param array<int, array<string, mixed>> $themes theme rows: theme_id, parent_id, theme_path
     * @return int[]
     */
    public function storesToSwitchOff(array $configRows, array $stores, array $themes): array
    {
        $values = [];
        foreach ($configRows as $row) {
            $values[(string) $row['path']][(string) $row['scope']][(int) $row['scope_id']] = $row['value'];
        }
        $themesById = [];
        foreach ($themes as $theme) {
            $themesById[(int) $theme['theme_id']] = $theme;
        }

        $result = [];
        foreach ($stores as $store) {
            $storeId = (int) $store['store_id'];
            $websiteId = (int) $store['website_id'];
            if (isset($values[self::NEW_PATH]['stores'][$storeId])) {
                continue;
            }
            $hyvaEnabled = $this->effective($values[self::OLD_PATH] ?? [], $storeId, $websiteId)
                ?? self::OLD_DEFAULT;
            if ((string) $hyvaEnabled !== '0') {
                continue;
            }
            $themeId = $this->effective($values[self::THEME_PATH] ?? [], $storeId, $websiteId);
            if ($themeId !== null && $this->isHyvaTheme((int) $themeId, $themesById)) {
                $result[] = $storeId;
            }
        }

        return $result;
    }

    /**
     * Magento's scope fallback: store view, then website, then default.
     *
     * @param array<string, array<int, mixed>> $byScope
     * @param int $storeId
     * @param int $websiteId
     * @return mixed
     */
    private function effective(array $byScope, int $storeId, int $websiteId): mixed
    {
        return $byScope['stores'][$storeId] ?? $byScope['websites'][$websiteId] ?? $byScope['default'][0] ?? null;
    }

    /**
     * @param int $themeId
     * @param array<int, array<string, mixed>> $themesById
     * @return bool
     */
    private function isHyvaTheme(int $themeId, array $themesById): bool
    {
        $seen = [];
        while (isset($themesById[$themeId]) && !isset($seen[$themeId])) {
            $seen[$themeId] = true;
            $theme = $themesById[$themeId];
            if (stripos((string) $theme['theme_path'], 'Hyva/') === 0) {
                return true;
            }
            $themeId = (int) ($theme['parent_id'] ?? 0);
        }

        return false;
    }

    /**
     * @inheritDoc
     */
    public static function getDependencies()
    {
        return [];
    }

    /**
     * @inheritDoc
     */
    public function getAliases()
    {
        return [];
    }
}
