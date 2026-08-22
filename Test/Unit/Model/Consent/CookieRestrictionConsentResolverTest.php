<?php

declare(strict_types=1);

namespace Cwax\Banner\Test\Unit\Model\Consent;

use Cwax\Banner\Api\Data\ConsentDecisionInterface;
use Cwax\Banner\Model\Consent\CookieRestrictionConsentResolver;
use Magento\Cookie\Helper\Cookie as CookieHelper;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for CookieRestrictionConsentResolver: demoted to a veto-only
 * resolver that only ever denies (when restriction mode is enabled and the
 * visitor has not accepted) and otherwise abstains - it never affirmatively
 * grants in the normal flow.
 */
class CookieRestrictionConsentResolverTest extends TestCase
{
    private const STORE_ID = 1;

    private function makeResolver(bool $restrictionModeEnabled, bool $userNotAllowed): CookieRestrictionConsentResolver
    {
        $cookieHelper = $this->createMock(CookieHelper::class);
        $cookieHelper->method('isCookieRestrictionModeEnabled')->willReturn($restrictionModeEnabled);
        $cookieHelper->method('isUserNotAllowSaveCookie')->willReturn($userNotAllowed);

        return new CookieRestrictionConsentResolver($cookieHelper);
    }

    /**
     * Restriction mode ON, visitor has not accepted: the one case this
     * resolver denies.
     */
    public function testRestrictionModeEnabledAndNotAcceptedDenies(): void
    {
        $resolver = $this->makeResolver(true, true);

        $decision = $resolver->resolve(self::STORE_ID);

        $this->assertFalse($decision->isStorageGranted());
        $this->assertFalse($decision->isMeasurementGranted());
        $this->assertSame(['magento_cookie_restriction'], $decision->getDenialReasons());
    }

    /**
     * Restriction mode ON, visitor has accepted: abstain, not an
     * affirmative grant.
     */
    public function testRestrictionModeEnabledAndAcceptedAbstains(): void
    {
        $resolver = $this->makeResolver(true, false);

        $decision = $resolver->resolve(self::STORE_ID);

        $this->assertNull($decision->getSignal(ConsentDecisionInterface::SIGNAL_STORAGE));
        $this->assertNull($decision->getSignal(ConsentDecisionInterface::SIGNAL_MEASUREMENT));
        $this->assertFalse($decision->isStorageGranted());
        $this->assertSame([], $decision->getDenialReasons());
    }

    /**
     * Restriction mode OFF, "not allowed" flag true: mode being off means
     * there is nothing to veto on - must still abstain, not deny.
     */
    public function testRestrictionModeDisabledAbstainsEvenIfNotAllowedFlagSet(): void
    {
        $resolver = $this->makeResolver(false, true);

        $decision = $resolver->resolve(self::STORE_ID);

        $this->assertNull($decision->getSignal(ConsentDecisionInterface::SIGNAL_STORAGE));
        $this->assertNull($decision->getSignal(ConsentDecisionInterface::SIGNAL_MEASUREMENT));
        $this->assertSame([], $decision->getDenialReasons());
    }

    /**
     * Restriction mode OFF, visitor allowed: abstain.
     */
    public function testRestrictionModeDisabledAndAllowedAbstains(): void
    {
        $resolver = $this->makeResolver(false, false);

        $decision = $resolver->resolve(self::STORE_ID);

        $this->assertNull($decision->getSignal(ConsentDecisionInterface::SIGNAL_STORAGE));
        $this->assertNull($decision->getSignal(ConsentDecisionInterface::SIGNAL_MEASUREMENT));
        $this->assertSame([], $decision->getDenialReasons());
    }

    /**
     * This resolver must never affirmatively grant in the normal flow: no
     * combination of inputs should ever produce isStorageGranted() === true.
     *
     * @dataProvider allInputCombinationsProvider
     */
    public function testNeverAffirmativelyGrants(bool $restrictionModeEnabled, bool $userNotAllowed): void
    {
        $resolver = $this->makeResolver($restrictionModeEnabled, $userNotAllowed);

        $decision = $resolver->resolve(self::STORE_ID);

        $this->assertFalse($decision->isStorageGranted());
        $this->assertFalse($decision->isMeasurementGranted());
    }

    /**
     * @return array<string, array{0: bool, 1: bool}>
     */
    public static function allInputCombinationsProvider(): array
    {
        return [
            'enabled, not allowed' => [true, true],
            'enabled, allowed' => [true, false],
            'disabled, not allowed' => [false, true],
            'disabled, allowed' => [false, false],
        ];
    }
}
