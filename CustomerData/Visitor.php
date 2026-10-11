<?php

declare(strict_types=1);

namespace Cwax\Banner\CustomerData;

use Cwax\Banner\Model\Config;
use Cwax\Banner\Model\Lastmile\VisitorContext;
use Cwax\Banner\Model\Lastmile\VisitorSyncService;
use Magento\Customer\CustomerData\SectionSourceInterface;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Private-content section "cwax-visitor". It is loaded by AJAX after the (possibly cached) page, so
 * full-page cache never stores visitor cookies or claims. The side effect is the point: when consent
 * allows, the response carries Set-Cookie for _cws and _cwvs. The returned data holds only state, synced
 * and sid: no claims, no visitor id, no summary, no token.
 */
class Visitor implements SectionSourceInterface
{
    public function __construct(
        private readonly Config $config,
        private readonly StoreManagerInterface $storeManager,
        private readonly VisitorSyncService $syncService,
        private readonly VisitorContext $visitorContext,
        private readonly LoggerInterface $logger
    ) {
    }

    /** @return array<string, mixed> */
    public function getSectionData(): array
    {
        try {
            $storeId = (int) $this->storeManager->getStore()->getId();
            if (!$this->config->isConfigured($storeId)) {
                return ['state' => 'off'];
            }

            // Consent is decided inside the sync service (ConsentResolverInterface, storage signal). Without
            // storage consent it clears stale cookies and returns 'denied' before anything else happens.
            $result = $this->syncService->sync($storeId);
            if (($result['state'] ?? '') !== 'granted') {
                // No consent: no claims, and the visitor context is not touched.
                return ['state' => 'denied'];
            }
            // Persist a cleared pending purchase, otherwise it would be sent again.
            $this->visitorContext->flush();

            return [
                'state' => 'granted',
                'synced' => (bool) ($result['synced'] ?? false),
                'sid' => (string) ($result['sid'] ?? ''),
            ];
        } catch (\Throwable $exception) {
            $this->logger->warning('ConversionWax Last Mile visitor section failed.', [
                'exception' => $exception,
            ]);

            return ['state' => 'error'];
        }
    }
}
