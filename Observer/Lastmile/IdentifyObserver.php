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
    /**
     * Customers already identified in this request. Registration dispatches customer_register_success
     * and then customer_login for the same new customer, and Magento shares this observer instance
     * across both, so this keeps that to one identify event.
     *
     * @var array<string, true>
     */
    private array $identified = [];

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
            if ($customerId === '' || isset($this->identified[$customerId])) {
                return;
            }
            $this->identified[$customerId] = true;
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
