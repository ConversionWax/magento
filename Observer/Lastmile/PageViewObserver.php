<?php

declare(strict_types=1);

namespace Cwax\Banner\Observer\Lastmile;

use Cwax\Banner\Model\Lastmile\EventQueue;
use Magento\Framework\App\Request\Http;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;

class PageViewObserver implements ObserverInterface
{
    public function __construct(
        private readonly EventQueue $eventQueue,
        private readonly Http $request
    ) {
    }

    public function execute(Observer $observer): void
    {
        if (!$this->request->isGet() || $this->request->isXmlHttpRequest()) {
            return;
        }

        $response = $observer->getEvent()->getResponse();
        if (is_object($response)
            && method_exists($response, 'getStatusCode')
            && (int) $response->getStatusCode() >= 400
        ) {
            return;
        }

        $this->eventQueue->enqueue('page_view');
    }
}
