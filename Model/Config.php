<?php

declare(strict_types=1);

namespace Cwax\Banner\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Store\Model\ScopeInterface;

class Config
{
    private const XML_PATH_ENABLED = 'conversionwax_lastmile/connection/enabled';
    private const XML_PATH_WEB_PROPERTY_ID = 'conversionwax_lastmile/connection/web_property_id';
    private const XML_PATH_API_TOKEN = 'conversionwax_lastmile/connection/api_token';
    private const XML_PATH_ENDPOINT = 'conversionwax_lastmile/connection/endpoint';
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

    public function getEndpoint(int $storeId): string
    {
        return rtrim(trim((string) $this->scopeConfig->getValue(
            self::XML_PATH_ENDPOINT,
            ScopeInterface::SCOPE_STORE,
            $storeId
        )), '/');
    }

    /**
     * The collector session endpoint lives on the same (allowlisted) host as the configured events endpoint.
     *
     * @param int $storeId
     * @return string
     */
    public function getSessionUrl(int $storeId): string
    {
        $endpoint = $this->getEndpoint($storeId);
        if (!$this->isAllowedEndpoint($endpoint)) {
            return '';
        }
        $parts = parse_url($endpoint);
        if (!is_array($parts) || ($parts['host'] ?? '') === '') {
            return '';
        }

        return 'https://' . strtolower($parts['host'])
            . (isset($parts['port']) ? ':' . $parts['port'] : '')
            . '/v1/session';
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
            && $this->getApiToken($storeId) !== ''
            && $this->isAllowedEndpoint($this->getEndpoint($storeId));
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

    /**
     * Restrict the admin-set endpoint to the ConversionWax collector so the
     * consumer cannot be pointed at an arbitrary internal or external host.
     */
    private function isAllowedEndpoint(string $endpoint): bool
    {
        if (!str_starts_with($endpoint, 'https://')) {
            return false;
        }

        $host = parse_url($endpoint, PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            return false;
        }
        $host = strtolower($host);

        return $host === 'collect.conversionwax.com' || str_ends_with($host, '.conversionwax.com');
    }
}
