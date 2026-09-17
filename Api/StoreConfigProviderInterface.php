<?php

declare(strict_types=1);

namespace Cwax\Banner\Api;

interface StoreConfigProviderInterface
{
    public const CONSENT_MODE_CAPTURE_IMMEDIATELY = 'capture_immediately';
    public const CONSENT_MODE_WAIT_FOR_CONSENT = 'wait_for_consent';

    /**
     * @return string|null one of the CONSENT_MODE_* constants, or null when
     *     the collector's store config could not be resolved (network
     *     failure, timeout, invalid web property id, non-200 response, or a
     *     response missing/mismatching consentMode). Never throws.
     */
    public function getConsentMode(int $storeId): ?string;
}
