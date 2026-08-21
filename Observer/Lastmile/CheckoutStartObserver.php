<?php

declare(strict_types=1);

namespace Cwax\Banner\Observer\Lastmile;

use Cwax\Banner\Model\Lastmile\EventQueue;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;

class CheckoutStartObserver implements ObserverInterface
{
    public function __construct(private readonly EventQueue $eventQueue)
    {
    }

    public function execute(Observer $observer): void
    {
        $this->eventQueue->enqueue('checkout_start');
    }
}
