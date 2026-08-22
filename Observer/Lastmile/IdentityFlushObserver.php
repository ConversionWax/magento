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
 * Registered independently of PageViewObserver on the same
 * controller_front_send_response_before event (see etc/frontend/events.xml)
 * so the flush still runs when PageViewObserver bails out early - eg a
 * non-GET request, an XHR request, a non-2xx status, a non-HTML response, or
 * a likely bot user agent. This observer is declared after PageViewObserver
 * in that file, so a page view whose identity is first resolved inside
 * PageViewObserver's own call is still flushed here in the same event pass.
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
