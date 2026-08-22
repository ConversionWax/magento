<?php

declare(strict_types=1);

namespace Cwax\Banner\Model\Consent;

use Cwax\Banner\Api\Data\ConsentDecisionInterface;

final class ConsentDecision implements ConsentDecisionInterface
{
    /**
     * @param array<string, bool> $signals keyed by a SIGNAL_* constant; a
     *     missing key means no opinion was expressed for that signal.
     * @param string[] $denialReasons short, PII-free reason codes
     */
    public function __construct(
        private readonly array $signals,
        private readonly array $denialReasons = []
    ) {
    }

    public function isStorageGranted(): bool
    {
        return $this->getSignal(self::SIGNAL_STORAGE) === true;
    }

    public function isMeasurementGranted(): bool
    {
        return $this->getSignal(self::SIGNAL_MEASUREMENT) === true;
    }

    public function getSignal(string $signal): ?bool
    {
        return $this->signals[$signal] ?? null;
    }

    public function getDenialReasons(): array
    {
        return $this->denialReasons;
    }
}
