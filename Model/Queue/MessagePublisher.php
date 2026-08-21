<?php

declare(strict_types=1);

namespace Cwax\Banner\Model\Queue;

use Magento\Framework\MessageQueue\PublisherInterface;

class MessagePublisher
{
    public const EVENT_TOPIC = 'conversionwax.lastmile.events';
    public const FAILED_TOPIC = 'conversionwax.lastmile.failed';

    public function __construct(private readonly PublisherInterface $publisher)
    {
    }

    /** @param array<string, mixed> $message */
    public function publishEvent(array $message): void
    {
        $this->publisher->publish(
            self::EVENT_TOPIC,
            json_encode($message, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
        );
    }

    /** @param array<string, mixed> $message */
    public function publishFailed(array $message): void
    {
        $this->publisher->publish(
            self::FAILED_TOPIC,
            json_encode($message, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
        );
    }
}
