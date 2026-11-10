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
    private const XML_PATH_INTEGRATION_ID = 'conversionwax_lastmile/connection/integration_id';
    private const XML_PATH_STOREFRONT_SNIPPET = 'conversionwax_lastmile/connection/storefront_snippet';
    private const UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

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

    /**
     * The install's integration id from ConversionWax (Connect Magento step), or '' when it is unset or not a
     * UUID. lm.js tracks nothing without it.
     *
     * @param int $storeId
     * @return string
     */
    public function getIntegrationId(int $storeId): string
    {
        $value = trim((string) $this->scopeConfig->getValue(
            self::XML_PATH_INTEGRATION_ID,
            ScopeInterface::SCOPE_STORE,
            $storeId
        ));

        return preg_match(self::UUID_PATTERN, $value) === 1 ? $value : '';
    }

    /**
     * "Load Last Mile on Magento storefront". No means another front end runs its own lm.js, so the
     * module adds no tag and no browser script. Server commerce events do not depend on it.
     *
     * @param int $storeId
     * @return bool
     */
    public function isStorefrontSnippetEnabled(int $storeId): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_STOREFRONT_SNIPPET,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * True when the module should put the lm.js tag on this store view's pages.
     *
     * @param int $storeId
     * @return bool
     */
    public function shouldLoadSnippet(int $storeId): bool
    {
        return $this->isConfigured($storeId)
            && $this->isStorefrontSnippetEnabled($storeId)
            && $this->getIntegrationId($storeId) !== '';
    }

    /**
     * The lm.js URL for the store's selected environment. Like the collector host, it is fixed in code.
     *
     * @param int $storeId
     * @return string
     */
    public function getSnippetUrl(int $storeId): string
    {
        return Environment::SNIPPET_URLS[$this->getEnvironment($storeId)]
            ?? Environment::SNIPPET_URLS[Environment::PRODUCTION];
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
        return Environment::HOSTS[$this->getEnvironment($storeId)] ?? Environment::HOSTS[Environment::PRODUCTION];
    }

    private function getEnvironment(int $storeId): string
    {
        return trim((string) $this->scopeConfig->getValue(
            self::XML_PATH_ENVIRONMENT,
            ScopeInterface::SCOPE_STORE,
            $storeId
        ));
    }

    public function getEventsUrl(int $storeId): string
    {
        return $this->getCollectorHost($storeId) . self::EVENTS_PATH;
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
            && preg_match(self::UUID_PATTERN, $this->getWebPropertyId($storeId)) === 1
            && $this->getApiToken($storeId) !== '';
    }
}
