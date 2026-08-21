<?php

declare(strict_types=1);

namespace Cwax\Banner\Model\Queue;

use Cwax\Banner\Model\Config;
use Magento\Framework\HTTP\Client\Curl;
use Psr\Log\LoggerInterface;

class EventConsumer
{
    private const MAX_ATTEMPTS = 8;
    // 401 is retryable because collector credential caches can briefly hold
    // the prior digest immediately after a deliberate token rotation.
    private const TRANSIENT_STATUS = [401, 408, 425, 429, 500, 502, 503, 504];

    public function __construct(
        private readonly Config $config,
        private readonly MessagePublisher $publisher,
        private readonly Curl $curl,
        private readonly LoggerInterface $logger
    ) {
    }

    public function process(string $encodedMessage): void
    {
        $message = $this->decode($encodedMessage);
        $storeId = (int) $message['storeViewId'];
        $eventId = (string) $message['eventId'];

        if (!$this->config->isConfigured($storeId)) {
            $this->deadLetter($message, 'integration_not_configured');
            return;
        }

        try {
            $body = json_encode(
                $message['payload'],
                JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
            );
            $this->curl->setOption(CURLOPT_CONNECTTIMEOUT, 3);
            $this->curl->setOption(CURLOPT_TIMEOUT, 10);
            $this->curl->addHeader('Authorization', 'Bearer ' . $this->config->getApiToken($storeId));
            $this->curl->addHeader('Content-Type', 'application/json');
            $this->curl->addHeader('User-Agent', 'ConversionWax-Magento/0.2.0');
            $this->curl->post($this->config->getEndpoint($storeId), $body);
            $status = $this->curl->getStatus();
        } catch (\Throwable $exception) {
            if ($this->retry($message, 'network_error')) {
                $this->logger->warning('ConversionWax Last Mile delivery failed and was requeued.', [
                    'event_id' => $eventId,
                    'attempt' => ((int) $message['attempt']) + 1,
                    'exception' => $exception,
                ]);
            }
            return;
        }

        if ($status >= 200 && $status < 300) {
            return;
        }
        if (in_array($status, self::TRANSIENT_STATUS, true)) {
            $this->retry($message, 'http_' . $status);
            return;
        }

        $this->deadLetter($message, 'http_' . $status);
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
    private function retry(array $message, string $reason): bool
    {
        $nextAttempt = ((int) $message['attempt']) + 1;
        if ($nextAttempt >= self::MAX_ATTEMPTS) {
            $this->deadLetter($message, 'retry_exhausted_' . $reason);
            return false;
        }

        // This delay occurs only inside the queue consumer. It never blocks
        // a storefront or checkout request.
        sleep(min(30, 2 ** max(0, $nextAttempt - 1)));
        $message['attempt'] = $nextAttempt;
        $message['lastFailure'] = $reason;
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
