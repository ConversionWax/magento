<?php

declare(strict_types=1);

namespace Cwax\Banner\Observer\Lastmile;

use Cwax\Banner\Model\Lastmile\EventQueue;
use Magento\Framework\App\State;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Psr\Log\LoggerInterface;

class AddToCartObserver implements ObserverInterface
{
    public function __construct(
        private readonly EventQueue $eventQueue,
        private readonly State $appState,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(Observer $observer): void
    {
        try {
            $area = $this->appState->getAreaCode();
            if (!in_array($area, ['frontend', 'webapi_rest', 'graphql'], true)) {
                return;
            }
            $items = $observer->getEvent()->getItems();
            if (!is_array($items) || $items === []) {
                return;
            }
            $item = reset($items);
            if (!is_object($item)) {
                return;
            }
            $sku = method_exists($item, 'getSku') ? (string) $item->getSku() : '';
            if ($sku === '' && method_exists($item, 'getProduct')) {
                $product = $item->getProduct();
                $sku = is_object($product) && method_exists($product, 'getSku')
                    ? (string) $product->getSku()
                    : '';
            }
            if ($sku === '') {
                return;
            }
            $this->eventQueue->enqueue('add_to_cart', ['productId' => $sku]);
        } catch (\Throwable $exception) {
            // Analytics must never stop cart mutation.
            $this->logger->error('ConversionWax Last Mile could not build an add-to-cart event.', [
                'exception' => $exception,
            ]);
        }
    }
}
