<?php

declare(strict_types=1);

namespace Cwax\Banner\Model\Lastmile;

use Cwax\Banner\Model\Config;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Builds the emailHash field for the Last Mile wire contract.
 *
 * HMAC-SHA256 the lowercased, trimmed email keyed on the store's API token,
 * instead of a bare sha256(email), which is trivially reversed by a rainbow
 * table since email addresses are low entropy. Never throws: any failure to
 * resolve the store or token simply yields no hash.
 */
class EmailHasher
{
    public function __construct(
        private readonly Config $config,
        private readonly StoreManagerInterface $storeManager
    ) {
    }

    public function hash(?string $email): ?string
    {
        $normalized = strtolower(trim((string) $email));
        if ($normalized === '') {
            return null;
        }

        try {
            $storeId = (int) $this->storeManager->getStore()->getId();
            $token = $this->config->getApiToken($storeId);
        } catch (\Throwable) {
            return null;
        }
        if ($token === '') {
            return null;
        }

        return hash_hmac('sha256', $normalized, $token);
    }
}
