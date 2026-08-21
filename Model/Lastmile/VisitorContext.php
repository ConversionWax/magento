<?php

declare(strict_types=1);

namespace Cwax\Banner\Model\Lastmile;

use Magento\Framework\App\Request\Http;
use Magento\Framework\Stdlib\Cookie\CookieMetadataFactory;
use Magento\Framework\Stdlib\CookieManagerInterface;
use Psr\Log\LoggerInterface;

class VisitorContext
{
    private const VISITOR_COOKIE = '_cwid';
    private const SESSION_COOKIE = '_cwms';
    private const COOKIE_DURATION = 34560000;
    private const SESSION_IDLE_MS = 1800000;

    /** @var array<string, mixed>|null */
    private ?array $context = null;

    public function __construct(
        private readonly CookieManagerInterface $cookieManager,
        private readonly CookieMetadataFactory $cookieMetadataFactory,
        private readonly Http $request,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @return array{
     *   visitorId:string,
     *   sessionId:string,
     *   startedAt:int,
     *   lastActivityAt:int,
     *   entryUrl:string,
     *   entryReferrer:?string,
     *   isNew:bool,
     *   number:int,
     *   hadPurchase:bool
     * }
     */
    public function get(): array
    {
        if ($this->context !== null) {
            return $this->context;
        }

        $now = (int) round(microtime(true) * 1000);
        $visitorId = (string) $this->cookieManager->getCookie(self::VISITOR_COOKIE);
        if (!$this->isUuid($visitorId)) {
            $visitorId = $this->uuid();
        }

        $previous = $this->decodeState(
            (string) $this->cookieManager->getCookie(self::SESSION_COOKIE),
            $visitorId
        );
        $sameSession = $previous !== null
            && $now >= $previous['lastActivityAt']
            && ($now - $previous['lastActivityAt']) <= self::SESSION_IDLE_MS;

        if ($sameSession) {
            $state = $previous;
            $state['lastActivityAt'] = $now;
            $isNew = false;
        } else {
            $state = [
                'visitorId' => $visitorId,
                'sessionId' => $this->uuid(),
                'startedAt' => $now,
                'lastActivityAt' => $now,
                'entryUrl' => $this->request->getUriString(),
                'entryReferrer' => $this->cleanReferrer(
                    $this->request->getServer('HTTP_REFERER')
                ),
                'number' => $previous === null ? 1 : $previous['number'] + 1,
                'hadPurchase' => $previous['hadPurchase'] ?? false,
            ];
            $isNew = true;
        }

        $this->context = [
            ...$state,
            'isNew' => $isNew,
        ];
        $this->persist();

        return $this->context;
    }

    public function markPurchased(): void
    {
        $this->get();
        if ($this->context === null || $this->context['hadPurchase'] === true) {
            return;
        }
        $this->context['hadPurchase'] = true;
        $this->persist();
    }

    private function persist(): void
    {
        if ($this->context === null) {
            return;
        }

        try {
            $metadata = $this->cookieMetadataFactory->createPublicCookieMetadata()
                ->setDuration(self::COOKIE_DURATION)
                ->setPath('/')
                ->setSecure($this->request->isSecure())
                ->setHttpOnly(false)
                ->setSameSite('Lax');
            $this->cookieManager->setPublicCookie(
                self::VISITOR_COOKIE,
                (string) $this->context['visitorId'],
                clone $metadata
            );
            $this->cookieManager->setPublicCookie(
                self::SESSION_COOKIE,
                $this->encodeState($this->context),
                clone $metadata
            );
        } catch (\Throwable $exception) {
            $this->logger->warning('ConversionWax Last Mile could not persist visitor context.', [
                'exception' => $exception,
            ]);
        }
    }

    /** @param array<string, mixed> $state */
    private function encodeState(array $state): string
    {
        $json = json_encode([
            'vid' => $state['visitorId'],
            'sid' => $state['sessionId'],
            'st' => $state['startedAt'],
            'la' => $state['lastActivityAt'],
            'url' => $state['entryUrl'],
            'ref' => $state['entryReferrer'],
            'n' => $state['number'],
            'hp' => $state['hadPurchase'] ? 1 : 0,
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

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
     *   hadPurchase:bool
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

            return [
                'visitorId' => $visitorId,
                'sessionId' => $state['sid'],
                'startedAt' => $state['st'],
                'lastActivityAt' => $state['la'],
                'entryUrl' => substr($state['url'], 0, 2048),
                'entryReferrer' => is_string($state['ref'] ?? null)
                    ? substr($state['ref'], 0, 2048)
                    : null,
                'number' => min($state['n'], 1000000),
                'hadPurchase' => ($state['hp'] ?? 0) === 1,
            ];
        } catch (\Throwable) {
            return null;
        }
    }

    private function cleanReferrer(mixed $referrer): ?string
    {
        if (!is_string($referrer) || $referrer === '') {
            return null;
        }
        return substr($referrer, 0, 2048);
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
