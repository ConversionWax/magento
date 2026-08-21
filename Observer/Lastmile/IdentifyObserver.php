<?php

declare(strict_types=1);

namespace Cwax\Banner\Observer\Lastmile;

use Cwax\Banner\Model\Lastmile\EventQueue;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;

class IdentifyObserver implements ObserverInterface
{
    public function __construct(private readonly EventQueue $eventQueue)
    {
    }

    public function execute(Observer $observer): void
    {
        $customer = $observer->getEvent()->getCustomer();
        if (!is_object($customer) || !method_exists($customer, 'getId')) {
            return;
        }
        $customerId = (string) $customer->getId();
        if ($customerId === '') {
            return;
        }
        $email = method_exists($customer, 'getEmail')
            ? strtolower(trim((string) $customer->getEmail()))
            : '';
        $this->eventQueue->enqueue('identify', [
            'customerId' => $customerId,
            'emailHash' => $email !== '' ? hash('sha256', $email) : null,
        ]);
    }
}
