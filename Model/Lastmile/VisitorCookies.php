<?php

declare(strict_types=1);

namespace Cwax\Banner\Model\Lastmile;

use Cwax\Banner\Api\ConsentResolverInterface;
use Magento\Framework\Stdlib\Cookie\CookieMetadataFactory;
use Magento\Framework\Stdlib\CookieManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Reads and writes the shared Last Mile cookies (docs/cookie-contract.md in conversionwax_lastmile).
 * `_cwid` and `_cwms` are owned by VisitorContext; this class only clears them on withdrawal.
 */
class VisitorCookies
{
    public const VISITOR = '_cwid';
    public const MAGENTO_SESSION = '_cwms';
    public const SESSION = '_cws';
    public const SUMMARY = '_cwvs';

    private const PERSISTENT_SECONDS = 34560000;
    private const SUMMARY_PATTERN = '/^[A-Za-z0-9_-]{1,2100}\.[A-Za-z0-9_-]{1,128}$/D';
    private const SESSION_ID_PATTERN = '/^[A-Za-z0-9_-]{8,64}$/D';

    public function __construct(
        private readonly CookieManagerInterface $cookieManager,
        private readonly CookieMetadataFactory $cookieMetadataFactory,
        private readonly ConsentResolverInterface $consentResolver,
        private readonly LoggerInterface $logger
    ) {
    }

    public function getSummary(): ?string
    {
        $value = $this->cookieManager->getCookie(self::SUMMARY);
        if (!is_string($value) || preg_match(self::SUMMARY_PATTERN, $value) !== 1) {
            return null;
        }

        return $value;
    }

    public function getSessionId(): ?string
    {
        $raw = $this->cookieManager->getCookie(self::SESSION);
        if (!is_string($raw) || $raw === '' || strlen($raw) > 1024) {
            return null;
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            $decoded = json_decode(rawurldecode($raw), true);
        }

        $sid = is_array($decoded) ? ($decoded['sid'] ?? null) : null;

        return is_string($sid) && preg_match(self::SESSION_ID_PATTERN, $sid) === 1 ? $sid : null;
    }

    /**
     * The embed treats the presence of _cwvs as consent evidence, so it is written only while storage
     * consent is granted; otherwise a stale copy is removed (consent withdrawn).
     */
    public function writeSummary(string $summary, int $storeId): void
    {
        if (!$this->consentResolver->resolve($storeId)->isStorageGranted()) {
            $this->clear(self::SUMMARY);

            return;
        }
        $this->write(self::SUMMARY, $summary, self::PERSISTENT_SECONDS);
    }

    /** @param array{sid:string,st:int,la:int,pv:int,src:string,med:string,geo?:string} $session */
    public function writeSession(array $session): void
    {
        try {
            $json = json_encode($session, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            $this->logger->warning('ConversionWax Last Mile could not encode the session cookie.', [
                'exception' => $exception,
            ]);

            return;
        }
        $this->write(self::SESSION, $json, null);
    }

    /**
     * Used when cookie consent is not (or no longer) granted, so stale cookies cannot be read as consent.
     */
    public function clearAll(): void
    {
        foreach ([self::VISITOR, self::MAGENTO_SESSION, self::SESSION, self::SUMMARY] as $name) {
            $this->clear($name);
        }
    }

    private function clear(string $name): void
    {
        if ($this->cookieManager->getCookie($name) === null) {
            return;
        }
        try {
            $this->cookieManager->deleteCookie(
                $name,
                $this->cookieMetadataFactory->createCookieMetadata()->setPath('/')
            );
        } catch (\Throwable $exception) {
            $this->logger->warning('ConversionWax Last Mile could not clear a visitor cookie.', [
                'cookie' => $name,
                'exception' => $exception,
            ]);
        }
    }

    private function write(string $name, string $value, ?int $durationSeconds): void
    {
        try {
            $metadata = $this->cookieMetadataFactory->createPublicCookieMetadata()
                ->setPath('/')
                // Always Secure (cookie contract), exactly as VisitorContext writes _cwid and _cwms.
                ->setSecure(true)
                ->setHttpOnly(false)
                ->setSameSite('Lax');
            if ($durationSeconds !== null) {
                $metadata->setDuration($durationSeconds);
            }
            $this->cookieManager->setPublicCookie($name, $value, $metadata);
        } catch (\Throwable $exception) {
            $this->logger->warning('ConversionWax Last Mile could not write a visitor cookie.', [
                'cookie' => $name,
                'exception' => $exception,
            ]);
        }
    }
}
