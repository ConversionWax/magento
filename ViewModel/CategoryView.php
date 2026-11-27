<?php

declare(strict_types=1);

namespace Cwax\Banner\ViewModel;

use Cwax\Banner\Model\Config;
use Magento\Catalog\Helper\Data as CatalogHelper;
use Magento\Framework\View\Element\Block\ArgumentInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * The lm.js context of the category page being rendered (CWTASKS-254, the `lc` last viewed category claim).
 * The same for every visitor of the page (full-page cache), so it is safe in cached HTML. The name and path
 * follow the cookie contract: a value that fails the rule is left out, never cut, and the category id is
 * still sent. lm.js sends nothing before consent.
 */
class CategoryView implements ArgumentInterface
{
    private const NAME_MAX = 40;
    private const PATH_MAX = 64;
    private const PATH_PATTERN = "#^/(?![/\\\\])[A-Za-z0-9\\-._~/%!$&'()*+,;=:@?]*\\z#";
    private const NAME_FORBIDDEN_PATTERN = '#[\\x00-\\x1F\\x7F<>{}"\\\\]#';

    public function __construct(
        private readonly Config $config,
        private readonly CatalogHelper $catalogHelper,
        private readonly StoreManagerInterface $storeManager
    ) {
    }

    /**
     * @return array<string, string> empty when this is not a category page or the module is not configured
     */
    public function getContext(): array
    {
        if (!$this->config->isConfigured((int) $this->storeManager->getStore()->getId())) {
            return [];
        }
        $category = $this->catalogHelper->getCategory();
        if ($category === null || (string) $category->getId() === '') {
            return [];
        }
        $context = ['category' => (string) $category->getId()];
        $name = self::cleanName((string) $category->getName());
        $path = self::cleanPath((string) $category->getUrl());
        if ($name !== '' && $path !== '') {
            $context['categoryName'] = $name;
            $context['categoryUrl'] = $path;
        }

        return $context + ['pageType' => 'category', 'platform' => 'magento'];
    }

    /** The name with whitespace folded, or '' when it is not valid. */
    public static function cleanName(string $name): string
    {
        $folded = preg_replace('/\s+/u', ' ', $name);
        if ($folded === null) {
            return '';
        }
        $folded = trim($folded);
        // The collector counts UTF-16 code units (JavaScript length).
        $length = strlen((string) mb_convert_encoding($folded, 'UTF-16LE', 'UTF-8')) / 2;
        if ($length < 1 || $length > self::NAME_MAX || preg_match(self::NAME_FORBIDDEN_PATTERN, $folded) === 1) {
            return '';
        }

        return $folded;
    }

    /** Path and query of the URL, or '' when it is not a valid same-site path. */
    public static function cleanPath(string $url): string
    {
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['path'])) {
            return '';
        }
        $path = $parts['path'] . (isset($parts['query']) ? '?' . $parts['query'] : '');
        if (strlen($path) > self::PATH_MAX || preg_match(self::PATH_PATTERN, $path) !== 1) {
            return '';
        }

        return $path;
    }
}
