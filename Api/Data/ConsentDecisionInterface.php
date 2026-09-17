<?php

declare(strict_types=1);

namespace Cwax\Banner\Api\Data;

interface ConsentDecisionInterface
{
    public const SIGNAL_STORAGE = 'storage';
    public const SIGNAL_MEASUREMENT = 'measurement';

    public function isStorageGranted(): bool;

    public function isMeasurementGranted(): bool;

    /**
     * @return bool|null null when no resolver expressed an opinion on this
     *     signal - distinct from an explicit denial.
     */
    public function getSignal(string $signal): ?bool;

    /** @return string[] short, PII-free reason codes explaining any denial */
    public function getDenialReasons(): array;
}
