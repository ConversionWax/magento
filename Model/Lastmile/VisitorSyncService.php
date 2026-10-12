<?php

declare(strict_types=1);

namespace Cwax\Banner\Model\Lastmile;

use Cwax\Banner\Api\ConsentResolverInterface;
use Cwax\Banner\Model\Config;
use Magento\Framework\App\Request\Http;
use Magento\Framework\HTTP\PhpEnvironment\RemoteAddress;

/**
 * Called from the cwax-visitor section. Checks storage consent first and, without it, does nothing but
 * remove stale cookies. With consent it keeps _cws and _cwvs in step with the collector, at most once per
 * Magento session (or again when _cws is missing, so the session count rises), plus once per pending purchase.
 */
class VisitorSyncService
{
    private const SDK_VERSION = 'magento-0.2.0';
    // Same attribution allowlist as VisitorContext::sanitizeEntryUrl (utm_* and the collector's click ids).
    private const ALLOWED_ATTRIBUTION_CLICK_IDS = [
        'gclid', 'gbraid', 'wbraid', 'fbclid', 'msclkid', 'ttclid', 'epik', 'sccid', 'li_fat_id', 'twclid',
    ];

    public function __construct(
        private readonly Config $config,
        private readonly VisitorContext $visitorContext,
        private readonly VisitorCookies $cookies,
        private readonly CollectorClient $client,
        private readonly RemoteAddress $remoteAddress,
        private readonly Http $request,
        private readonly ConsentResolverInterface $consentResolver
    ) {
    }

    /** @return array{state:string,synced:bool,sid:string} state is 'denied' when storage consent is missing */
    public function sync(int $storeId): array
    {
        // Hard requirement: consent first. Nothing below may run (no identity mint, no collector call,
        // no cookie write) unless storage consent is granted.
        if (!$this->consentResolver->resolve($storeId)->isStorageGranted()) {
            $this->cookies->clearAll();

            return ['state' => 'denied', 'synced' => false, 'sid' => ''];
        }

        $context = $this->visitorContext->get();
        if ($context === null) {
            return ['state' => 'granted', 'synced' => false, 'sid' => ''];
        }
        $summary = $this->cookies->getSummary();
        $synced = true;

        if ($summary === null || $this->cookies->getSessionId() !== $context['sessionId']) {
            $response = $this->client->session(
                $storeId,
                $this->sessionBody($storeId, $context, $summary),
                $this->clientIp(),
                $this->userAgent()
            );
            if ($response === null) {
                $synced = false;
            } else {
                $summary = (string) $response['summary'];
                $this->cookies->writeSummary($summary, $storeId);
                $this->cookies->writeSession($this->sessionCookie($context, $response));
            }
        }

        $pending = $context['pendingPurchase'] ?? null;
        if ($synced && $pending !== null && $summary !== null) {
            $response = $this->client->session(
                $storeId,
                $this->purchaseBody($storeId, $context, $summary, $pending),
                $this->clientIp(),
                $this->userAgent()
            );
            if ($response === null) {
                $synced = false;
            } else {
                $this->cookies->writeSummary((string) $response['summary'], $storeId);
                $this->visitorContext->clearPendingPurchase();
            }
        }

        return ['state' => 'granted', 'synced' => $synced, 'sid' => (string) $context['sessionId']];
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function sessionBody(int $storeId, array $context, ?string $summary): array
    {
        // st === la is how the collector recognizes a freshly minted session (cookie contract), so the
        // first sync of a Magento session always reports the session start for both timestamps.
        $startedAt = (int) $context['startedAt'];

        return [
            'mintOnly' => true,
            'requestId' => 'magento-session-' . $context['sessionId'],
            'storeId' => $this->config->getWebPropertyId($storeId),
            'visitorId' => $context['visitorId'],
            'summary' => $summary,
            'session' => [
                'sessionId' => $context['sessionId'],
                'startedAt' => $startedAt,
                'lastActivityAt' => $startedAt,
                'pageviews' => 1,
            ],
            'arrival' => [
                'url' => $this->arrivalUrl($context),
                'referrer' => null,
                'ts' => $startedAt,
                'isLanding' => true,
            ],
            'sdk' => ['v' => self::SDK_VERSION],
        ] + $this->deviceField();
    }

    /**
     * @param array<string, mixed> $context
     * @param array{orderId:string,value:float,currency:string,ts:int} $pending
     * @return array<string, mixed>
     */
    private function purchaseBody(int $storeId, array $context, string $summary, array $pending): array
    {
        $startedAt = (int) $context['startedAt'];

        return [
            'mintOnly' => true,
            'requestId' => 'magento-purchase-' . $pending['orderId'],
            'storeId' => $this->config->getWebPropertyId($storeId),
            'visitorId' => $context['visitorId'],
            'summary' => $summary,
            'session' => [
                'sessionId' => $context['sessionId'],
                'startedAt' => $startedAt,
                'lastActivityAt' => max((int) $context['lastActivityAt'], $startedAt + 1),
                'pageviews' => 1,
            ],
            'purchase' => [
                'orderId' => $pending['orderId'],
                'value' => $pending['value'],
                'currency' => $pending['currency'],
                'productIds' => [],
                'ts' => $pending['ts'],
            ],
            'sdk' => ['v' => self::SDK_VERSION],
        ] + $this->deviceField();
    }

    /**
     * @param array<string, mixed> $context
     * @param array<string, mixed> $response
     * @return array{sid:string,st:int,la:int,pv:int,src:string,med:string,geo?:string}
     */
    private function sessionCookie(array $context, array $response): array
    {
        $entry = is_array($response['entry'] ?? null) ? $response['entry'] : [];
        $startedAt = (int) $context['startedAt'];
        $session = [
            'sid' => (string) $context['sessionId'],
            'st' => $startedAt,
            'la' => $startedAt,
            'pv' => 1,
            'src' => is_string($entry['source'] ?? null) && $entry['source'] !== ''
                ? substr($entry['source'], 0, 16)
                : 'direct',
            'med' => is_string($entry['medium'] ?? null) && $entry['medium'] !== ''
                ? substr($entry['medium'], 0, 16)
                : 'direct',
        ];
        $geo = $response['geo'] ?? null;
        if (is_array($geo) && is_string($geo['country'] ?? null) && $geo['country'] !== '') {
            $session['geo'] = $geo['country']
                . (is_string($geo['region'] ?? null) && $geo['region'] !== '' ? ',' . $geo['region'] : '');
        }

        return $session;
    }

    /**
     * Under Varnish the section request is the first request PHP sees, so the page the visitor landed on
     * is the Referer of this request. Only a same-host Referer is used, reduced to path plus attribution
     * parameters; otherwise the context entry URL is the fallback.
     *
     * @param array<string, mixed> $context
     */
    private function arrivalUrl(array $context): string
    {
        $referer = $this->request->getServer('HTTP_REFERER');
        $parts = is_string($referer) && $referer !== '' ? parse_url(substr($referer, 0, 2048)) : false;
        $host = (string) preg_replace('/:\d+$/', '', (string) $this->request->getServer('HTTP_HOST'));
        if ($parts === false
            || !in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)
            || strcasecmp((string) ($parts['host'] ?? ''), $host) !== 0
        ) {
            return (string) $context['entryUrl'];
        }

        $query = '';
        if (($parts['query'] ?? '') !== '') {
            parse_str($parts['query'], $params);
            $allowed = [];
            foreach ($params as $key => $value) {
                $lowerKey = strtolower((string) $key);
                if (is_string($value) && (str_starts_with($lowerKey, 'utm_')
                    || in_array($lowerKey, self::ALLOWED_ATTRIBUTION_CLICK_IDS, true))) {
                    $allowed[$key] = $value;
                }
            }
            $query = $allowed === [] ? '' : '?' . http_build_query($allowed);
        }

        return substr(($parts['path'] ?? '/') . $query, 0, 2048);
    }

    /** @return array{device?: array{ua: string}} the key is omitted entirely when the user agent is empty */
    private function deviceField(): array
    {
        // The collector parses the raw user agent itself (X8): device.ua only.
        $ua = $this->userAgent();

        return $ua === '' ? [] : ['device' => ['ua' => $ua]];
    }

    private function userAgent(): string
    {
        return substr((string) $this->request->getServer('HTTP_USER_AGENT'), 0, 1024);
    }

    private function clientIp(): ?string
    {
        $ip = $this->remoteAddress->getRemoteAddress();

        return is_string($ip) && $ip !== '' ? $ip : null;
    }
}
