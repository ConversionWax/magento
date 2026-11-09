<?php

declare(strict_types=1);

namespace Cwax\Banner\Observer\Lastmile;

use Cwax\Banner\Model\Lastmile\VisitorContext;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Psr\Log\LoggerInterface;

/**
 * Writes the Last Mile identity cookies, if any identity was resolved during
 * this request, now that the response's Cache-Control header is final.
 *
 * Registered on controller_front_send_response_before (see
 * etc/frontend/events.xml and etc/webapi_rest/events.xml), so identity
 * resolved by any commerce observer earlier in the request (add to cart,
 * checkout start, purchase, identify) is flushed with the same response:
 * a `_cwid` Magento minted, a refresh of the one lm.js wrote, and a `_cws`
 * Magento owns. A request that resolved no identity writes nothing.
 */
class IdentityFlushObserver implements ObserverInterface
{
    public function __construct(
        private readonly VisitorContext $visitorContext,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(Observer $observer): void
    {
        try {
            $this->visitorContext->flush();
        } catch (\Throwable $exception) {
            // Tracking must never break a storefront response.
            $this->logger->warning('ConversionWax Last Mile could not flush visitor identity.', [
                'exception' => $exception,
            ]);
        }
    }
}
