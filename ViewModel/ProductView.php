<?php

declare(strict_types=1);

namespace Cwax\Banner\ViewModel;

use Cwax\Banner\Model\Config;
use Magento\Catalog\Helper\Data as CatalogHelper;
use Magento\Framework\View\Element\Block\ArgumentInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * The SKU of the product page being rendered. The same for every visitor of the page (full-page cache), so
 * it is safe in cached HTML. lm.js reports the product_view with it (cw.push(['context', ...]), the same
 * productId the server events use), and the cwax-product meta tag exposes it to any other front end.
 */
class ProductView implements ArgumentInterface
{
    public function __construct(
        private readonly Config $config,
        private readonly CatalogHelper $catalogHelper,
        private readonly StoreManagerInterface $storeManager
    ) {
    }

    public function getSku(): string
    {
        if (!$this->config->isConfigured((int) $this->storeManager->getStore()->getId())) {
            return '';
        }
        $product = $this->catalogHelper->getProduct();

        return $product === null ? '' : (string) $product->getSku();
    }
}
