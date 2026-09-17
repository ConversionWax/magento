<?php

declare(strict_types=1);

namespace Cwax\Banner\Model\Queue;

use Cwax\Banner\Model\Config;
use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\HTTP\Client\CurlFactory;
use Psr\Log\LoggerInterface;

class EventConsumer
{
    private const MAX_ATTEMPTS = 8;
    // 401 is retryable because collector credential caches can briefly hold
    // the prior digest immediately after a deliberate token rotation, but
    // that grace window is short: cap it at one retry so a revoked token
    // cannot burn the full attempt budget and head-of-line-block the queue.
    private const MAX_ATTEMPTS_401 = 2;
    private const TRANSIENT_STATUS = [401, 408, 425, 429, 500, 502, 503, 504];

    public function __construct(
        private readonly Config $config,
        private readonly MessagePublisher $publisher,
        private readonly CurlFactory $curlFactory,
        private readonly LoggerInterface $logger
    ) {
    }

    public function process(string $encodedMessage): void
    {
        $message = $this->decode($encodedMessage);
        $storeId = (int) $message['storeViewId'];
        $eventId = (string) $message['eventId'];

        // The retry delay is enforced by this timestamp rather than a
        // blocking sleep. Republish untouched, without counting an attempt,
        // until the delay has elapsed.
        $retryAfter = $message['retryAfter'] ?? null;
        if (is_int($retryAfter) && $retryAfter > $this->nowMs()) {
            $this->publisher->publishEvent($message);
            return;
        }

        if (!$this->config->isConfigured($storeId)) {
            $this->deadLetter($message, 'integration_not_configured');
            return;
        }

        try {
            $body = json_encode(
                $message['payload'],
                JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
            );
            // A fresh client per message: Curl is otherwise a shared
            // singleton and options/response cookies would leak across
            // every message in the batch.
            $curl = $this->curlFactory->create();
            $curl->setOption(CURLOPT_CONNECTTIMEOUT, 3);
            $curl->setOption(CURLOPT_TIMEOUT, 10);
            $curl->setOption(CURLOPT_SSL_VERIFYPEER, true);
            $curl->setOption(CURLOPT_SSL_VERIFYHOST, 2);
            $curl->setOption(CURLOPT_FOLLOWLOCATION, false);
            $curl->addHeader('Authorization', 'Bearer ' . $this->config->getApiToken($storeId));
            $curl->addHeader('Content-Type', 'application/json');
            $curl->addHeader('User-Agent', 'ConversionWax-Magento/0.2.0');
            $curl->post($this->config->getEndpoint($storeId), $body);
            $status = $curl->getStatus();
        } catch (\Throwable $exception) {
            if ($this->retry($message, 'network_error', self::MAX_ATTEMPTS)) {
                $this->logger->warning('ConversionWax Last Mile delivery failed and was requeued.', [
                    'event_id' => $eventId,
                    'attempt' => ((int) $message['attempt']) + 1,
                    'exception' => $exception,
                ]);
            }
            return;
        }

        if ($status >= 200 && $status < 300) {
            if ($status === 204 && $this->isDisabledPropertyResponse($curl)) {
                $this->logger->warning(
                    'ConversionWax Last Mile event was dropped because the web property is disabled.',
                    [
                        'event_id' => $eventId,
                        'web_property_id' => $this->config->getWebPropertyId($storeId),
                    ]
                );
            }
            return;
        }
        if (in_array($status, self::TRANSIENT_STATUS, true)) {
            $maxAttempts = $status === 401 ? self::MAX_ATTEMPTS_401 : self::MAX_ATTEMPTS;
            $this->retry($message, 'http_' . $status, $maxAttempts);
            return;
        }

        $this->deadLetter($message, 'http_' . $status);
    }

    /**
     * The collector responds 204 with this header when the web property is
     * disabled, so the event was intentionally not accepted rather than
     * successfully delivered.
     */
    private function isDisabledPropertyResponse(Curl $curl): bool
    {
        foreach ($curl->getHeaders() as $name => $value) {
            if (strtolower((string) $name) === 'x-lastmile-accepted') {
                return trim((string) $value) === '0';
            }
        }
        return false;
    }

    private function nowMs(): int
    {
        return (int) round(microtime(true) * 1000);
    }

    /** @return array<string, mixed> */
    private function decode(string $encodedMessage): array
    {
        $message = json_decode($encodedMessage, true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($message)
            || ($message['version'] ?? null) !== 1
            || !is_int($message['attempt'] ?? null)
            || !is_numeric($message['storeViewId'] ?? null)
            || !is_string($message['eventId'] ?? null)
            || !is_array($message['payload'] ?? null)
        ) {
            throw new \InvalidArgumentException('Malformed ConversionWax Last Mile queue message.');
        }
        return $message;
    }

    /** @param array<string, mixed> $message */
    private function retry(array $message, string $reason, int $maxAttempts): bool
    {
        $nextAttempt = ((int) $message['attempt']) + 1;
        if ($nextAttempt >= $maxAttempts) {
            $this->deadLetter($message, 'retry_exhausted_' . $reason);
            return false;
        }

        // The backoff delay is carried on the message as a retryAfter
        // timestamp instead of blocking the consumer with sleep(). This
        // never blocks a storefront or checkout request, and it never
        // head-of-line-blocks the rest of the batch either.
        $delaySeconds = min(30, 2 ** max(0, $nextAttempt - 1));
        $message['attempt'] = $nextAttempt;
        $message['lastFailure'] = $reason;
        $message['retryAfter'] = $this->nowMs() + $delaySeconds * 1000;
        $this->publisher->publishEvent($message);
        return true;
    }

    /** @param array<string, mixed> $message */
    private function deadLetter(array $message, string $reason): void
    {
        $message['failedAt'] = gmdate('c');
        $message['failureReason'] = $reason;
        $this->publisher->publishFailed($message);
        $this->logger->error('ConversionWax Last Mile event moved to the failed queue.', [
            'event_id' => $message['eventId'] ?? null,
            'attempt' => $message['attempt'] ?? null,
            'reason' => $reason,
        ]);
    }
}
