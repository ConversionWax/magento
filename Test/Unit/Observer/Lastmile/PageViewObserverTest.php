<?php

declare(strict_types=1);

namespace Cwax\Banner\Test\Unit\Observer\Lastmile;

use Cwax\Banner\Model\Lastmile\EventQueue;
use Cwax\Banner\Observer\Lastmile\PageViewObserver;
use Magento\Framework\App\Request\Http;
use Magento\Framework\Event;
use Magento\Framework\Event\Observer;
use Magento\Framework\HTTP\PhpEnvironment\Response;
use PHPUnit\Framework\TestCase;

class PageViewObserverTest extends TestCase
{
    private const BROWSER = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 14_0) AppleWebKit/605.1.15 Safari/605.1.15';

    /**
     * Magento renders HTML pages without putting Content-Type in the response
     * header bag; PHP adds it from default_mimetype when the body is sent.
     */
    public function testHtmlPageWithoutAContentTypeHeaderIsAPageView(): void
    {
        $this->assertPageView(true, $this->response());
    }

    public function testExplicitHtmlContentTypeIsAPageView(): void
    {
        $this->assertPageView(true, $this->response(200, 'text/html; charset=UTF-8'));
    }

    /** @dataProvider nonPageResponses */
    public function testNonPageResponsesAreNotPageViews(int $status, ?string $contentType): void
    {
        $this->assertPageView(false, $this->response($status, $contentType));
    }

    /** @return array<string, array{int, ?string}> */
    public static function nonPageResponses(): array
    {
        return [
            'robots.txt' => [200, 'text/plain'],
            'json result' => [200, 'application/json'],
            'rss feed' => [200, 'text/xml; charset=UTF-8'],
            'redirect' => [302, null],
            'not found' => [404, null],
        ];
    }

    public function testMissingContentTypeFollowsPhpDefaultMimetype(): void
    {
        $previous = ini_get('default_mimetype');
        ini_set('default_mimetype', 'text/plain');
        try {
            $this->assertPageView(false, $this->response());
        } finally {
            ini_set('default_mimetype', (string) $previous);
        }
    }

    public function testBotsAreNotPageViews(): void
    {
        $this->assertPageView(false, $this->response(), 'curl/8.7.1');
    }

    public function testAjaxRequestsAreNotPageViews(): void
    {
        $this->assertPageView(false, $this->response(), self::BROWSER, true);
    }

    private function assertPageView(
        bool $expected,
        Response $response,
        string $userAgent = self::BROWSER,
        bool $ajax = false
    ): void {
        $request = $this->createMock(Http::class);
        $request->method('isGet')->willReturn(true);
        $request->method('isXmlHttpRequest')->willReturn($ajax);
        $request->method('getServer')->with('HTTP_USER_AGENT')->willReturn($userAgent);

        $queue = $this->createMock(EventQueue::class);
        $queue->expects($expected ? $this->once() : $this->never())
            ->method('enqueue')
            ->with('page_view');

        (new PageViewObserver($queue, $request))
            ->execute(new Observer(['event' => new Event(['response' => $response])]));
    }

    private function response(int $status = 200, ?string $contentType = null): Response
    {
        $response = new Response();
        $response->setStatusCode($status);
        if ($contentType !== null) {
            $response->setHeader('Content-Type', $contentType);
        }
        return $response;
    }
}
