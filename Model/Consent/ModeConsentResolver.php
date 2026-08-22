<?php

declare(strict_types=1);

namespace Cwax\Banner\Model\Consent;

use Cwax\Banner\Api\ConsentResolverInterface;
use Cwax\Banner\Api\Data\ConsentDecisionInterface;
use Cwax\Banner\Api\StoreConfigProviderInterface;
use Cwax\Banner\Model\Config;
use Cwax\Banner\Model\Config\Source\ConsentFallbackPolicy;
use Magento\Cookie\Helper\Cookie as CookieHelper;

class ModeConsentResolver implements ConsentResolverInterface
{
    private const DENIAL_REASON_UNRESOLVED = 'store_config_unavailable';
    private const DENIAL_REASON_MAGENTO_COOKIE_FALLBACK = 'magento_cookie_restriction_fallback';

    public function __construct(
        private readonly Config $config,
        private readonly StoreConfigProviderInterface $storeConfigProvider,
        private readonly CookieHelper $cookieHelper
    ) {
    }

    public function resolve(int $storeId): ConsentDecisionInterface
    {
        $mode = $this->storeConfigProvider->getConsentMode($storeId);

        if ($mode === StoreConfigProviderInterface::CONSENT_MODE_CAPTURE_IMMEDIATELY) {
            // Matches the snippet's own unconditional behavior: no
            // snippet-derived gate in this mode.
            return new ConsentDecision([
                ConsentDecisionInterface::SIGNAL_STORAGE => true,
                ConsentDecisionInterface::SIGNAL_MEASUREMENT => true,
            ]);
        }

        if ($mode === StoreConfigProviderInterface::CONSENT_MODE_WAIT_FOR_CONSENT) {
            if (!$this->config->isSnippetInstalled($storeId)) {
                // No browser snippet ever runs on this store: the cookie
                // signal SnippetIdentityConsentResolver looks for can never
                // appear. Apply the admin fallback policy instead of
                // denying forever by default.
                return $this->resolveFallback($storeId);
            }

            // A real snippet is installed: abstain and let
            // SnippetIdentityConsentResolver decide from the cookie.
            return new ConsentDecision([]);
        }

        // consentMode could not be resolved at all (network failure,
        // timeout, unknown store, malformed response).
        return $this->resolveFallback($storeId);
    }

    private function resolveFallback(int $storeId): ConsentDecisionInterface
    {
        return match ($this->config->getConsentFallbackPolicy($storeId)) {
            ConsentFallbackPolicy::GRANT => new ConsentDecision([
                ConsentDecisionInterface::SIGNAL_STORAGE => true,
                ConsentDecisionInterface::SIGNAL_MEASUREMENT => true,
            ]),
            ConsentFallbackPolicy::MAGENTO_COOKIE => $this->resolveFromMagentoCookie(),
            default => new ConsentDecision(
                [
                    ConsentDecisionInterface::SIGNAL_STORAGE => false,
                    ConsentDecisionInterface::SIGNAL_MEASUREMENT => false,
                ],
                [self::DENIAL_REASON_UNRESOLVED]
            ),
        };
    }

    private function resolveFromMagentoCookie(): ConsentDecisionInterface
    {
        $granted = $this->cookieHelper->isCookieRestrictionModeEnabled()
            && !$this->cookieHelper->isUserNotAllowSaveCookie();

        return new ConsentDecision(
            [
                ConsentDecisionInterface::SIGNAL_STORAGE => $granted,
                ConsentDecisionInterface::SIGNAL_MEASUREMENT => $granted,
            ],
            $granted ? [] : [self::DENIAL_REASON_MAGENTO_COOKIE_FALLBACK]
        );
    }
}
