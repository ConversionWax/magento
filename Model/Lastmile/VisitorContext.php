<?php

declare(strict_types=1);

namespace Cwax\Banner\Model\Lastmile;

use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\Response\Http as ResponseHttp;
use Magento\Framework\Stdlib\Cookie\CookieMetadataFactory;
use Magento\Framework\Stdlib\CookieManagerInterface;
use Psr\Log\LoggerInterface;

class VisitorContext
{
    private const VISITOR_COOKIE = '_cwid';
    private const SESSION_COOKIE = '_cwms';
    // Browser-side cookies (lastmile packages/snippet/src/cookies.ts): read
    // only, never written here.
    private const BROWSER_SESSION_COOKIE = '_cws';
    private const SUMMARY_COOKIE = '_cwvs';
    private const COOKIE_DURATION = 34560000;
    private const SESSION_IDLE_MS = 1800000;
    private const NUMBER_CAP = 1000000;
    private const PENDING_PURCHASE_MAX_AGE_MS = 2592000000; // 30 days

    // Query params kept on entryUrl: standard UTM parameters plus the click
    // ids the collector's own attribution classifier recognizes (lastmile
    // lib/lastmile-lib/src/classify.ts CLICK_IDS). Everything else - account
    // confirmation keys, newsletter codes, any other store-specific token -
    // is dropped.
    private const ALLOWED_ATTRIBUTION_CLICK_IDS = [
        'gclid', 'gbraid', 'wbraid', 'fbclid', 'msclkid', 'ttclid', 'epik', 'sccid', 'li_fat_id', 'twclid',
    ];

    /** @var array<string, mixed>|null */
    private ?array $context = null;
    private bool $unresolved = false;
    private ?bool $cacheable = null;
    /** @var string|null what the last persist() wrote, so a repeat flush() sends no duplicate Set-Cookie */
    private ?string $persisted = null;

    public function __construct(
        private readonly CookieManagerInterface $cookieManager,
        private readonly CookieMetadataFactory $cookieMetadataFactory,
        private readonly RequestInterface $request,
        private readonly ResponseHttp $response,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Returns null when identity cannot be established for this response:
     * under Varnish's default VCL, Set-Cookie is stripped from any cacheable
     * (ttl > 0) response and a cache HIT never reaches PHP again, so a
     * visitor/session id minted here would never reach the browser. Callers
     * must not enqueue an event in that case rather than report a phantom
     * new visitor.
     *
     * @return array{
     *   visitorId:string,
     *   sessionId:string,
     *   startedAt:int,
     *   lastActivityAt:int,
     *   entryUrl:string,
     *   entryReferrer:?string,
     *   isNew:bool,
     *   number:int,
     *   hadPurchase:bool,
     *   pendingPurchase:?array{orderId:string,value:float,currency:string,ts:int},
     *   geo:?string
     * }|null
     */
    public function get(): ?array
    {
        if ($this->context !== null || $this->unresolved) {
            return $this->context;
        }

        $now = (int) round(microtime(true) * 1000);
        // Tri-state: true (cacheable), false (known uncacheable), or null
        // (not yet determined - no Cache-Control header exists yet at this
        // point in the request). Minting a brand new id is only safe when
        // cacheability is KNOWN false; null must be treated exactly like
        // true here, or a mid-request observer would mint an id that later
        // turns out to live on a cacheable page and can never persist.
        $cacheable = $this->resolveCacheable();

        $rawVisitorId = (string) $this->cookieManager->getCookie(self::VISITOR_COOKIE);
        if ($this->isUuid($rawVisitorId)) {
            $visitorId = $rawVisitorId;
        } elseif ($cacheable !== false) {
            $this->unresolved = true;
            return null;
        } else {
            $visitorId = $this->uuid();
        }

        $previous = $this->decodeState(
            (string) $this->cookieManager->getCookie(self::SESSION_COOKIE),
            $visitorId
        );
        $browserSession = $this->decodeBrowserSession();
        $claims = $this->decodeSummaryClaims();
        $browserSid = $browserSession['sid'] ?? null;

        if ($browserSid !== null) {
            // The store also runs the browser snippet: adopt its live
            // session id so server and browser events join on session_id,
            // rather than minting a competing one of our own.
            $isNew = $previous === null || $previous['sessionId'] !== $browserSid;
            $sessionId = $browserSid;
        } else {
            $isNew = $previous === null
                || $now < $previous['lastActivityAt']
                || ($now - $previous['lastActivityAt']) > self::SESSION_IDLE_MS;
            if ($isNew && $cacheable !== false && $previous !== null) {
                // No browser session to adopt, and cacheability is known
                // true or still unknown: a mint of our own might not survive
                // this response, so keep the stale session going rather than
                // fabricate an id that could never persist.
                $isNew = false;
            }
            $sessionId = $isNew
                ? ($cacheable !== false ? null : $this->uuid())
                : $previous['sessionId'];
        }

        if ($sessionId === null) {
            $this->unresolved = true;
            return null;
        }

        if ($isNew) {
            $state = [
                'visitorId' => $visitorId,
                'sessionId' => $sessionId,
                'startedAt' => $now,
                'lastActivityAt' => $now,
                'entryUrl' => $this->sanitizeEntryUrl($this->request->getUriString()),
                'entryReferrer' => $this->cleanReferrer($this->request->getServer('HTTP_REFERER')),
                'number' => $claims['number']
                    ?? min($previous === null ? 1 : $previous['number'] + 1, self::NUMBER_CAP),
                'hadPurchase' => $claims['hadPurchase'] ?? ($previous['hadPurchase'] ?? false),
                'pendingPurchase' => $previous['pendingPurchase'] ?? null,
            ];
        } else {
            // Only reachable once $previous has already been established
            // above; the null-coalesced literal is a defensive fallback.
            $state = $previous ?? [
                'visitorId' => $visitorId,
                'sessionId' => $sessionId,
                'startedAt' => $now,
                'lastActivityAt' => $now,
                'entryUrl' => $this->sanitizeEntryUrl($this->request->getUriString()),
                'entryReferrer' => $this->cleanReferrer($this->request->getServer('HTTP_REFERER')),
                'number' => $claims['number'] ?? 1,
                'hadPurchase' => $claims['hadPurchase'] ?? false,
                'pendingPurchase' => null,
            ];
            $state['sessionId'] = $sessionId;
            $state['lastActivityAt'] = $now;
            if ($claims['number'] !== null) {
                $state['number'] = $claims['number'];
            }
            if ($claims['hadPurchase'] !== null) {
                $state['hadPurchase'] = $state['hadPurchase'] || $claims['hadPurchase'];
            }
        }

        $this->context = [
            ...$state,
            'isNew' => $isNew,
            'geo' => $browserSession['geo'] ?? null,
        ];

        // Cookie writing is deferred to flush(), called at
        // controller_front_send_response_before once Cache-Control is final.
        // Writing here, mid-request, would rely on $cacheable being resolved
        // from a Cache-Control header that has not been set yet by that
        // point, which is exactly the bug this class exists to avoid.

        return $this->context;
    }

    /**
     * Callers must have storage consent before calling this (or clearPendingPurchase()),
     * exactly as for get(); VisitorContext itself holds no consent opinion.
     *
     * @param array{orderId?:string,value?:float|int,currency?:string} $purchase
     */
    public function markPurchased(array $purchase = []): void
    {
        if ($this->get() === null) {
            return;
        }
        $this->context['hadPurchase'] = true;
        $pending = $this->normalizePurchase($purchase);
        if ($pending !== null) {
            $this->context['pendingPurchase'] = $pending;
        }
        // Persisting is deferred to flush(); see the note in get().
    }

    public function clearPendingPurchase(): void
    {
        if ($this->get() === null) {
            return;
        }
        $this->context['pendingPurchase'] = null;
        // Persisting is deferred to flush(); see the note in get().
    }

    /**
     * @param array<string, mixed> $purchase
     * @return array{orderId:string,value:float,currency:string,ts:int}|null
     */
    private function normalizePurchase(array $purchase): ?array
    {
        $orderId = $purchase['orderId'] ?? null;
        $value = $purchase['value'] ?? null;
        $currency = $purchase['currency'] ?? null;
        if (!is_string($orderId) || $orderId === '' || strlen($orderId) > 64
            || !(is_int($value) || is_float($value)) || !is_finite((float) $value)
            || !is_string($currency) || preg_match('/^[A-Za-z]{3}$/', $currency) !== 1
        ) {
            return null;
        }

        return [
            'orderId' => $orderId,
            'value' => (float) $value,
            'currency' => strtoupper($currency),
            'ts' => (int) round(microtime(true) * 1000),
        ];
    }

    /**
     * @return array{orderId:string,value:float,currency:string,ts:int}|null
     */
    private function decodePurchase(mixed $raw): ?array
    {
        if (!is_array($raw)
            || !is_string($raw['o'] ?? null) || $raw['o'] === '' || strlen($raw['o']) > 64
            || !(is_int($raw['v'] ?? null) || is_float($raw['v'] ?? null))
            || !is_finite((float) $raw['v'])
            || !is_string($raw['c'] ?? null) || preg_match('/^[A-Za-z]{3}$/', $raw['c']) !== 1
            || !is_int($raw['t'] ?? null)
        ) {
            return null;
        }
        $ageMs = (int) round(microtime(true) * 1000) - $raw['t'];
        if ($ageMs > self::PENDING_PURCHASE_MAX_AGE_MS || $ageMs < -60000) {
            return null;
        }

        return [
            'orderId' => $raw['o'],
            'value' => (float) $raw['v'],
            'currency' => strtoupper($raw['c']),
            'ts' => $raw['t'],
        ];
    }

    /**
     * Writes the identity cookies, if any identity was resolved this request
     * and the response has turned out to be genuinely uncacheable. Called
     * late, at controller_front_send_response_before (and by the cwax-visitor
     * section, which has declared its response uncacheable); a repeat call
     * with unchanged state writes nothing. The late call runs after
     * Magento's page cache plugin has finalized the Cache-Control header -
     * calling isCacheableResponse() directly here rather than through the
     * memoized resolveCacheable() so a stale reading from an earlier,
     * premature call during the request cannot suppress a real cacheability
     * check now.
     *
     * get() and resolveCacheable() treat a null (undetermined) result the
     * same as cacheable=true (do not mint); flush() deliberately treats null
     * the opposite way (write the cookie), and both are correct for when
     * they run. get() can run mid-request, before Cache-Control exists,
     * where null truly just means "not decided yet" - minting there risks
     * the phantom-visitor bug this class exists to prevent. flush() runs at
     * response-send, after Magento's page cache plugin has had its chance to
     * mark the response cacheable; if no Cache-Control header exists even at
     * that point, the response was never going to be cached, so null there
     * means the same as a decided false.
     */
    public function flush(): void
    {
        if ($this->context === null) {
            return;
        }
        if ($this->isCacheableResponse()) {
            // Genuinely cacheable (true): Varnish would strip Set-Cookie
            // anyway, so no cookie is written, exactly as the design
            // intends. false and null both fall through to persist() below -
            // PHP's truthiness here doubles as the null-means-uncacheable
            // rule documented above.
            return;
        }
        $this->persist();
    }

    /**
     * Memoizes isCacheableResponse() for get(), which may be called more
     * than once per request. Deliberately does not use a separate "resolved"
     * flag: while the Cache-Control header is still unset, isCacheableResponse()
     * returns null and this re-evaluates on every call instead of locking in
     * that null as though it were a decided answer. Only a real true/false
     * result ever sticks. flush() never calls this method - it calls
     * isCacheableResponse() directly so a null memoized here early cannot
     * suppress its live, late check.
     */
    private function resolveCacheable(): ?bool
    {
        if ($this->cacheable === null) {
            $this->cacheable = $this->isCacheableResponse();
        }
        return $this->cacheable;
    }

    /**
     * Detects cacheability from the response's own Cache-Control header
     * rather than guessing by route: Magento's full page cache subsystem has
     * already finalized this header by controller_front_send_response_before,
     * the point at which the page-view observer runs. Before that point the
     * header frequently does not exist yet at all; that is genuinely
     * undetermined, not decided-uncacheable, so it is reported as null
     * rather than false.
     *
     * @return bool|null true when explicitly public with a positive max-age;
     *   false when a Cache-Control header is present but says no-store,
     *   no-cache, private, or lacks public/a positive max-age; null when no
     *   Cache-Control header is present at all yet.
     */
    private function isCacheableResponse(): ?bool
    {
        $headers = $this->response->getHeaders();
        $cacheControl = $headers->get('Cache-Control');
        if ($cacheControl instanceof \ArrayIterator) {
            $cacheControl = $cacheControl->current();
        }
        if (!is_object($cacheControl) || !method_exists($cacheControl, 'getFieldValue')) {
            // No Cache-Control header at all: not yet determined. Callers
            // must not treat this as a decided answer - see the class notes
            // on get() and flush() above.
            return null;
        }

        $value = strtolower((string) $cacheControl->getFieldValue());
        if (str_contains($value, 'no-store') || str_contains($value, 'no-cache') || str_contains($value, 'private')) {
            return false;
        }
        if (!str_contains($value, 'public') || preg_match('/max-age=(\d+)/', $value, $matches) !== 1) {
            return false;
        }

        return (int) $matches[1] > 0;
    }

    private function persist(): void
    {
        if ($this->context === null) {
            return;
        }

        try {
            $encoded = $this->encodeState($this->context);
            $signature = $this->context['visitorId'] . '|' . $encoded;
            if ($signature === $this->persisted) {
                return;
            }
            $visitorMetadata = $this->cookieMetadataFactory->createPublicCookieMetadata()
                ->setDuration(self::COOKIE_DURATION)
                ->setPath('/')
                ->setSecure(true)
                ->setHttpOnly(false)
                ->setSameSite('Lax');
            $this->cookieManager->setPublicCookie(
                self::VISITOR_COOKIE,
                (string) $this->context['visitorId'],
                $visitorMetadata
            );

            // _cwms is a session cookie: no Duration is set, so no
            // Max-Age/Expires attribute is sent, matching the contract's
            // session-cookie treatment of the browser's own _cws.
            $sessionMetadata = $this->cookieMetadataFactory->createPublicCookieMetadata()
                ->setPath('/')
                ->setSecure(true)
                ->setHttpOnly(false)
                ->setSameSite('Lax');
            $this->cookieManager->setPublicCookie(
                self::SESSION_COOKIE,
                $encoded,
                $sessionMetadata
            );
            $this->persisted = $signature;
        } catch (\Throwable $exception) {
            $this->logger->warning('ConversionWax Last Mile could not persist visitor context.', [
                'exception' => $exception,
            ]);
        }
    }

    /** @param array<string, mixed> $state */
    private function encodeState(array $state): string
    {
        $payload = [
            'vid' => $state['visitorId'],
            'sid' => $state['sessionId'],
            'st' => $state['startedAt'],
            'la' => $state['lastActivityAt'],
            'url' => $state['entryUrl'],
            'ref' => $state['entryReferrer'],
            'n' => $state['number'],
            'hp' => $state['hadPurchase'] ? 1 : 0,
        ];
        if (($state['pendingPurchase'] ?? null) !== null) {
            // Only what the collector needs: no email, no customer id.
            $payload['pp'] = [
                'o' => $state['pendingPurchase']['orderId'],
                'v' => $state['pendingPurchase']['value'],
                'c' => $state['pendingPurchase']['currency'],
                't' => $state['pendingPurchase']['ts'],
            ];
        }
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        return rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
    }

    /**
     * @return array{
     *   visitorId:string,
     *   sessionId:string,
     *   startedAt:int,
     *   lastActivityAt:int,
     *   entryUrl:string,
     *   entryReferrer:?string,
     *   number:int,
     *   hadPurchase:bool,
     *   pendingPurchase:?array{orderId:string,value:float,currency:string,ts:int}
     * }|null
     */
    private function decodeState(string $encoded, string $visitorId): ?array
    {
        if ($encoded === '' || strlen($encoded) > 4096) {
            return null;
        }

        try {
            $padded = strtr($encoded, '-_', '+/');
            $padded .= str_repeat('=', (4 - strlen($padded) % 4) % 4);
            $decoded = base64_decode($padded, true);
            if ($decoded === false) {
                return null;
            }
            $state = json_decode($decoded, true, 16, JSON_THROW_ON_ERROR);
            if (!is_array($state)
                || ($state['vid'] ?? null) !== $visitorId
                || !$this->isUuid((string) ($state['sid'] ?? ''))
                || !is_int($state['st'] ?? null)
                || !is_int($state['la'] ?? null)
                || !is_string($state['url'] ?? null)
                || !is_int($state['n'] ?? null)
                || $state['n'] < 1
            ) {
                return null;
            }

            // _cwms is not HttpOnly, so a compromised page or rogue browser
            // extension could rewrite it with an attacker-chosen entryUrl
            // before the next request. Validate the host before sanitizing -
            // sanitizeEntryUrl() normalizes away scheme/host entirely, so it
            // must run after the store-host check, not before it, or a
            // different-host URL would slip through by having its host
            // silently stripped instead of being rejected.
            $rawUrl = substr($state['url'], 0, 2048);
            if (!$this->isStoreUrl($rawUrl)) {
                return null;
            }
            $url = $this->sanitizeEntryUrl($rawUrl);

            $ref = is_string($state['ref'] ?? null) ? substr($state['ref'], 0, 2048) : null;
            if ($ref !== null && !$this->isAbsoluteHttpUrl($ref)) {
                $ref = null;
            }

            return [
                'visitorId' => $visitorId,
                'sessionId' => $state['sid'],
                'startedAt' => $state['st'],
                'lastActivityAt' => $state['la'],
                'entryUrl' => $url,
                'entryReferrer' => $ref,
                'number' => min($state['n'], self::NUMBER_CAP),
                'hadPurchase' => ($state['hp'] ?? 0) === 1,
                'pendingPurchase' => $this->decodePurchase($state['pp'] ?? null),
            ];
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Reads the browser snippet's session cookie (cookies.ts CWS). Session
     * count / has-purchased come from _cwvs claims separately; only sid and
     * the cached geo string are read here.
     *
     * @return array{sid: string, geo: ?string}|null
     */
    private function decodeBrowserSession(): ?array
    {
        $raw = (string) $this->cookieManager->getCookie(self::BROWSER_SESSION_COOKIE);
        if ($raw === '' || strlen($raw) > 4096) {
            return null;
        }

        try {
            $decoded = json_decode(rawurldecode($raw), true, 8, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return null;
        }
        if (!is_array($decoded) || !$this->isUuid((string) ($decoded['sid'] ?? ''))) {
            return null;
        }

        $geo = is_string($decoded['geo'] ?? null) ? substr($decoded['geo'], 0, 64) : null;

        return ['sid' => $decoded['sid'], 'geo' => $geo];
    }

    /**
     * Reads sc (session count) / hp (has_purchased) from the _cwvs visitor
     * summary claims. The claims are read without verifying the HMAC
     * signature - this module has no access to the collector's signing key -
     * matching the cookie contract's documented behavior for read-only
     * consumers (the conversionwax_js embed does the same).
     *
     * @return array{number: ?int, hadPurchase: ?bool}
     */
    private function decodeSummaryClaims(): array
    {
        $empty = ['number' => null, 'hadPurchase' => null];

        $raw = (string) $this->cookieManager->getCookie(self::SUMMARY_COOKIE);
        $dot = strpos($raw, '.');
        if ($raw === '' || strlen($raw) > 4096 || $dot === false) {
            return $empty;
        }

        try {
            $padded = strtr(substr($raw, 0, $dot), '-_', '+/');
            $padded .= str_repeat('=', (4 - strlen($padded) % 4) % 4);
            $json = base64_decode($padded, true);
            if ($json === false) {
                return $empty;
            }
            $claims = json_decode($json, true, 8, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return $empty;
        }
        if (!is_array($claims)) {
            return $empty;
        }

        $number = isset($claims['sc']) && is_int($claims['sc']) && $claims['sc'] >= 1
            ? min($claims['sc'], self::NUMBER_CAP)
            : null;
        $hadPurchase = isset($claims['hp']) ? $claims['hp'] === 1 : null;

        return ['number' => $number, 'hadPurchase' => $hadPurchase];
    }

    private function cleanReferrer(mixed $referrer): ?string
    {
        if (!is_string($referrer) || $referrer === '') {
            return null;
        }
        $referrer = substr($referrer, 0, 2048);
        return $this->isAbsoluteHttpUrl($referrer) ? $referrer : null;
    }

    /**
     * Keeps only the store's own path plus attribution params (utm_* and the
     * collector's recognized click-id params - lastmile
     * lib/lastmile-lib/src/classify.ts CLICK_IDS), so account confirmation
     * keys, newsletter codes, and any other sensitive query data never leave
     * the store. The rest of the query string is dropped.
     */
    private function sanitizeEntryUrl(string $uriString): string
    {
        $parts = parse_url($uriString);
        if ($parts === false) {
            // Malformed enough that parse_url gave up; drop everything from
            // the first '?' as a conservative fallback so no query data
            // leaks through unfiltered.
            $bare = explode('?', $uriString, 2)[0];
            return substr($bare, 0, 2048);
        }

        $path = $parts['path'] ?? '/';
        $query = '';
        if (isset($parts['query']) && $parts['query'] !== '') {
            parse_str($parts['query'], $params);
            $allowed = [];
            foreach ($params as $key => $value) {
                if (!is_string($value)) {
                    continue;
                }
                $lowerKey = strtolower((string) $key);
                if (str_starts_with($lowerKey, 'utm_')
                    || in_array($lowerKey, self::ALLOWED_ATTRIBUTION_CLICK_IDS, true)
                ) {
                    $allowed[$key] = $value;
                }
            }
            if ($allowed !== []) {
                $query = '?' . http_build_query($allowed);
            }
        }

        return substr($path . $query, 0, 2048);
    }

    private function isStoreUrl(string $value): bool
    {
        if ($value === '') {
            return false;
        }
        if ($value[0] === '/' && !str_starts_with($value, '//')) {
            return true;
        }

        $parts = parse_url($value);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            return false;
        }
        if (!in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
            return false;
        }

        return strcasecmp($parts['host'], (string) $this->request->getServer('HTTP_HOST')) === 0;
    }

    private function isAbsoluteHttpUrl(string $value): bool
    {
        $parts = parse_url($value);
        return $parts !== false
            && isset($parts['scheme'], $parts['host'])
            && in_array(strtolower($parts['scheme']), ['http', 'https'], true);
    }

    private function isUuid(string $value): bool
    {
        return preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
            $value
        ) === 1;
    }

    private function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);
        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12)
        );
    }
}
