<?php

declare(strict_types=1);

namespace Cwax\Banner\Model;

use Cwax\Banner\Model\Config\Source\Environment;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Store\Model\ScopeInterface;

class Config
{
    private const XML_PATH_ENABLED = 'conversionwax_lastmile/connection/enabled';
    private const XML_PATH_WEB_PROPERTY_ID = 'conversionwax_lastmile/connection/web_property_id';
    private const XML_PATH_API_TOKEN = 'conversionwax_lastmile/connection/api_token';
    private const XML_PATH_ENVIRONMENT = 'conversionwax_lastmile/connection/environment';
    private const EVENTS_PATH = '/v1/integrations/events';
    private const SESSION_PATH = '/v1/session';
    private const XML_PATH_HYVA_ENABLED = 'conversionwax_lastmile/connection/hyva_enabled';
    private const XML_PATH_SNIPPET_INSTALLED = 'conversionwax_lastmile/consent/snippet_installed';
    private const XML_PATH_CONSENT_FALLBACK_POLICY = 'conversionwax_lastmile/consent/fallback_policy';

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

    public function getWebPropertyId(int $storeId): string
    {
        return trim((string) $this->scopeConfig->getValue(
            self::XML_PATH_WEB_PROPERTY_ID,
            ScopeInterface::SCOPE_STORE,
            $storeId
        ));
    }

    public function isHyvaEnabled(int $storeId): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_HYVA_ENABLED, ScopeInterface::SCOPE_STORE, $storeId);
    }

    /**
     * Collector base URL for the store's selected environment.
     *
     * Only the fixed hosts in Environment::HOSTS can be returned. A missing or unknown value
     * means Production, the default. No merchant-entered URL is ever read.
     *
     * @param int $storeId
     * @return string
     */
    public function getCollectorHost(int $storeId): string
    {
        $environment = trim((string) $this->scopeConfig->getValue(
            self::XML_PATH_ENVIRONMENT,
            ScopeInterface::SCOPE_STORE,
            $storeId
        ));

        return Environment::HOSTS[$environment] ?? Environment::HOSTS[Environment::PRODUCTION];
    }

    public function getEventsUrl(int $storeId): string
    {
        return $this->getCollectorHost($storeId) . self::EVENTS_PATH;
    }

    public function getSessionUrl(int $storeId): string
    {
        return $this->getCollectorHost($storeId) . self::SESSION_PATH;
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

        // If decryption fails (crypt key rotation, DB restored into another
        // env) the ciphertext must never be treated as a usable token.
        return $this->encryptor->decrypt($stored);
    }

    public function isConfigured(int $storeId): bool
    {
        return $this->isEnabled($storeId)
            && preg_match(
                '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
                $this->getWebPropertyId($storeId)
            ) === 1
            && $this->getApiToken($storeId) !== '';
    }

    public function isSnippetInstalled(int $storeId): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_SNIPPET_INSTALLED,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    public function getConsentFallbackPolicy(int $storeId): string
    {
        $value = trim((string) $this->scopeConfig->getValue(
            self::XML_PATH_CONSENT_FALLBACK_POLICY,
            ScopeInterface::SCOPE_STORE,
            $storeId
        ));

        return in_array($value, [
            \Cwax\Banner\Model\Config\Source\ConsentFallbackPolicy::DENY,
            \Cwax\Banner\Model\Config\Source\ConsentFallbackPolicy::GRANT,
            \Cwax\Banner\Model\Config\Source\ConsentFallbackPolicy::MAGENTO_COOKIE,
        ], true) ? $value : \Cwax\Banner\Model\Config\Source\ConsentFallbackPolicy::DENY;
    }
}
