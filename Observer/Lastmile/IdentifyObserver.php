<?php

declare(strict_types=1);

namespace Cwax\Banner\Observer\Lastmile;

use Cwax\Banner\Model\Lastmile\IdentifyRecorder;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Psr\Log\LoggerInterface;

class IdentifyObserver implements ObserverInterface
{
    /**
     * Registration dispatches customer_register_success and then customer_login for the same new
     * customer in one request. IdentifyRecorder (one instance per request) keeps that to one identify
     * event, and also skips it when a newsletter signup in the same request already sent it.
     */
    public function __construct(
        private readonly IdentifyRecorder $identifyRecorder,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(Observer $observer): void
    {
        try {
            $customer = $observer->getEvent()->getCustomer();
            if (!is_object($customer) || !method_exists($customer, 'getId')) {
                return;
            }
            $customerId = (string) $customer->getId();
            if ($customerId === '') {
                return;
            }
            $email = method_exists($customer, 'getEmail') ? (string) $customer->getEmail() : '';
            $this->identifyRecorder->identifyCustomer($customerId, $email);
        } catch (\Throwable $exception) {
            // Analytics must never stop login or registration.
            $this->logger->error('ConversionWax Last Mile could not build an identify event.', [
                'exception' => $exception,
            ]);
        }
    }
}
