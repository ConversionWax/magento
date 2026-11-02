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
    private const CONFIG_PATH = '/v1/integrations/config';

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

        $mode = $this->fetchFromCollector($storeId);
        $this->cache->save(
            $mode ?? self::UNRESOLVED_SENTINEL,
            $cacheKey,
            [ConsentConfigCacheType::CACHE_TAG],
            $mode !== null ? self::SUCCESS_CACHE_LIFETIME : self::NEGATIVE_CACHE_LIFETIME
        );
        $this->memo[$webPropertyId] = $mode;

        return $mode;
    }

    private function fetchFromCollector(int $storeId): ?string
    {
        $token = $this->config->getApiToken($storeId);
        if ($token === '') {
            return null;
        }

        $curl = $this->curlFactory->create();
        $curl->setOption(CURLOPT_CONNECTTIMEOUT, self::CONNECT_TIMEOUT_SECONDS);
        $curl->setOption(CURLOPT_TIMEOUT, self::REQUEST_TIMEOUT_SECONDS);
        $curl->setOption(CURLOPT_SSL_VERIFYPEER, true);
        $curl->setOption(CURLOPT_SSL_VERIFYHOST, 2);
        $curl->setOption(CURLOPT_FOLLOWLOCATION, false);
        // The connection token identifies the install, so the URL carries no ID.
        $curl->addHeader('Authorization', 'Bearer ' . $token);
        $curl->get($this->config->getCollectorHost($storeId) . self::CONFIG_PATH);

        if ($curl->getStatus() !== 200) {
            // Any non-200 is "no config". The collector answers 401 for an
            // unknown or inactive token and for a disabled website. A
            // 200 with disabled:true is still a valid config with a real
            // consentMode and is not affected by this branch.
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

    private function isUuid(string $value): bool
    {
        return preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
            $value
        ) === 1;
    }
}
