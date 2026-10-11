<?php

declare(strict_types=1);

namespace Cwax\Banner\Model\Lastmile;

use Cwax\Banner\Api\ConsentResolverInterface;
use Cwax\Banner\Api\Data\ConsentDecisionInterface;
use Cwax\Banner\Model\Config;
use Cwax\Banner\Model\Queue\MessagePublisher;
use Magento\Framework\App\RequestInterface;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

class EventQueue
{
    private const SDK_VERSION = 'magento-0.2.0';
    private bool $sessionStartQueued = false;
    private ?ConsentDecisionInterface $consentDecision = null;

    public function __construct(
        private readonly Config $config,
        private readonly VisitorContext $visitorContext,
        private readonly MessagePublisher $publisher,
        private readonly StoreManagerInterface $storeManager,
        private readonly ConsentResolverInterface $consentResolver,
        private readonly RequestInterface $request,
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

        // The consent decision mirrors the ConversionWax platform's own
        // consentMode (Cwax\Banner\Api\ConsentResolverInterface) instead of
        // a Magento-only gate. Storage must be checked, and denied, before
        // visitorContext->get() is ever called, so no cookie is minted
        // without it.
        $decision = $this->resolveConsent($storeId);
        if (!$decision->isStorageGranted()) {
            return;
        }

        $context = $this->visitorContext->get();
        if ($context === null) {
            // Identity could not be established for this response (eg a
            // cacheable page with no prior cookie); do not report a phantom
            // new visitor.
            return;
        }

        if (!$decision->isMeasurementGranted()) {
            // Identity may still be usable elsewhere (eg a future Banner
            // personalization read); no event may ship to the collector.
            return;
        }

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

        // Only set when resolvable: the collector rejects an explicit null,
        // it must be entirely absent when there is nothing to report.
        $geo = $this->extractGeo($context);
        if ($geo !== null) {
            $payload['geo'] = $geo;
        }

        $this->publisher->publishEvent([
            'version' => 1,
            'attempt' => 0,
            'storeViewId' => $storeId,
            'eventId' => $eventId,
            // Not part of the collector wire contract (payload above already
            // matches it exactly). Carried alongside for whichever consumer
            // stage ends up forwarding it as an X-Forwarded-For hop to the
            // collector, so the adapter path can eventually resolve geo the
            // same way the browser-event path already does.
            'clientIp' => $this->resolveClientIp(),
            'payload' => $payload,
        ]);
    }

    /**
     * Memoized per instance: Magento shares one EventQueue instance across a
     * single request, so multiple enqueue() calls in the same request (eg
     * page_view then add_to_cart) resolve consent once, not once per event.
     */
    private function resolveConsent(int $storeId): ConsentDecisionInterface
    {
        return $this->consentDecision ??= $this->consentResolver->resolve($storeId);
    }

    /**
     * Country/region for the collector's geo field. Magento has no geoip
     * database of its own to resolve an IP address, so this only has a
     * value when the store also runs the browser snippet and the collector
     * has already cached a resolved geo on the visitor's _cws cookie
     * (cookies.ts StoredSession.geo, "US,CA" compact form). Server-only
     * installs (no snippet) will still get NULL geo until the collector's
     * adapter path resolves it from an IP itself.
     *
     * @param array<string, mixed> $context
     * @return array{country: string, region?: string}|null
     */
    private function extractGeo(array $context): ?array
    {
        $raw = $context['geo'] ?? null;
        if (!is_string($raw) || $raw === '') {
            return null;
        }

        $parts = explode(',', $raw, 2);
        $country = trim($parts[0]);
        if ($country === '' || strlen($country) > 64) {
            return null;
        }

        $geo = ['country' => $country];
        if (isset($parts[1])) {
            $region = trim($parts[1]);
            if ($region !== '' && strlen($region) <= 128) {
                $geo['region'] = $region;
            }
        }

        return $geo;
    }

    /**
     * Client IP, preferring the first X-Forwarded-For hop over REMOTE_ADDR -
     * the same precedence Magento's own
     * Magento\Framework\HTTP\PhpEnvironment\RemoteAddress uses when
     * configured to trust that header. Inlined here rather than injecting
     * that class, since wiring its alternative-headers argument needs a
     * di.xml entry outside this module's owned files.
     */
    private function resolveClientIp(): ?string
    {
        $forwardedFor = (string) $this->request->getServer('HTTP_X_FORWARDED_FOR');
        if ($forwardedFor !== '') {
            $firstHop = trim(explode(',', $forwardedFor)[0]);
            if ($firstHop !== '') {
                return substr($firstHop, 0, 64);
            }
        }

        $remoteAddr = (string) $this->request->getServer('REMOTE_ADDR');
        return $remoteAddr !== '' ? substr($remoteAddr, 0, 64) : null;
    }

    /**
     * @param array{orderId?:string,value?:float|int,currency?:string} $purchase
     */
    public function markPurchased(array $purchase = []): void
    {
        try {
            $storeId = (int) $this->storeManager->getStore()->getId();
            if (!$this->config->isConfigured($storeId)
                || !$this->resolveConsent($storeId)->isStorageGranted()
            ) {
                return;
            }
            $this->visitorContext->markPurchased($purchase);
        } catch (\Throwable $exception) {
            $this->logger->warning('ConversionWax Last Mile could not update purchase context.', [
                'exception' => $exception,
            ]);
        }
    }
}
