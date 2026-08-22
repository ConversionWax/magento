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
            try {
                $area = $this->appState->getAreaCode();
            } catch (\Throwable) {
                // Area code is not always set this early (eg CLI/setup context); expected, not an error.
                $this->logger->debug('ConversionWax Last Mile skipped an add-to-cart event: area code not set.');
                return;
            }
            if (!in_array($area, ['frontend', 'webapi_rest', 'graphql'], true)) {
                return;
            }
            $items = $observer->getEvent()->getItems();
            if (!is_array($items) || $items === []) {
                return;
            }
            foreach ($items as $item) {
                $this->enqueueItem($item);
            }
        } catch (\Throwable $exception) {
            // Analytics must never stop cart mutation.
            $this->logger->error('ConversionWax Last Mile could not build an add-to-cart event.', [
                'exception' => $exception,
            ]);
        }
    }

    private function enqueueItem(mixed $item): void
    {
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

        $attributes = ['productId' => $sku];
        if (method_exists($item, 'getRowTotal')) {
            // Row total already reflects quantity, in major currency units.
            $attributes['value'] = (float) $item->getRowTotal();
        } elseif (method_exists($item, 'getQty') && method_exists($item, 'getPrice')) {
            $attributes['value'] = (float) $item->getQty() * (float) $item->getPrice();
        }

        $this->eventQueue->enqueue('add_to_cart', $attributes);
    }
}
