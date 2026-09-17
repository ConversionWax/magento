<?php

declare(strict_types=1);

namespace Cwax\Banner\Model\Consent;

use Cwax\Banner\Api\ConsentResolverInterface;
use Cwax\Banner\Api\Data\ConsentDecisionInterface;
use Cwax\Banner\Api\StoreConfigProviderInterface;
use Cwax\Banner\Model\Config;
use Magento\Framework\Stdlib\CookieManagerInterface;

class SnippetIdentityConsentResolver implements ConsentResolverInterface
{
    /**
     * Must match Cwax\Banner\Model\Lastmile\VisitorContext::BROWSER_SESSION_COOKIE.
     * This is the browser snippet's own true session cookie (no Max-Age -
     * conversionwax_lastmile packages/snippet/src/cookies.ts CWS), not
     * _cwid/_cwvs. Those two persist 400 days and are minted by the same
     * call as this one (session.ts ensureIdentity()), so their presence
     * proves only that consent was granted at some point in that window,
     * not that it applies to this request. _cws resets every browser
     * session, so under wait_for_consent it can only exist here because
     * this session's own boot() already required a genuine cw.consent(true)
     * dispatch - the tightest signal available server-side.
     */
    private const BROWSER_SESSION_COOKIE = '_cws';
    private const DENIAL_REASON = 'no_snippet_consent_signal';

    public function __construct(
        private readonly StoreConfigProviderInterface $storeConfigProvider,
        private readonly CookieManagerInterface $cookieManager,
        private readonly Config $config
    ) {
    }

    public function resolve(int $storeId): ConsentDecisionInterface
    {
        $mode = $this->storeConfigProvider->getConsentMode($storeId);
        if ($mode !== StoreConfigProviderInterface::CONSENT_MODE_WAIT_FOR_CONSENT) {
            // capture_immediately: no snippet-derived gate. Unresolved mode:
            // handled entirely by ModeConsentResolver's fallback.
            return new ConsentDecision([]);
        }

        if (!$this->config->isSnippetInstalled($storeId)) {
            // No browser snippet runs on this store: the _cws cookie this
            // resolver looks for can never appear, so this signal must not
            // vote at all. ModeConsentResolver's fallback policy (grant,
            // magento_cookie, or deny) is the sole voice for this store.
            return new ConsentDecision([]);
        }

        $raw = (string) $this->cookieManager->getCookie(self::BROWSER_SESSION_COOKIE);
        // Cheap shape check only (URL-encoded JSON object) - this resolver
        // does not need to parse session fields, only detect presence of a
        // plausible, non-empty value.
        $granted = $raw !== '' && str_starts_with(rawurldecode($raw), '{');

        return new ConsentDecision(
            [
                ConsentDecisionInterface::SIGNAL_STORAGE => $granted,
                ConsentDecisionInterface::SIGNAL_MEASUREMENT => $granted,
            ],
            $granted ? [] : [self::DENIAL_REASON]
        );
    }
}
