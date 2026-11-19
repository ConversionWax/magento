<?php

declare(strict_types=1);

namespace Cwax\Banner\Observer\Lastmile;

use Cwax\Banner\Model\Lastmile\IdentifyRecorder;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Newsletter\Model\Subscriber;
use Psr\Log\LoggerInterface;

/**
 * newsletter_subscriber_save_after (storefront only): a signup sends one identify.
 *
 * A signup is a save that moves the subscriber into Subscribed or Not Activated (confirmation needed)
 * from no row, Unsubscribed or Unconfirmed. The confirmation link (Not Activated to Subscribed) sends
 * nothing, because it is often opened on another device and the signup already sent the identify.
 * A guest signup has the email hash only; a logged-in signup also has the customer ID.
 */
class NewsletterSubscriberObserver implements ObserverInterface
{
    private const SIGNED_UP = [Subscriber::STATUS_SUBSCRIBED, Subscriber::STATUS_NOT_ACTIVE];

    public function __construct(
        private readonly IdentifyRecorder $identifyRecorder,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(Observer $observer): void
    {
        try {
            $subscriber = $observer->getEvent()->getData('subscriber');
            if (!$subscriber instanceof Subscriber) {
                return;
            }
            if (!in_array((int) $subscriber->getData('subscriber_status'), self::SIGNED_UP, true)) {
                return;
            }
            $before = $subscriber->getOrigData('subscriber_status');
            if ($before !== null && in_array((int) $before, self::SIGNED_UP, true)) {
                return;
            }
            $customerId = (int) $subscriber->getData('customer_id');
            $this->identifyRecorder->identifyFromEmail(
                $customerId > 0 ? (string) $customerId : null,
                (string) $subscriber->getData('subscriber_email')
            );
        } catch (\Throwable $exception) {
            // Analytics must never stop a newsletter signup.
            $this->logger->error('ConversionWax Last Mile could not handle a newsletter signup.', [
                'exception' => $exception,
            ]);
        }
    }
}
