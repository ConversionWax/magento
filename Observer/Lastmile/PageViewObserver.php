<?php

declare(strict_types=1);

namespace Cwax\Banner\Observer\Lastmile;

use Cwax\Banner\Model\Lastmile\EventQueue;
use Magento\Framework\App\Request\Http;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;

class PageViewObserver implements ObserverInterface
{
    /**
     * Case-insensitive substrings that identify common crawlers and HTTP
     * client libraries in the User-Agent header. Not exhaustive; this only
     * needs to keep the most obvious non-human traffic out of page_view
     * events.
     */
    private const BOT_USER_AGENT_MARKERS = [
        'bot',
        'spider',
        'crawl',
        'slurp',
        'curl',
        'wget',
        'python-requests',
        'python-urllib',
        'go-http-client',
        'libwww-perl',
        'headlesschrome',
        'facebookexternalhit',
        'pingdom',
        'uptimerobot',
    ];

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
        if (!is_object($response) || !method_exists($response, 'getStatusCode')) {
            return;
        }
        $statusCode = (int) $response->getStatusCode();
        if ($statusCode < 200 || $statusCode >= 300) {
            // Excludes redirects (301/302) and error pages; neither is a real page view.
            return;
        }

        if (!$this->isHtmlResponse($response)) {
            // Excludes robots.txt, sitemap.xml, RSS/XML feeds and similar non-page responses.
            return;
        }

        if ($this->isLikelyBot()) {
            return;
        }

        $this->eventQueue->enqueue('page_view');
    }

    private function isHtmlResponse(object $response): bool
    {
        if (!method_exists($response, 'getHeaders')) {
            // Unknown response type; do not block on a signal we cannot read.
            return true;
        }
        $headers = $response->getHeaders();
        if (!is_object($headers) || !method_exists($headers, 'get')) {
            return true;
        }
        $contentType = $headers->get('Content-Type');
        if ($contentType instanceof \ArrayIterator) {
            $contentType = $contentType->current();
        }
        if (!is_object($contentType) || !method_exists($contentType, 'getFieldValue')) {
            // No Content-Type header at all, most commonly a bare redirect response.
            return false;
        }
        return str_contains(strtolower((string) $contentType->getFieldValue()), 'text/html');
    }

    private function isLikelyBot(): bool
    {
        $userAgent = strtolower((string) $this->request->getServer('HTTP_USER_AGENT'));
        if ($userAgent === '') {
            return true;
        }
        foreach (self::BOT_USER_AGENT_MARKERS as $marker) {
            if (str_contains($userAgent, $marker)) {
                return true;
            }
        }
        return false;
    }
}
