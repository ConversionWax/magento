<?php

declare(strict_types=1);

namespace Cwax\Banner\Observer\Lastmile;

use Cwax\Banner\Model\Lastmile\IdentifyRecorder;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Quote\Model\Quote;
use Psr\Log\LoggerInterface;

/**
 * sales_quote_save_after (storefront and REST only): a guest quote whose email changed sends one
 * identify with the email hash and no customer ID.
 *
 * Hyva Checkout, third-party one page checkouts and Magento's own order placement write the guest
 * email to quote.customer_email. Luma checkout writes it only to the billing address before the
 * order; GuestPaymentEmailPlugin covers that step.
 */
class GuestQuoteEmailObserver implements ObserverInterface
{
    public function __construct(
        private readonly IdentifyRecorder $identifyRecorder,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(Observer $observer): void
    {
        try {
            $quote = $observer->getEvent()->getData('quote');
            if (!$quote instanceof Quote) {
                return;
            }
            if ((int) $quote->getData('customer_id') > 0) {
                // A logged-in customer was identified at login.
                return;
            }
            if (!$quote->dataHasChangedFor('customer_email')) {
                return;
            }
            $email = (string) $quote->getData('customer_email');
            if ($email === '') {
                return;
            }
            $this->identifyRecorder->identifyFromEmail(null, $email);
        } catch (\Throwable $exception) {
            // Analytics must never stop a quote save.
            $this->logger->error('ConversionWax Last Mile could not handle a guest checkout email.', [
                'exception' => $exception,
            ]);
        }
    }
}
