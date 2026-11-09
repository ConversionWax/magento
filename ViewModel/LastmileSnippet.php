<?php

declare(strict_types=1);

namespace Cwax\Banner\ViewModel;

use Cwax\Banner\Model\Config;
use Magento\Cookie\Helper\Cookie as CookieHelper;
use Magento\Framework\View\Element\Block\ArgumentInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Store-scoped values for the lm.js tag only. Output must be identical for every visitor (full-page cache).
 */
class LastmileSnippet implements ArgumentInterface
{
    public function __construct(
        private readonly Config $config,
        private readonly CookieHelper $cookieHelper,
        private readonly StoreManagerInterface $storeManager
    ) {
    }

    /**
     * Last Mile is configured, "Load Last Mile on Magento storefront" is Yes and the Integration ID is set.
     *
     * @return bool
     */
    public function isEnabled(): bool
    {
        return $this->config->shouldLoadSnippet($this->storeId());
    }

    public function getSnippetUrl(): string
    {
        return $this->config->getSnippetUrl($this->storeId());
    }

    public function getWebsiteId(): string
    {
        return $this->config->getWebPropertyId($this->storeId());
    }

    public function getIntegrationId(): string
    {
        return $this->config->getIntegrationId($this->storeId());
    }

    public function respectsRestriction(): bool
    {
        // Store-level system config only. Never the visitor's own allow/deny choice.
        return (bool) $this->cookieHelper->isCookieRestrictionModeEnabled();
    }

    private function storeId(): int
    {
        return (int) $this->storeManager->getStore()->getId();
    }
}
