<?php

declare(strict_types=1);

namespace Cwax\Banner\Model\Consent;

use Cwax\Banner\Api\StoreConfigProviderInterface;
use Cwax\Banner\Model\Config;
use Magento\Framework\HTTP\Client\CurlFactory;
use Magento\Framework\Serialize\SerializerInterface;
use Psr\Log\LoggerInterface;

class StoreConfigProvider implements StoreConfigProviderInterface
{
    private const CACHE_KEY_PREFIX = 'cwax_banner_consent_mode_';
    private const SUCCESS_CACHE_LIFETIME = 300;
    private const NEGATIVE_CACHE_LIFETIME = 30;
    private const UNRESOLVED_SENTINEL = '__unresolved__';
    private const CONNECT_TIMEOUT_SECONDS = 2;
    private const REQUEST_TIMEOUT_SECONDS = 3;
    private const CONFIG_PATH_TEMPLATE = '/v1/config/%s';

    /** @var array<string, string|null> in-process memo keyed by web property id */
    private array $memo = [];

    public function __construct(
        private readonly Config $config,
        private readonly ConsentConfigCacheType $cache,
        private readonly CurlFactory $curlFactory,
        private readonly SerializerInterface $serializer,
        private readonly LoggerInterface $logger
    ) {
    }

    public function getConsentMode(int $storeId): ?string
    {
        try {
            return $this->fetch($storeId);
        } catch (\Throwable $exception) {
            $this->logger->warning('ConversionWax Last Mile could not resolve store consent mode.', [
                'exception' => $exception,
            ]);
            return null;
        }
    }

    private function fetch(int $storeId): ?string
    {
        $webPropertyId = $this->config->getWebPropertyId($storeId);
        if (!$this->isUuid($webPropertyId)) {
            return null;
        }

        if (array_key_exists($webPropertyId, $this->memo)) {
            return $this->memo[$webPropertyId];
        }

        $cacheKey = self::CACHE_KEY_PREFIX . $webPropertyId;
        $cached = $this->cache->load($cacheKey);
        if ($cached !== false) {
            $mode = $cached === self::UNRESOLVED_SENTINEL ? null : $cached;
            $this->memo[$webPropertyId] = $mode;
            return $mode;
        }

        $mode = $this->fetchFromCollector($storeId, $webPropertyId);
        $this->cache->save(
            $mode ?? self::UNRESOLVED_SENTINEL,
            $cacheKey,
            [ConsentConfigCacheType::CACHE_TAG],
            $mode !== null ? self::SUCCESS_CACHE_LIFETIME : self::NEGATIVE_CACHE_LIFETIME
        );
        $this->memo[$webPropertyId] = $mode;

        return $mode;
    }

    private function fetchFromCollector(int $storeId, string $webPropertyId): ?string
    {
        $host = $this->resolveHost($storeId);
        if ($host === null) {
            return null;
        }

        $curl = $this->curlFactory->create();
        $curl->setOption(CURLOPT_CONNECTTIMEOUT, self::CONNECT_TIMEOUT_SECONDS);
        $curl->setOption(CURLOPT_TIMEOUT, self::REQUEST_TIMEOUT_SECONDS);
        $curl->setOption(CURLOPT_SSL_VERIFYPEER, true);
        $curl->setOption(CURLOPT_SSL_VERIFYHOST, 2);
        $curl->setOption(CURLOPT_FOLLOWLOCATION, false);
        $curl->get($host . sprintf(self::CONFIG_PATH_TEMPLATE, $webPropertyId));

        if ($curl->getStatus() !== 200) {
            // Matches the snippet's own contract: any non-200 is treated as
            // "no config," not just 404 (see discovery: unknown, flag-off,
            // and errored stores all respond 404; the 200/disabled:true
            // case is a valid config with a real consentMode and is not
            // affected by this branch).
            return null;
        }

        try {
            $body = $this->serializer->unserialize($curl->getBody());
        } catch (\Throwable) {
            return null;
        }
        $mode = is_array($body) ? ($body['consentMode'] ?? null) : null;

        return in_array($mode, [
            StoreConfigProviderInterface::CONSENT_MODE_CAPTURE_IMMEDIATELY,
            StoreConfigProviderInterface::CONSENT_MODE_WAIT_FOR_CONSENT,
        ], true) ? $mode : null;
    }

    /**
     * Derives scheme+host from the already-configured, already-validated
     * events endpoint rather than a second admin field: the collector's
     * public config-fetch base URL is explicitly undecided as of this
     * change (discovery notes staging/production hosts are not yet
     * mapped), and reusing the vetted host keeps the admin form the same
     * size. Re-validates the allowlist defensively rather than trusting
     * caller ordering.
     */
    private function resolveHost(int $storeId): ?string
    {
        $endpoint = $this->config->getEndpoint($storeId);
        $parts = parse_url($endpoint);
        if (!isset($parts['scheme'], $parts['host']) || $parts['scheme'] !== 'https') {
            return null;
        }
        $host = strtolower($parts['host']);
        if ($host !== 'collect.conversionwax.com' && !str_ends_with($host, '.conversionwax.com')) {
            return null;
        }

        return $parts['scheme'] . '://' . $host;
    }

    private function isUuid(string $value): bool
    {
        return preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
            $value
        ) === 1;
    }
}
