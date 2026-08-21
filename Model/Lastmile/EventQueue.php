<?php

declare(strict_types=1);

namespace Cwax\Banner\Model\Lastmile;

use Cwax\Banner\Model\Config;
use Cwax\Banner\Model\Queue\MessagePublisher;
use Magento\Cookie\Helper\Cookie as CookieHelper;
use Magento\Framework\App\Request\Http;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

class EventQueue
{
    private const SDK_VERSION = 'magento-0.2.0';
    private bool $sessionStartQueued = false;

    public function __construct(
        private readonly Config $config,
        private readonly VisitorContext $visitorContext,
        private readonly MessagePublisher $publisher,
        private readonly StoreManagerInterface $storeManager,
        private readonly CookieHelper $cookieHelper,
        private readonly Http $request,
        private readonly LoggerInterface $logger
    ) {
    }

    /** @param array<string, mixed> $attributes */
    public function enqueue(string $type, array $attributes = []): void
    {
        try {
            $this->enqueueInternal($type, $attributes);
        } catch (\Throwable $exception) {
            // Tracking must never break rendering, login, cart, or checkout.
            $this->logger->error('ConversionWax Last Mile could not enqueue an event.', [
                'event_type' => $type,
                'exception' => $exception,
            ]);
        }
    }

    /** @param array<string, mixed> $attributes */
    private function enqueueInternal(string $type, array $attributes): void
    {
        $storeId = (int) $this->storeManager->getStore()->getId();
        if (!$this->config->isConfigured($storeId)) {
            return;
        }
        if ($this->config->respectsCookieRestriction($storeId)
            && !$this->cookieHelper->isUserAllowedSaveCookie()
        ) {
            return;
        }

        $context = $this->visitorContext->get();
        $eventId = bin2hex(random_bytes(16));
        $event = array_filter([
            'eventId' => $eventId,
            'type' => $type,
            'ts' => (int) round(microtime(true) * 1000),
            'path' => substr($this->request->getPathInfo(), 0, 2048),
            'productId' => $attributes['productId'] ?? null,
            'orderId' => $attributes['orderId'] ?? null,
            'value' => $attributes['value'] ?? null,
            'currency' => $attributes['currency'] ?? null,
            'customerId' => $attributes['customerId'] ?? null,
            'emailHash' => $attributes['emailHash'] ?? null,
        ], static fn (mixed $value): bool => $value !== null && $value !== '');

        $isNew = $context['isNew'] && !$this->sessionStartQueued;
        $this->sessionStartQueued = $this->sessionStartQueued || $isNew;
        $payload = [
            'batchId' => $eventId,
            'schemaVersion' => 1,
            'sentAt' => $event['ts'],
            'storeId' => $this->config->getWebPropertyId($storeId),
            'visitorId' => $context['visitorId'],
            'sessionId' => $context['sessionId'],
            'session' => [
                'startedAt' => $context['startedAt'],
                'lastActivityAt' => $context['lastActivityAt'],
                'entryUrl' => substr($context['entryUrl'], 0, 2048),
                'entryReferrer' => $context['entryReferrer'],
                'isNew' => $isNew,
                'number' => $context['number'],
                'hadPurchase' => $context['hadPurchase'],
            ],
            'device' => [
                'ua' => substr((string) $this->request->getServer('HTTP_USER_AGENT'), 0, 1024),
            ],
            'platform' => 'magento',
            'capture' => 'hook',
            'sdk' => ['v' => self::SDK_VERSION],
            'events' => [$event],
        ];

        $this->publisher->publishEvent([
            'version' => 1,
            'attempt' => 0,
            'storeViewId' => $storeId,
            'eventId' => $eventId,
            'payload' => $payload,
        ]);
    }

    public function markPurchased(): void
    {
        try {
            $this->visitorContext->markPurchased();
        } catch (\Throwable $exception) {
            $this->logger->warning('ConversionWax Last Mile could not update purchase context.', [
                'exception' => $exception,
            ]);
        }
    }
}
