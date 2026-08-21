<?php

declare(strict_types=1);

namespace Cwax\Banner\Observer\Lastmile;

use Cwax\Banner\Model\Lastmile\EventQueue;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;

class ProductViewObserver implements ObserverInterface
{
    public function __construct(private readonly EventQueue $eventQueue)
    {
    }

    public function execute(Observer $observer): void
    {
        $product = $observer->getEvent()->getProduct();
        if (!is_object($product) || !method_exists($product, 'getSku')) {
            return;
        }
        $this->eventQueue->enqueue('product_view', [
            'productId' => (string) $product->getSku(),
        ]);
    }
}
