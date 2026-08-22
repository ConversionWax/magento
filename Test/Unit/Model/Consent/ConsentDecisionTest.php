<?php

declare(strict_types=1);

namespace Cwax\Banner\Test\Unit\Model\Consent;

use Cwax\Banner\Api\Data\ConsentDecisionInterface;
use Cwax\Banner\Model\Consent\ConsentDecision;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the ConsentDecision value object.
 */
class ConsentDecisionTest extends TestCase
{
    public function testBothSignalsGrantedTrue(): void
    {
        $decision = new ConsentDecision([
            ConsentDecisionInterface::SIGNAL_STORAGE => true,
            ConsentDecisionInterface::SIGNAL_MEASUREMENT => true,
        ]);

        $this->assertTrue($decision->isStorageGranted());
        $this->assertTrue($decision->isMeasurementGranted());
        $this->assertTrue($decision->getSignal(ConsentDecisionInterface::SIGNAL_STORAGE));
        $this->assertTrue($decision->getSignal(ConsentDecisionInterface::SIGNAL_MEASUREMENT));
        $this->assertSame([], $decision->getDenialReasons());
    }

    public function testBothSignalsExplicitlyDenied(): void
    {
        $decision = new ConsentDecision([
            ConsentDecisionInterface::SIGNAL_STORAGE => false,
            ConsentDecisionInterface::SIGNAL_MEASUREMENT => false,
        ], ['some_reason']);

        $this->assertFalse($decision->isStorageGranted());
        $this->assertFalse($decision->isMeasurementGranted());
        $this->assertFalse($decision->getSignal(ConsentDecisionInterface::SIGNAL_STORAGE));
        $this->assertFalse($decision->getSignal(ConsentDecisionInterface::SIGNAL_MEASUREMENT));
        $this->assertSame(['some_reason'], $decision->getDenialReasons());
    }

    /**
     * A signal absent from the constructor array is "no opinion": getSignal()
     * must return null (not false), and the isXGranted() helpers must treat
     * that as not-granted without conflating it with an explicit denial.
     */
    public function testMissingSignalIsNullNotFalse(): void
    {
        $decision = new ConsentDecision([]);

        $this->assertNull($decision->getSignal(ConsentDecisionInterface::SIGNAL_STORAGE));
        $this->assertNull($decision->getSignal(ConsentDecisionInterface::SIGNAL_MEASUREMENT));
        $this->assertFalse($decision->isStorageGranted());
        $this->assertFalse($decision->isMeasurementGranted());
        $this->assertSame([], $decision->getDenialReasons());
    }

    /**
     * The two named signals are independent: one may be granted while the
     * other is absent (per the spec's forward-extensibility design).
     */
    public function testSignalsAreIndependent(): void
    {
        $decision = new ConsentDecision([
            ConsentDecisionInterface::SIGNAL_STORAGE => true,
        ]);

        $this->assertTrue($decision->isStorageGranted());
        $this->assertFalse($decision->isMeasurementGranted());
        $this->assertNull($decision->getSignal(ConsentDecisionInterface::SIGNAL_MEASUREMENT));
    }

    public function testUnknownSignalNameReturnsNull(): void
    {
        $decision = new ConsentDecision([
            ConsentDecisionInterface::SIGNAL_STORAGE => true,
        ]);

        $this->assertNull($decision->getSignal('some_future_signal'));
    }

    public function testDenialReasonsDefaultToEmptyArray(): void
    {
        $decision = new ConsentDecision([ConsentDecisionInterface::SIGNAL_STORAGE => false]);

        $this->assertSame([], $decision->getDenialReasons());
    }
}
