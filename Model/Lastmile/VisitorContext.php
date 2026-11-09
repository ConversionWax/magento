<?php

declare(strict_types=1);

namespace Cwax\Banner\Model\Lastmile;

use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\Response\Http as ResponseHttp;
use Magento\Framework\HTTP\PhpEnvironment\Request as PhpRequest;
use Magento\Framework\Stdlib\Cookie\CookieMetadataFactory;
use Magento\Framework\Stdlib\CookieManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Visitor and session identity for server commerce events (add to cart, checkout, purchase, identify).
 *
 * lm.js owns identity on the storefront: it mints `_cwid` and its session cookie `_cws` (cookie contract
 * C1), and this class reads them. Magento mints only when `_cwid` (or a live session) is missing, which
 * happens when lm.js is blocked or another front end runs it, and only on a response that is KNOWN to be
 * uncacheable. A Magento-minted `_cws` carries the "mg" key, so the module keeps that session alive itself
 * and never rewrites a session lm.js owns.
 */
class VisitorContext
{
    private const VISITOR_COOKIE = '_cwid';
    // lm.js's session cookie (lastmile packages/snippet/src/cookies.ts CWS), the only session cookie.
    private const SESSION_COOKIE = '_cws';
    private const SUMMARY_COOKIE = '_cwvs';
    // Marks a _cws that Magento minted. Extra keys are allowed by the cookie contract and lm.js keeps them.
    private const SERVER_SESSION_KEY = 'mg';
    private const COOKIE_DURATION = 34560000;
    private const SESSION_IDLE_MS = 1800000;
    private const NUMBER_CAP = 1000000;
    // Full-page cache, Varnish and CDNs never store a response to these. Anything else (GET, HEAD, or an
    // unknown method) is judged by its Cache-Control header instead.
    private const UNCACHEABLE_METHODS = ['POST', 'PUT', 'PATCH', 'DELETE'];
    private const MAX_COOKIE_LENGTH = 4096;

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
    /** @var array<string, mixed>|null the _cws value to write, only for a session Magento owns */
    private ?array $serverSession = null;
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
     * Callers must have storage consent before calling this; VisitorContext
     * itself holds no consent opinion.
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

        $session = $this->decodeSession();
        $isNew = false;
        if ($session !== null && !isset($session[self::SERVER_SESSION_KEY])) {
            // lm.js owns this session and rolls it over itself: adopt it so
            // server and browser events join on session_id, and never write it.
            $sessionId = $session['sid'];
        } elseif ($session !== null && $this->isLive($session, $now)) {
            // Magento's own session (lm.js is not running): keep it alive.
            $sessionId = $session['sid'];
            $this->serverSession = ['la' => $now] + $session;
        } elseif ($cacheable !== false) {
            // A mint of our own might not survive this response.
            $this->unresolved = true;
            return null;
        } else {
            $sessionId = $this->uuid();
            $isNew = true;
            // Cookie contract C1 shape. st === la marks a freshly minted session.
            $this->serverSession = [
                'sid' => $sessionId,
                'st' => $now,
                'la' => $now,
                'pv' => 0,
                'src' => 'direct',
                'med' => 'direct',
                self::SERVER_SESSION_KEY => 1,
            ];
        }

        $startedAt = !$isNew && is_int($session['st'] ?? null) && $session['st'] <= $now ? $session['st'] : $now;
        $claims = $this->decodeSummaryClaims();
        $entry = $this->resolveEntry();
        $this->context = [
            'visitorId' => $visitorId,
            'sessionId' => $sessionId,
            'startedAt' => $startedAt,
            'lastActivityAt' => $now,
            'entryUrl' => $entry['url'],
            'entryReferrer' => $entry['referrer'],
            'isNew' => $isNew,
            'number' => $claims['number'] ?? 1,
            'hadPurchase' => $claims['hadPurchase'] ?? false,
            'geo' => is_string($session['geo'] ?? null) ? substr($session['geo'], 0, 64) : null,
        ];

        // Cookie writing is deferred to flush(), called at
        // controller_front_send_response_before once Cache-Control is final.
        // Writing here, mid-request, would rely on $cacheable being resolved
        // from a Cache-Control header that has not been set yet by that
        // point, which is exactly the bug this class exists to avoid.

        return $this->context;
    }

    /**
     * For a GET commerce request that is never cacheable (the checkout page): sends the no-cache headers
     * now, so get() may mint, and the final response agrees. Call it before get().
     */
    public function declareUncacheable(): void
    {
        $this->response->setNoCacheHeaders();
        $this->cacheable = false;
        $this->unresolved = false;
    }

    /**
     * Writes the identity cookies, if any identity was resolved this request
     * and the response has turned out to be genuinely uncacheable. Called
     * late, at controller_front_send_response_before; a repeat call with
     * unchanged state writes nothing. The late call runs after Magento's
     * page cache plugin has finalized the Cache-Control header - calling
     * isCacheableResponse() directly here rather than through the memoized
     * resolveCacheable() so a stale reading from an earlier, premature call
     * during the request cannot suppress a real cacheability check now.
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
     *
     * `_cwid` is written again even when it came from the browser: Safari
     * caps a cookie written by script (lm.js) at 7 days, or 24 hours after a
     * tracked link, but not one set by an HTTP response.
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
     * Memoizes the cacheability get() works from. A POST, PUT or DELETE
     * (add to cart, login, order placement) is never stored by any cache,
     * so it is known false at once. For GET and HEAD it re-evaluates while
     * the Cache-Control header is still unset (null), instead of locking in
     * that null as though it were a decided answer; only a real true/false
     * result ever sticks. flush() never calls this method - it calls
     * isCacheableResponse() directly so a null memoized here early cannot
     * suppress its live, late check.
     */
    private function resolveCacheable(): ?bool
    {
        if ($this->cacheable === null) {
            $this->cacheable = $this->isCacheableMethod() ? $this->isCacheableResponse() : false;
        }
        return $this->cacheable;
    }

    private function isCacheableMethod(): bool
    {
        if (!$this->request instanceof PhpRequest) {
            return true;
        }

        return !in_array(strtoupper((string) $this->request->getMethod()), self::UNCACHEABLE_METHODS, true);
    }

    /**
     * Detects cacheability from the response's own Cache-Control header
     * rather than guessing by route: Magento's full page cache subsystem has
     * already finalized this header by controller_front_send_response_before,
     * the point at which flush() runs. Before that point the
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
            $session = $this->serverSession === null
                ? ''
                : json_encode($this->serverSession, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            $signature = $this->context['visitorId'] . '|' . $session;
            if ($signature === $this->persisted) {
                return;
            }
            $this->cookieManager->setPublicCookie(
                self::VISITOR_COOKIE,
                (string) $this->context['visitorId'],
                $this->metadata()->setDuration(self::COOKIE_DURATION)
            );
            if ($session !== '') {
                // A session cookie, like lm.js's own _cws: no Max-Age/Expires.
                $this->cookieManager->setPublicCookie(self::SESSION_COOKIE, $session, $this->metadata());
            }
            $this->persisted = $signature;
        } catch (\Throwable $exception) {
            $this->logger->warning('ConversionWax Last Mile could not persist visitor context.', [
                'exception' => $exception,
            ]);
        }
    }

    /**
     * Cookie contract C1 attributes: Path=/, SameSite=Lax, Secure, readable by lm.js and the embed.
     *
     * @return \Magento\Framework\Stdlib\Cookie\PublicCookieMetadata
     */
    private function metadata()
    {
        return $this->cookieMetadataFactory->createPublicCookieMetadata()
            ->setPath('/')
            ->setSecure(true)
            ->setHttpOnly(false)
            ->setSameSite('Lax');
    }

    /**
     * Reads _cws, written by lm.js (URI-encoded JSON) or by this class. Every key is kept, so a session
     * Magento keeps alive loses nothing lm.js may have added to it.
     *
     * @return array<string, mixed>|null with a UUID sid, or null
     */
    private function decodeSession(): ?array
    {
        $raw = (string) $this->cookieManager->getCookie(self::SESSION_COOKIE);
        if ($raw === '' || strlen($raw) > self::MAX_COOKIE_LENGTH) {
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

        return $decoded;
    }

    /** @param array<string, mixed> $session */
    private function isLive(array $session, int $now): bool
    {
        $lastActivity = $session['la'] ?? null;

        return is_int($lastActivity) && $now >= $lastActivity && $now - $lastActivity <= self::SESSION_IDLE_MS;
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

    /**
     * The entry of a session Magento mints. That only happens on a commerce request (an add to cart POST,
     * the checkout page, a REST order call), so the page the visitor was on is its same-host Referer.
     * Without one the request itself is the entry and any other Referer is the session's referrer.
     *
     * @return array{url:string,referrer:?string}
     */
    private function resolveEntry(): array
    {
        $referer = $this->request->getServer('HTTP_REFERER');
        $page = is_string($referer) ? substr($referer, 0, 2048) : '';
        if ($this->isAbsoluteHttpUrl($page) && $this->isStoreUrl($page)) {
            return ['url' => $this->sanitizeEntryUrl($page), 'referrer' => null];
        }

        return [
            'url' => $this->sanitizeEntryUrl((string) $this->request->getUriString()),
            'referrer' => $this->cleanReferrer($referer),
        ];
    }

    /** Scheme, host and path only: a referrer's query and fragment can hold another site's private data. */
    private function cleanReferrer(mixed $referrer): ?string
    {
        if (!is_string($referrer) || $referrer === '') {
            return null;
        }
        $referrer = substr($referrer, 0, 2048);
        if (!$this->isAbsoluteHttpUrl($referrer)) {
            return null;
        }

        return explode('#', explode('?', $referrer, 2)[0], 2)[0];
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
            return substr((string) preg_replace('#/guest-carts/[^/]+#', '/guest-carts/-', $bare), 0, 2048);
        }

        // The guest cart mask id is a bearer credential for that cart.
        $path = (string) preg_replace('#/guest-carts/[^/]+#', '/guest-carts/-', $parts['path'] ?? '/');
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

        $host = (string) preg_replace('/:\d+$/', '', (string) $this->request->getServer('HTTP_HOST'));

        return strcasecmp($parts['host'], $host) === 0;
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
