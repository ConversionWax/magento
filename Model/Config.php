<?php

declare(strict_types=1);

namespace Cwax\Banner\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Store\Model\ScopeInterface;

class Config
{
    private const XML_PATH_ENABLED = 'conversionwax_lastmile/connection/enabled';
    private const XML_PATH_RESPECT_COOKIE_RESTRICTION =
        'conversionwax_lastmile/connection/respect_cookie_restriction';
    private const XML_PATH_WEB_PROPERTY_ID = 'conversionwax_lastmile/connection/web_property_id';
    private const XML_PATH_API_TOKEN = 'conversionwax_lastmile/connection/api_token';
    private const XML_PATH_ENDPOINT = 'conversionwax_lastmile/connection/endpoint';

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly EncryptorInterface $encryptor
    ) {
    }

    public function isEnabled(int $storeId): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_ENABLED,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    public function respectsCookieRestriction(int $storeId): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_RESPECT_COOKIE_RESTRICTION,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    public function getWebPropertyId(int $storeId): string
    {
        return trim((string) $this->scopeConfig->getValue(
            self::XML_PATH_WEB_PROPERTY_ID,
            ScopeInterface::SCOPE_STORE,
            $storeId
        ));
    }

    public function getEndpoint(int $storeId): string
    {
        return rtrim(trim((string) $this->scopeConfig->getValue(
            self::XML_PATH_ENDPOINT,
            ScopeInterface::SCOPE_STORE,
            $storeId
        )), '/');
    }

    public function getApiToken(int $storeId): string
    {
        $stored = (string) $this->scopeConfig->getValue(
            self::XML_PATH_API_TOKEN,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
        if ($stored === '') {
            return '';
        }

        $decrypted = $this->encryptor->decrypt($stored);
        return $decrypted !== '' ? $decrypted : $stored;
    }

    public function isConfigured(int $storeId): bool
    {
        return $this->isEnabled($storeId)
            && preg_match(
                '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
                $this->getWebPropertyId($storeId)
            ) === 1
            && $this->getApiToken($storeId) !== ''
            && str_starts_with($this->getEndpoint($storeId), 'https://');
    }
}
