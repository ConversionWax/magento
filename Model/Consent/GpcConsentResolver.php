<?php

declare(strict_types=1);

namespace Cwax\Banner\Model\Consent;

use Cwax\Banner\Api\ConsentResolverInterface;
use Cwax\Banner\Api\Data\ConsentDecisionInterface;
use Magento\Framework\App\RequestInterface;

class GpcConsentResolver implements ConsentResolverInterface
{
    private const HEADER_SERVER_KEY = 'HTTP_SEC_GPC';
    private const HEADER_VALUE_SIGNAL = '1';
    private const DENIAL_REASON = 'sec_gpc';

    public function __construct(
        private readonly RequestInterface $request
    ) {
    }

    public function resolve(int $storeId): ConsentDecisionInterface
    {
        $header = (string) $this->request->getServer(self::HEADER_SERVER_KEY);
        if ($header === self::HEADER_VALUE_SIGNAL) {
            return new ConsentDecision(
                [
                    ConsentDecisionInterface::SIGNAL_STORAGE => false,
                    ConsentDecisionInterface::SIGNAL_MEASUREMENT => false,
                ],
                [self::DENIAL_REASON]
            );
        }

        // Absence of the header is not evidence of anything - abstain.
        return new ConsentDecision([]);
    }
}
