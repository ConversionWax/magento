<?php

declare(strict_types=1);

namespace Cwax\Banner\Model\Consent;

use Cwax\Banner\Api\ConsentResolverInterface;
use Cwax\Banner\Api\Data\ConsentDecisionInterface;
use Cwax\Banner\Api\StoreConfigProviderInterface;

class ModeConsentResolver implements ConsentResolverInterface
{
    private const DENIAL_REASON_UNRESOLVED = 'store_config_unavailable';

    public function __construct(
        private readonly StoreConfigProviderInterface $storeConfigProvider
    ) {
    }

    public function resolve(int $storeId): ConsentDecisionInterface
    {
        $mode = $this->storeConfigProvider->getConsentMode($storeId);

        if ($mode === StoreConfigProviderInterface::CONSENT_MODE_CAPTURE_IMMEDIATELY) {
            // Matches lm.js's own unconditional behavior: no
            // snippet-derived gate in this mode.
            return new ConsentDecision([
                ConsentDecisionInterface::SIGNAL_STORAGE => true,
                ConsentDecisionInterface::SIGNAL_MEASUREMENT => true,
            ]);
        }

        if ($mode === StoreConfigProviderInterface::CONSENT_MODE_WAIT_FOR_CONSENT) {
            // Abstain and let SnippetIdentityConsentResolver decide from
            // the cookie lm.js writes once consent is given.
            return new ConsentDecision([]);
        }

        // consentMode could not be resolved at all (network failure,
        // timeout, unknown store, malformed response): fail closed.
        return new ConsentDecision(
            [
                ConsentDecisionInterface::SIGNAL_STORAGE => false,
                ConsentDecisionInterface::SIGNAL_MEASUREMENT => false,
            ],
            [self::DENIAL_REASON_UNRESOLVED]
        );
    }
}
