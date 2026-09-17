<?php

declare(strict_types=1);

namespace Cwax\Banner\Model\Queue;

use Psr\Log\LoggerInterface;

/**
 * Drains the conversionwax.lastmile.failed queue.
 *
 * Magento's queue_message cleanup cron only purges rows in COMPLETE or ERROR
 * status. Nothing else ever touched this queue's messages, so without a
 * consumer every dead letter (which carries emailHash, customerId, order
 * value, and URLs in its payload) sat in queue_message as NEW forever, with
 * no erasure path.
 *
 * This consumer closes that gap. It logs only the failure metadata needed to
 * investigate a delivery problem, then returns normally so the message is
 * marked complete and the cleanup cron can reap the row. It never logs the
 * message payload, the connection token, or any other field that could carry
 * customer PII.
 */
class FailedEventConsumer
{
    public function __construct(
        private readonly LoggerInterface $logger
    ) {
    }

    public function process(string $encodedMessage): void
    {
        $eventId = null;
        $attempt = null;
        $reason = null;
        $failedAt = null;

        try {
            $message = json_decode($encodedMessage, true, 32, JSON_THROW_ON_ERROR);
            if (is_array($message)) {
                $eventId = isset($message['eventId']) ? (string) $message['eventId'] : null;
                $attempt = isset($message['attempt']) ? (int) $message['attempt'] : null;
                $reason = isset($message['failureReason'])
                    ? (string) $message['failureReason']
                    : (isset($message['lastFailure']) ? (string) $message['lastFailure'] : null);
                $failedAt = isset($message['failedAt']) ? (string) $message['failedAt'] : null;
            }
        } catch (\Throwable $exception) {
            // The message is not valid JSON. There is nothing further to
            // extract. Fall through and log what little is known so the row
            // still gets reaped instead of sitting as NEW forever.
        }

        $this->logger->error(
            'ConversionWax Last Mile event permanently failed and was drained from the failed queue.',
            [
                'event_id' => $eventId,
                'attempt' => $attempt,
                'reason' => $reason,
                'failed_at' => $failedAt,
            ]
        );
    }
}
