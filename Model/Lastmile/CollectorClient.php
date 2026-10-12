<?php

declare(strict_types=1);

namespace Cwax\Banner\Model\Lastmile;

use Cwax\Banner\Model\Config;
use Magento\Framework\HTTP\Client\CurlFactory;
use Psr\Log\LoggerInterface;

/**
 * Synchronous mint call to the collector session endpoint. This runs inside the cwax-visitor section
 * request (an AJAX call, never a page render), so it has a hard 300 ms budget and fails closed.
 */
class CollectorClient
{
    private const CONNECT_TIMEOUT_MS = 200;
    private const TOTAL_TIMEOUT_MS = 300;
    // Caps at the collector's accepted length (1024); it ignores a longer device.ua.
    private const MAX_USER_AGENT_LENGTH = 1024;

    public function __construct(
        private readonly CurlFactory $curlFactory,
        private readonly Config $config,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * The URL comes from Config::getSessionUrl, which only returns an allowlisted collector host.
     *
     * @param int $storeId
     * @param array<string, mixed> $body SessionRequestBody
     * @param string|null $clientIp
     * @param string $userAgent
     * @return array<string, mixed>|null SessionResponseBody, or null on any failure
     */
    public function session(int $storeId, array $body, ?string $clientIp, string $userAgent): ?array
    {
        $url = $this->config->getSessionUrl($storeId);
        if ($url === '') {
            return null;
        }

        try {
            // Mint calls make the collector write no rows. The collector parses the raw user agent itself.
            $body['mintOnly'] = true;
            unset($body['claimsOnly']);
            $device = is_array($body['device'] ?? null) ? $body['device'] : [];
            unset($device['deviceType'], $device['ua']);
            if ($userAgent !== '') {
                $device['ua'] = substr($userAgent, 0, self::MAX_USER_AGENT_LENGTH);
            }
            if ($device === []) {
                unset($body['device']);
            } else {
                $body['device'] = $device;
            }

            $curl = $this->curlFactory->create();
            $curl->setOption(CURLOPT_CONNECTTIMEOUT_MS, self::CONNECT_TIMEOUT_MS);
            $curl->setOption(CURLOPT_TIMEOUT_MS, self::TOTAL_TIMEOUT_MS);
            $curl->setOption(CURLOPT_NOSIGNAL, 1);
            $curl->addHeader('Content-Type', 'application/json');
            if ($clientIp !== null && $clientIp !== '') {
                // The collector derives geo from the first X-Forwarded-For hop and never stores the IP.
                $curl->addHeader('X-Forwarded-For', $clientIp);
            }
            // Without a valid token the collector never looks up customer purchase history.
            $token = $this->config->getApiToken($storeId);
            if ($token !== '') {
                $curl->addHeader('Authorization', 'Bearer ' . $token);
            }
            $curl->post($url, json_encode($body, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
            $status = $curl->getStatus();
            if ($status === 401) {
                $this->logger->warning(
                    'ConversionWax Last Mile collector rejected the API token (HTTP 401). '
                    . 'Check the API token in the module configuration.',
                    ['store_id' => $storeId]
                );
                return null;
            }
            if ($status !== 200) {
                return null;
            }
            $decoded = json_decode((string) $curl->getBody(), true, 16);
        } catch (\Throwable $exception) {
            // Never log the request body or headers: they hold the visitor id, the summary and the token.
            $this->logger->info('ConversionWax Last Mile session sync failed.', [
                'reason' => $exception->getMessage(),
            ]);
            return null;
        }

        return is_array($decoded) && is_string($decoded['summary'] ?? null) ? $decoded : null;
    }
}
