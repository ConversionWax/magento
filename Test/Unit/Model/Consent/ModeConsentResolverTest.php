<?php

declare(strict_types=1);

namespace Cwax\Banner\Test\Unit\Model\Consent;

use Cwax\Banner\Api\Data\ConsentDecisionInterface;
use Cwax\Banner\Api\StoreConfigProviderInterface;
use Cwax\Banner\Model\Config;
use Cwax\Banner\Model\Config\Source\ConsentFallbackPolicy;
use Cwax\Banner\Model\Consent\ModeConsentResolver;
use Magento\Cookie\Helper\Cookie as CookieHelper;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for ModeConsentResolver: interprets the collector's resolved
 * consentMode, and applies the admin fallback policy when the mode cannot
 * be resolved or no browser snippet is installed.
 */
class ModeConsentResolverTest extends TestCase
{
    private const STORE_ID = 1;

    /** @var Config&MockObject */
    private Config $config;

    /** @var StoreConfigProviderInterface&MockObject */
    private StoreConfigProviderInterface $storeConfigProvider;

    /** @var CookieHelper&MockObject */
    private CookieHelper $cookieHelper;

    protected function setUp(): void
    {
        $this->config = $this->createMock(Config::class);
        $this->storeConfigProvider = $this->createMock(StoreConfigProviderInterface::class);
        $this->cookieHelper = $this->createMock(CookieHelper::class);
    }

    private function makeResolver(): ModeConsentResolver
    {
        return new ModeConsentResolver($this->config, $this->storeConfigProvider, $this->cookieHelper);
    }

    /**
     * capture_immediately: unconditional grant, no snippet-derived gate, no
     * dependency on the fallback policy at all.
     */
    public function testCaptureImmediatelyGrantsBothUnconditionally(): void
    {
        $this->storeConfigProvider->method('getConsentMode')
            ->willReturn(StoreConfigProviderInterface::CONSENT_MODE_CAPTURE_IMMEDIATELY);
        $this->config->expects($this->never())->method('getConsentFallbackPolicy');

        $decision = $this->makeResolver()->resolve(self::STORE_ID);

        $this->assertTrue($decision->isStorageGranted());
        $this->assertTrue($decision->isMeasurementGranted());
        $this->assertSame([], $decision->getDenialReasons());
    }

    /**
     * wait_for_consent with a real snippet installed: this resolver must
     * abstain entirely and defer to SnippetIdentityConsentResolver.
     */
    public function testWaitForConsentWithSnippetInstalledAbstains(): void
    {
        $this->storeConfigProvider->method('getConsentMode')
            ->willReturn(StoreConfigProviderInterface::CONSENT_MODE_WAIT_FOR_CONSENT);
        $this->config->method('isSnippetInstalled')->willReturn(true);
        $this->config->expects($this->never())->method('getConsentFallbackPolicy');

        $decision = $this->makeResolver()->resolve(self::STORE_ID);

        $this->assertNull($decision->getSignal(ConsentDecisionInterface::SIGNAL_STORAGE));
        $this->assertNull($decision->getSignal(ConsentDecisionInterface::SIGNAL_MEASUREMENT));
        $this->assertSame([], $decision->getDenialReasons());
    }

    /**
     * wait_for_consent with no snippet installed and fallback policy GRANT:
     * unconditional grant.
     */
    public function testWaitForConsentNoSnippetFallbackGrant(): void
    {
        $this->storeConfigProvider->method('getConsentMode')
            ->willReturn(StoreConfigProviderInterface::CONSENT_MODE_WAIT_FOR_CONSENT);
        $this->config->method('isSnippetInstalled')->willReturn(false);
        $this->config->method('getConsentFallbackPolicy')->willReturn(ConsentFallbackPolicy::GRANT);

        $decision = $this->makeResolver()->resolve(self::STORE_ID);

        $this->assertTrue($decision->isStorageGranted());
        $this->assertTrue($decision->isMeasurementGranted());
        $this->assertSame([], $decision->getDenialReasons());
    }

    /**
     * wait_for_consent with no snippet installed and fallback policy DENY:
     * unconditional denial with the unresolved reason code.
     */
    public function testWaitForConsentNoSnippetFallbackDeny(): void
    {
        $this->storeConfigProvider->method('getConsentMode')
            ->willReturn(StoreConfigProviderInterface::CONSENT_MODE_WAIT_FOR_CONSENT);
        $this->config->method('isSnippetInstalled')->willReturn(false);
        $this->config->method('getConsentFallbackPolicy')->willReturn(ConsentFallbackPolicy::DENY);

        $decision = $this->makeResolver()->resolve(self::STORE_ID);

        $this->assertFalse($decision->isStorageGranted());
        $this->assertFalse($decision->isMeasurementGranted());
        $this->assertSame(['store_config_unavailable'], $decision->getDenialReasons());
    }

    /**
     * Unresolved consentMode (null - network failure, unknown store, etc.)
     * goes through the same fallback path as "no snippet installed",
     * independent of whatever isSnippetInstalled() returns.
     */
    public function testUnresolvedModeFallsBackToDenyPolicy(): void
    {
        $this->storeConfigProvider->method('getConsentMode')->willReturn(null);
        $this->config->method('getConsentFallbackPolicy')->willReturn(ConsentFallbackPolicy::DENY);

        $decision = $this->makeResolver()->resolve(self::STORE_ID);

        $this->assertFalse($decision->isStorageGranted());
        $this->assertFalse($decision->isMeasurementGranted());
        $this->assertSame(['store_config_unavailable'], $decision->getDenialReasons());
    }

    public function testUnresolvedModeFallsBackToGrantPolicy(): void
    {
        $this->storeConfigProvider->method('getConsentMode')->willReturn(null);
        $this->config->method('getConsentFallbackPolicy')->willReturn(ConsentFallbackPolicy::GRANT);

        $decision = $this->makeResolver()->resolve(self::STORE_ID);

        $this->assertTrue($decision->isStorageGranted());
        $this->assertTrue($decision->isMeasurementGranted());
    }

    /**
     * magento_cookie fallback, restriction mode enabled and visitor has
     * accepted: the one case that affirmatively grants via this branch.
     */
    public function testMagentoCookieFallbackGrantsWhenRestrictionEnabledAndAccepted(): void
    {
        $this->storeConfigProvider->method('getConsentMode')->willReturn(null);
        $this->config->method('getConsentFallbackPolicy')->willReturn(ConsentFallbackPolicy::MAGENTO_COOKIE);
        $this->cookieHelper->method('isCookieRestrictionModeEnabled')->willReturn(true);
        $this->cookieHelper->method('isUserNotAllowSaveCookie')->willReturn(false);

        $decision = $this->makeResolver()->resolve(self::STORE_ID);

        $this->assertTrue($decision->isStorageGranted());
        $this->assertTrue($decision->isMeasurementGranted());
        $this->assertSame([], $decision->getDenialReasons());
    }

    /**
     * magento_cookie fallback, restriction mode enabled but visitor has not
     * accepted: denies with the fallback-specific reason code.
     */
    public function testMagentoCookieFallbackDeniesWhenRestrictionEnabledAndNotAccepted(): void
    {
        $this->storeConfigProvider->method('getConsentMode')->willReturn(null);
        $this->config->method('getConsentFallbackPolicy')->willReturn(ConsentFallbackPolicy::MAGENTO_COOKIE);
        $this->cookieHelper->method('isCookieRestrictionModeEnabled')->willReturn(true);
        $this->cookieHelper->method('isUserNotAllowSaveCookie')->willReturn(true);

        $decision = $this->makeResolver()->resolve(self::STORE_ID);

        $this->assertFalse($decision->isStorageGranted());
        $this->assertFalse($decision->isMeasurementGranted());
        $this->assertSame(['magento_cookie_restriction_fallback'], $decision->getDenialReasons());
    }

    /**
     * magento_cookie fallback with Magento's own Cookie Restriction Mode
     * disabled entirely on this store: documents actual current behavior.
     * isCookieRestrictionModeEnabled() === false short-circuits the `&&` in
     * resolveFromMagentoCookie(), so $granted is false regardless of
     * isUserNotAllowSaveCookie() - this fallback denies unconditionally
     * whenever restriction mode is off. See finding reported alongside
     * these tests: this is the opposite of how CookieRestrictionConsentResolver
     * itself treats a disabled restriction mode elsewhere in this change
     * (abstain, not deny).
     */
    public function testMagentoCookieFallbackDeniesWhenRestrictionModeDisabled(): void
    {
        $this->storeConfigProvider->method('getConsentMode')->willReturn(null);
        $this->config->method('getConsentFallbackPolicy')->willReturn(ConsentFallbackPolicy::MAGENTO_COOKIE);
        $this->cookieHelper->method('isCookieRestrictionModeEnabled')->willReturn(false);
        $this->cookieHelper->method('isUserNotAllowSaveCookie')->willReturn(false);

        $decision = $this->makeResolver()->resolve(self::STORE_ID);

        $this->assertFalse($decision->isStorageGranted());
        $this->assertFalse($decision->isMeasurementGranted());
        $this->assertSame(['magento_cookie_restriction_fallback'], $decision->getDenialReasons());
    }

    /**
     * The store id passed in must be forwarded to both getConsentMode() and
     * getConsentFallbackPolicy() (the latter is the one call site the spec
     * explicitly calls out as needing the store id, unlike the illustrative
     * buggy snippet in the design doc).
     */
    public function testStoreIdIsForwardedToCollaborators(): void
    {
        $storeId = 7;
        $this->storeConfigProvider->expects($this->once())
            ->method('getConsentMode')
            ->with($storeId)
            ->willReturn(null);
        $this->config->expects($this->once())
            ->method('getConsentFallbackPolicy')
            ->with($storeId)
            ->willReturn(ConsentFallbackPolicy::DENY);

        $this->makeResolver()->resolve($storeId);
    }
}
