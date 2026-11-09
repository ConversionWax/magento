<?php

declare(strict_types=1);

namespace Cwax\Banner\Test\Unit\Model\Consent;

use Cwax\Banner\Api\Data\ConsentDecisionInterface;
use Cwax\Banner\Api\StoreConfigProviderInterface;
use Cwax\Banner\Model\Consent\SnippetIdentityConsentResolver;
use Magento\Framework\Stdlib\CookieManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for SnippetIdentityConsentResolver: only ever expresses an
 * opinion when the collector-resolved consentMode is confirmed
 * wait_for_consent, and reads presence/shape of the _cws cookie as the
 * tightest server-observable consent signal.
 */
class SnippetIdentityConsentResolverTest extends TestCase
{
    private const STORE_ID = 1;

    /** @var StoreConfigProviderInterface&MockObject */
    private StoreConfigProviderInterface $storeConfigProvider;

    /** @var CookieManagerInterface&MockObject */
    private CookieManagerInterface $cookieManager;

    protected function setUp(): void
    {
        $this->storeConfigProvider = $this->createMock(StoreConfigProviderInterface::class);
        $this->cookieManager = $this->createMock(CookieManagerInterface::class);
    }

    private function makeResolver(): SnippetIdentityConsentResolver
    {
        return new SnippetIdentityConsentResolver($this->storeConfigProvider, $this->cookieManager);
    }

    /**
     * capture_immediately: no snippet-derived gate at all - abstain, and
     * never even read the cookie.
     */
    public function testCaptureImmediatelyAbstainsAndNeverReadsCookie(): void
    {
        $this->storeConfigProvider->method('getConsentMode')
            ->willReturn(StoreConfigProviderInterface::CONSENT_MODE_CAPTURE_IMMEDIATELY);
        $this->cookieManager->expects($this->never())->method('getCookie');

        $decision = $this->makeResolver()->resolve(self::STORE_ID);

        $this->assertNull($decision->getSignal(ConsentDecisionInterface::SIGNAL_STORAGE));
        $this->assertNull($decision->getSignal(ConsentDecisionInterface::SIGNAL_MEASUREMENT));
        $this->assertSame([], $decision->getDenialReasons());
    }

    /**
     * Unresolved consentMode (null): handed entirely to
     * ModeConsentResolver's fallback - abstain here, never read the cookie.
     */
    public function testUnresolvedModeAbstainsAndNeverReadsCookie(): void
    {
        $this->storeConfigProvider->method('getConsentMode')->willReturn(null);
        $this->cookieManager->expects($this->never())->method('getCookie');

        $decision = $this->makeResolver()->resolve(self::STORE_ID);

        $this->assertNull($decision->getSignal(ConsentDecisionInterface::SIGNAL_STORAGE));
        $this->assertSame([], $decision->getDenialReasons());
    }

    /**
     * wait_for_consent, cookie entirely absent (null): denies - this is the
     * first-touch fail-closed behavior documented in the spec.
     */
    public function testWaitForConsentCookieAbsentDenies(): void
    {
        $this->storeConfigProvider->method('getConsentMode')
            ->willReturn(StoreConfigProviderInterface::CONSENT_MODE_WAIT_FOR_CONSENT);
        $this->cookieManager->method('getCookie')->with('_cws')->willReturn(null);

        $decision = $this->makeResolver()->resolve(self::STORE_ID);

        $this->assertFalse($decision->isStorageGranted());
        $this->assertFalse($decision->isMeasurementGranted());
        $this->assertSame(['no_snippet_consent_signal'], $decision->getDenialReasons());
    }

    public function testWaitForConsentCookieEmptyStringDenies(): void
    {
        $this->storeConfigProvider->method('getConsentMode')
            ->willReturn(StoreConfigProviderInterface::CONSENT_MODE_WAIT_FOR_CONSENT);
        $this->cookieManager->method('getCookie')->willReturn('');

        $decision = $this->makeResolver()->resolve(self::STORE_ID);

        $this->assertFalse($decision->isStorageGranted());
        $this->assertSame(['no_snippet_consent_signal'], $decision->getDenialReasons());
    }

    /**
     * A cookie value of the literal string "0" must not be treated as PHP
     * falsy/empty - it is a non-empty string that simply fails the shape
     * check (does not start with "{"), so it must still deny, not throw or
     * be mishandled as an empty value.
     */
    public function testWaitForConsentCookieLiteralZeroDenies(): void
    {
        $this->storeConfigProvider->method('getConsentMode')
            ->willReturn(StoreConfigProviderInterface::CONSENT_MODE_WAIT_FOR_CONSENT);
        $this->cookieManager->method('getCookie')->willReturn('0');

        $decision = $this->makeResolver()->resolve(self::STORE_ID);

        $this->assertFalse($decision->isStorageGranted());
        $this->assertSame(['no_snippet_consent_signal'], $decision->getDenialReasons());
    }

    /**
     * A malformed cookie value that is non-empty but does not decode to
     * something starting with "{" must deny, not grant.
     */
    public function testWaitForConsentMalformedCookieDenies(): void
    {
        $this->storeConfigProvider->method('getConsentMode')
            ->willReturn(StoreConfigProviderInterface::CONSENT_MODE_WAIT_FOR_CONSENT);
        $this->cookieManager->method('getCookie')->willReturn('not-json');

        $decision = $this->makeResolver()->resolve(self::STORE_ID);

        $this->assertFalse($decision->isStorageGranted());
        $this->assertSame(['no_snippet_consent_signal'], $decision->getDenialReasons());
    }

    /**
     * A raw (non-URL-encoded) JSON session with a sid grants both signals.
     */
    public function testWaitForConsentRawJsonCookieGrants(): void
    {
        $this->storeConfigProvider->method('getConsentMode')
            ->willReturn(StoreConfigProviderInterface::CONSENT_MODE_WAIT_FOR_CONSENT);
        $this->cookieManager->method('getCookie')->willReturn('{"sid":"abc","la":1}');

        $decision = $this->makeResolver()->resolve(self::STORE_ID);

        $this->assertTrue($decision->isStorageGranted());
        $this->assertTrue($decision->isMeasurementGranted());
        $this->assertSame([], $decision->getDenialReasons());
    }

    /**
     * A URL-encoded JSON object value (the real-world shape set by the
     * browser snippet) also grants both signals once rawurldecode'd.
     */
    public function testWaitForConsentUrlEncodedJsonCookieGrants(): void
    {
        $this->storeConfigProvider->method('getConsentMode')
            ->willReturn(StoreConfigProviderInterface::CONSENT_MODE_WAIT_FOR_CONSENT);
        $this->cookieManager->method('getCookie')->willReturn(rawurlencode('{"sid":"abc","geo":"US"}'));

        $decision = $this->makeResolver()->resolve(self::STORE_ID);

        $this->assertTrue($decision->isStorageGranted());
        $this->assertTrue($decision->isMeasurementGranted());
        $this->assertSame([], $decision->getDenialReasons());
    }

    /**
     * The cookie name read must be exactly _cws (the true browser-session
     * cookie), never _cwid or _cwvs.
     */
    public function testReadsTheSessionCookieByExactName(): void
    {
        $this->storeConfigProvider->method('getConsentMode')
            ->willReturn(StoreConfigProviderInterface::CONSENT_MODE_WAIT_FOR_CONSENT);
        $this->cookieManager->expects($this->once())
            ->method('getCookie')
            ->with('_cws')
            ->willReturn('{"v":1}');

        $this->makeResolver()->resolve(self::STORE_ID);
    }

    /**
     * The store id passed in must be forwarded to getConsentMode().
     */
    public function testStoreIdIsForwardedToStoreConfigProvider(): void
    {
        $storeId = 9;
        $this->storeConfigProvider->expects($this->once())
            ->method('getConsentMode')
            ->with($storeId)
            ->willReturn(StoreConfigProviderInterface::CONSENT_MODE_CAPTURE_IMMEDIATELY);

        $this->makeResolver()->resolve($storeId);
    }

    /**
     * A JSON object without a session id is not an lm.js session.
     */
    public function testWaitForConsentJsonWithoutASessionIdDenies(): void
    {
        $this->storeConfigProvider->method('getConsentMode')
            ->willReturn(StoreConfigProviderInterface::CONSENT_MODE_WAIT_FOR_CONSENT);
        $this->cookieManager->method('getCookie')->willReturn(rawurlencode('{"v":1}'));

        $this->assertFalse($this->makeResolver()->resolve(self::STORE_ID)->isStorageGranted());
    }

    /**
     * A session Magento minted itself ("mg") is never evidence that the
     * visitor consented in the browser.
     */
    public function testWaitForConsentMagentoMintedSessionDenies(): void
    {
        $this->storeConfigProvider->method('getConsentMode')
            ->willReturn(StoreConfigProviderInterface::CONSENT_MODE_WAIT_FOR_CONSENT);
        $this->cookieManager->method('getCookie')->willReturn(rawurlencode('{"sid":"abc","mg":1}'));

        $decision = $this->makeResolver()->resolve(self::STORE_ID);

        $this->assertFalse($decision->isStorageGranted());
        $this->assertSame(['no_snippet_consent_signal'], $decision->getDenialReasons());
    }
}
