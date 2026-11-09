<?php

declare(strict_types=1);

namespace Cwax\Banner\Test\Unit\Model\Consent;

use Cwax\Banner\Api\Data\ConsentDecisionInterface;
use Cwax\Banner\Api\StoreConfigProviderInterface;
use Cwax\Banner\Model\Consent\ModeConsentResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for ModeConsentResolver: interprets the collector's resolved
 * consentMode, and fails closed when it cannot be resolved.
 */
class ModeConsentResolverTest extends TestCase
{
    private const STORE_ID = 1;

    /** @var StoreConfigProviderInterface&MockObject */
    private StoreConfigProviderInterface $storeConfigProvider;

    protected function setUp(): void
    {
        $this->storeConfigProvider = $this->createMock(StoreConfigProviderInterface::class);
    }

    private function makeResolver(): ModeConsentResolver
    {
        return new ModeConsentResolver($this->storeConfigProvider);
    }

    /**
     * capture_immediately: unconditional grant, no snippet-derived gate.
     */
    public function testCaptureImmediatelyGrantsBothUnconditionally(): void
    {
        $this->storeConfigProvider->method('getConsentMode')
            ->willReturn(StoreConfigProviderInterface::CONSENT_MODE_CAPTURE_IMMEDIATELY);

        $decision = $this->makeResolver()->resolve(self::STORE_ID);

        $this->assertTrue($decision->isStorageGranted());
        $this->assertTrue($decision->isMeasurementGranted());
        $this->assertSame([], $decision->getDenialReasons());
    }

    /**
     * wait_for_consent: this resolver abstains entirely and defers to
     * SnippetIdentityConsentResolver.
     */
    public function testWaitForConsentAbstains(): void
    {
        $this->storeConfigProvider->method('getConsentMode')
            ->willReturn(StoreConfigProviderInterface::CONSENT_MODE_WAIT_FOR_CONSENT);

        $decision = $this->makeResolver()->resolve(self::STORE_ID);

        $this->assertNull($decision->getSignal(ConsentDecisionInterface::SIGNAL_STORAGE));
        $this->assertNull($decision->getSignal(ConsentDecisionInterface::SIGNAL_MEASUREMENT));
        $this->assertSame([], $decision->getDenialReasons());
    }

    /** @return array<string, array{?string}> */
    public static function unresolvedModes(): array
    {
        return [
            'collector unreachable' => [null],
            'unknown mode' => ['something_else'],
        ];
    }

    /**
     * There is no admin fallback policy any more: an unresolved mode denies.
     */
    #[DataProvider('unresolvedModes')]
    public function testAnUnresolvedModeFailsClosed(?string $mode): void
    {
        $this->storeConfigProvider->method('getConsentMode')->willReturn($mode);

        $decision = $this->makeResolver()->resolve(self::STORE_ID);

        $this->assertFalse($decision->getSignal(ConsentDecisionInterface::SIGNAL_STORAGE));
        $this->assertFalse($decision->getSignal(ConsentDecisionInterface::SIGNAL_MEASUREMENT));
        $this->assertSame(['store_config_unavailable'], $decision->getDenialReasons());
    }

    public function testStoreIdIsForwardedToTheStoreConfigProvider(): void
    {
        $this->storeConfigProvider->expects($this->once())
            ->method('getConsentMode')
            ->with(7)
            ->willReturn(StoreConfigProviderInterface::CONSENT_MODE_CAPTURE_IMMEDIATELY);

        $this->makeResolver()->resolve(7);
    }
}
