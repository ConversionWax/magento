<?php

declare(strict_types=1);

namespace Cwax\Banner\Model\Consent;

use Cwax\Banner\Api\ConsentResolverInterface;
use Cwax\Banner\Api\Data\ConsentDecisionInterface;
use Magento\Cookie\Helper\Cookie as CookieHelper;

class CookieRestrictionConsentResolver implements ConsentResolverInterface
{
    private const DENIAL_REASON = 'magento_cookie_restriction';

    public function __construct(
        private readonly CookieHelper $cookieHelper
    ) {
    }

    public function resolve(int $storeId): ConsentDecisionInterface
    {
        if ($this->cookieHelper->isCookieRestrictionModeEnabled()
            && $this->cookieHelper->isUserNotAllowSaveCookie()
        ) {
            return new ConsentDecision(
                [
                    ConsentDecisionInterface::SIGNAL_STORAGE => false,
                    ConsentDecisionInterface::SIGNAL_MEASUREMENT => false,
                ],
                [self::DENIAL_REASON]
            );
        }

        // Demoted from primary gate to veto-only: restriction mode being
        // off, or not yet decided, is no longer treated as an affirmative
        // grant here.
        return new ConsentDecision([]);
    }
}
