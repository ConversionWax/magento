<?php

declare(strict_types=1);

namespace Cwax\Banner\ViewModel;

use Magento\Framework\View\Element\Block\ArgumentInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Store-scoped value for the cart script (cart.phtml). Output must be identical for every visitor of a
 * cached page: the current currency code is part of Magento's page cache key, cart values are not used.
 */
class CartData implements ArgumentInterface
{
    /**
     * @param StoreManagerInterface $storeManager
     */
    public function __construct(
        private readonly StoreManagerInterface $storeManager
    ) {
    }

    /**
     * Currency of the quote, and so of the cart section's subtotalAmount: the store view's current currency.
     *
     * @return string
     */
    public function getCurrencyCode(): string
    {
        $store = $this->storeManager->getStore();

        return $store instanceof Store ? (string) $store->getCurrentCurrencyCode() : '';
    }
}
