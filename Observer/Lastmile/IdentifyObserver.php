<?php

declare(strict_types=1);

namespace Cwax\Banner\Observer\Lastmile;

use Cwax\Banner\Model\Lastmile\EmailHasher;
use Cwax\Banner\Model\Lastmile\EventQueue;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Psr\Log\LoggerInterface;

class IdentifyObserver implements ObserverInterface
{
    public function __construct(
        private readonly EventQueue $eventQueue,
        private readonly EmailHasher $emailHasher,
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
            $this->eventQueue->enqueue('identify', [
                'customerId' => $customerId,
                'emailHash' => $this->emailHasher->hash($email),
            ]);
        } catch (\Throwable $exception) {
            // Analytics must never stop login or registration.
            $this->logger->error('ConversionWax Last Mile could not build an identify event.', [
                'exception' => $exception,
            ]);
        }
    }
}
