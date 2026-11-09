<?php

declare(strict_types=1);

namespace Cwax\Banner\Model\Consent;

use Cwax\Banner\Api\ConsentResolverInterface;
use Cwax\Banner\Api\Data\ConsentDecisionInterface;
use Cwax\Banner\Api\StoreConfigProviderInterface;
use Magento\Framework\Stdlib\CookieManagerInterface;

class SnippetIdentityConsentResolver implements ConsentResolverInterface
{
    /**
     * Must match Cwax\Banner\Model\Lastmile\VisitorContext::BROWSER_SESSION_COOKIE.
     * This is lm.js's own true session cookie (no Max-Age -
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
        private readonly CookieManagerInterface $cookieManager
    ) {
    }

    public function resolve(int $storeId): ConsentDecisionInterface
    {
        $mode = $this->storeConfigProvider->getConsentMode($storeId);
        if ($mode !== StoreConfigProviderInterface::CONSENT_MODE_WAIT_FOR_CONSENT) {
            // capture_immediately: no snippet-derived gate. Unresolved mode:
            // ModeConsentResolver denies on its own.
            return new ConsentDecision([]);
        }

        $raw = (string) $this->cookieManager->getCookie(self::BROWSER_SESSION_COOKIE);
        $granted = $this->isSnippetSession($raw);

        return new ConsentDecision(
            [
                ConsentDecisionInterface::SIGNAL_STORAGE => $granted,
                ConsentDecisionInterface::SIGNAL_MEASUREMENT => $granted,
            ],
            $granted ? [] : [self::DENIAL_REASON]
        );
    }

    /**
     * A URL-encoded JSON object with a session id, written by lm.js. A session Magento minted itself (the
     * "mg" key, see VisitorContext) is not evidence of consent: the server only mints after consent was
     * already established some other way, so it must not grant on its own echo.
     */
    private function isSnippetSession(string $raw): bool
    {
        if ($raw === '' || strlen($raw) > 4096) {
            return false;
        }
        try {
            $decoded = json_decode(rawurldecode($raw), true, 8, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return false;
        }

        return is_array($decoded)
            && is_string($decoded['sid'] ?? null)
            && $decoded['sid'] !== ''
            && !isset($decoded['mg']);
    }
}
