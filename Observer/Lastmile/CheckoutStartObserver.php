<?php

declare(strict_types=1);

namespace Cwax\Banner\Observer\Lastmile;

use Cwax\Banner\Model\Lastmile\EventQueue;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;

class CheckoutStartObserver implements ObserverInterface
{
    private const CHECKOUT_START_FIRED_KEY = 'cwax_lastmile_checkout_start_fired';

    public function __construct(
        private readonly EventQueue $eventQueue,
        private readonly CheckoutSession $checkoutSession
    ) {
    }

    public function execute(Observer $observer): void
    {
        try {
            $quote = $this->checkoutSession->getQuote();
        } catch (\Throwable) {
            return;
        }
        if (!is_object($quote)
            || !method_exists($quote, 'getItemsCount')
            || (int) $quote->getItemsCount() === 0
        ) {
            // The controller redirects to the cart when it is empty; do not record a checkout start.
            return;
        }

        if ($this->checkoutSession->getData(self::CHECKOUT_START_FIRED_KEY)) {
            // Already fired once for this checkout session; a reload must not queue a new event.
            return;
        }
        $this->checkoutSession->setData(self::CHECKOUT_START_FIRED_KEY, true);

        $this->eventQueue->enqueue('checkout_start');
    }
}
