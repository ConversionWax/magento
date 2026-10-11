<?php

declare(strict_types=1);

namespace Cwax\Banner\ViewModel;

use Cwax\Banner\Model\Config;
use Magento\Cookie\Helper\Cookie as CookieHelper;
use Magento\Framework\View\Element\Block\ArgumentInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Store-scoped flags only. Output must be identical for every visitor (full-page cache).
 */
class VisitorSync implements ArgumentInterface
{
    public function __construct(
        private readonly Config $config,
        private readonly CookieHelper $cookieHelper,
        private readonly StoreManagerInterface $storeManager
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->config->isConfigured((int) $this->storeManager->getStore()->getId());
    }

    public function respectsRestriction(): bool
    {
        // Store-level system config only. Never the visitor's own allow/deny choice.
        return (bool) $this->cookieHelper->isCookieRestrictionModeEnabled();
    }

    public function getConfigJson(): string
    {
        return json_encode(['restriction' => $this->respectsRestriction()], JSON_THROW_ON_ERROR);
    }
}
