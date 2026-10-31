<?php

declare(strict_types=1);

namespace Cwax\Banner\Test\Unit\Model\Lastmile;

use Cwax\Banner\Model\Lastmile\VisitorContext;
use Laminas\Http\Header\GenericHeader;
use Laminas\Http\Headers;
use Magento\Framework\App\Request\Http;
use Magento\Framework\App\Response\Http as ResponseHttp;
use Magento\Framework\Stdlib\Cookie\CookieMetadataFactory;
use Magento\Framework\Stdlib\Cookie\PublicCookieMetadata;
use Magento\Framework\Stdlib\CookieManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class VisitorContextTest extends TestCase
{
    private const VISITOR_ID = '11111111-2222-4333-8444-555555555555';
    private const SECTION_URL = 'https://shop.test/customer/section/load/?sections=cwax-visitor';

    /** @var array<string, string> */
    private array $jar = [self::VISITOR_COOKIE_NAME => self::VISITOR_ID];

    private const VISITOR_COOKIE_NAME = '_cwid';

    /** @var string[] names of every Set-Cookie written, in order */
    private array $setCookies = [];

    /**
     * A fresh VisitorContext per call simulates a fresh request reading the shared cookie jar.
     *
     * @param string|null $cacheControl the response's Cache-Control, or null for no header yet
     * @param array<string, string> $server
     */
    private function context(
        ?string $cacheControl = 'no-store',
        string $uri = 'https://shop.test/',
        array $server = []
    ): VisitorContext
    {
        $manager = $this->createStub(CookieManagerInterface::class);
        $manager->method('getCookie')->willReturnCallback(fn (string $n) => $this->jar[$n] ?? null);
        $manager->method('setPublicCookie')->willReturnCallback(
            function (string $name, string $value): void {
                $this->jar[$name] = $value;
                $this->setCookies[] = $name;
            }
        );
        $factory = $this->createStub(CookieMetadataFactory::class);
        $factory->method('createPublicCookieMetadata')->willReturnCallback(
            static fn (): PublicCookieMetadata => new PublicCookieMetadata()
        );
        $request = $this->createStub(Http::class);
        $request->method('getUriString')->willReturn($uri);
        $request->method('getServer')->willReturnCallback(
            static fn (string $key): ?string => $server[$key] ?? null
        );
        // Default: a known-uncacheable response, so identity can be minted and flush() writes cookies.
        $headers = new Headers();
        if ($cacheControl !== null) {
            $headers->addHeader(new GenericHeader('Cache-Control', $cacheControl));
        }
        $response = $this->createStub(ResponseHttp::class);
        $response->method('getHeaders')->willReturn($headers);

        return new VisitorContext($manager, $factory, $request, $response, $this->createStub(LoggerInterface::class));
    }

    /** @return array<string, mixed> */
    private function decodedMagentoSession(): array
    {
        $padded = strtr($this->jar['_cwms'], '-_', '+/');
        return json_decode(base64_decode($padded . str_repeat('=', (4 - strlen($padded) % 4) % 4)), true);
    }

    private function purchase(VisitorContext $context, array $details): void
    {
        $context->markPurchased($details);
        $context->flush();
    }

    public function testMarkPurchasedStoresAPendingPurchaseThatSurvivesTheNextRequest(): void
    {
        $this->purchase($this->context(), ['orderId' => '000000123', 'value' => 49.5, 'currency' => 'usd']);

        $stored = $this->decodedMagentoSession();
        $this->assertSame(1, $stored['hp']);
        $this->assertSame('000000123', $stored['pp']['o']);
        $this->assertSame(49.5, $stored['pp']['v']);
        $this->assertSame('USD', $stored['pp']['c']);
        $this->assertSame(['o', 'v', 'c', 't'], array_keys($stored['pp']));

        $next = $this->context()->get();
        $this->assertSame('000000123', $next['pendingPurchase']['orderId']);
        $this->assertSame(49.5, $next['pendingPurchase']['value']);
        $this->assertSame('USD', $next['pendingPurchase']['currency']);
        $this->assertIsInt($next['pendingPurchase']['ts']);
    }

    public function testASecondPurchaseReplacesTheFirstPendingPurchase(): void
    {
        $this->purchase($this->context(), ['orderId' => 'A1', 'value' => 10.0, 'currency' => 'USD']);
        $this->purchase($this->context(), ['orderId' => 'B2', 'value' => 20.0, 'currency' => 'USD']);

        $this->assertSame('B2', $this->context()->get()['pendingPurchase']['orderId']);
    }

    public function testClearPendingPurchaseKeepsHasPurchased(): void
    {
        $this->purchase($this->context(), ['orderId' => 'A1', 'value' => 10.0, 'currency' => 'USD']);
        $context = $this->context();
        $context->clearPendingPurchase();
        $context->flush();

        $stored = $this->decodedMagentoSession();
        $this->assertArrayNotHasKey('pp', $stored);
        $this->assertSame(1, $stored['hp']);
        $this->assertNull($this->context()->get()['pendingPurchase']);
    }

    public function testMarkPurchasedWithoutDetailsOnlySetsTheFlag(): void
    {
        $this->purchase($this->context(), []);

        $this->assertArrayNotHasKey('pp', $this->decodedMagentoSession());
        $this->assertTrue($this->context()->get()['hadPurchase']);
    }

    public function testAnOldOrMalformedPendingPurchaseIsDropped(): void
    {
        $this->purchase($this->context(), ['orderId' => 'A1', 'value' => 10.0, 'currency' => 'USD']);
        $stored = $this->decodedMagentoSession();
        $stored['pp']['t'] = (int) round(microtime(true) * 1000) - 31 * 86400000;
        $this->jar['_cwms'] = rtrim(strtr(base64_encode(json_encode($stored)), '+/', '-_'), '=');

        $this->assertNull($this->context()->get()['pendingPurchase']);

        $stored['pp'] = ['o' => '', 'v' => 'x'];
        $this->jar['_cwms'] = rtrim(strtr(base64_encode(json_encode($stored)), '+/', '-_'), '=');

        $this->assertNull($this->context()->get()['pendingPurchase']);
    }

    public function testInvalidPurchaseDetailsAreIgnored(): void
    {
        $this->purchase($this->context(), ['orderId' => '', 'value' => 10.0, 'currency' => 'USD']);
        $this->purchase($this->context(), ['orderId' => 'A1', 'value' => NAN, 'currency' => 'USD']);
        $this->purchase($this->context(), ['orderId' => 'A1', 'value' => 1.0, 'currency' => 'DOLLARS']);

        $this->assertArrayNotHasKey('pp', $this->decodedMagentoSession());
    }

    public function testACachedPageRenderNeverMintsAVisitorOrWritesCookies(): void
    {
        $this->jar = [];
        $context = $this->context('public, max-age=86400, s-maxage=86400');

        $this->assertNull($context->get());
        $context->flush();
        $this->assertSame([], $this->setCookies);
    }

    public function testACachedPageRenderNeverMintsASessionForAKnownVisitor(): void
    {
        $context = $this->context('public, max-age=86400');

        $this->assertNull($context->get());
        $this->assertArrayNotHasKey('_cwms', $this->jar);
    }

    public function testUnknownCacheabilityNeverMints(): void
    {
        $this->jar = [];

        $this->assertNull($this->context(null)->get());
    }

    public function testAKnownUncacheableResponseMintsVisitorAndSession(): void
    {
        $this->jar = [];
        $context = $this->context('max-age=0, must-revalidate, no-cache, no-store');

        $identity = $context->get();
        $context->flush();

        $this->assertIsArray($identity);
        $this->assertTrue($identity['isNew']);
        $this->assertSame($identity['visitorId'], $this->jar['_cwid']);
        $this->assertSame($identity['sessionId'], $this->decodedMagentoSession()['sid']);
    }

    public function testARepeatedFlushWithUnchangedStateWritesNoSecondSetCookie(): void
    {
        $context = $this->context();
        $context->get();

        $context->flush();
        $context->flush();
        $this->assertSame(['_cwid', '_cwms'], $this->setCookies);

        // A real change after the first flush is still written.
        $context->markPurchased(['orderId' => '100', 'value' => 5, 'currency' => 'USD']);
        $context->flush();
        $this->assertSame(['_cwid', '_cwms', '_cwid', '_cwms'], $this->setCookies);
    }

    public function testARestCheckoutResponseWithNoCacheControlPersistsThePendingPurchase(): void
    {
        // The visitor already has identity from the section. The REST response object VisitorContext
        // sees carries no Cache-Control at all; at flush time that means uncacheable.
        $section = $this->context();
        $section->get();
        $section->flush();
        $this->setCookies = [];

        $rest = $this->context(null);
        $rest->markPurchased(['orderId' => '000000042', 'value' => 19.5, 'currency' => 'usd']);
        $rest->flush();

        $this->assertContains('_cwms', $this->setCookies);
        $session = $this->decodedMagentoSession();
        $this->assertSame(1, $session['hp']);
        $this->assertSame('000000042', $session['pp']['o']);
        $this->assertSame('USD', $session['pp']['c']);
    }

    public function testASectionRequestTakesTheLandingPageFromItsReferer(): void
    {
        $this->jar = [];

        $context = $this->context(uri: self::SECTION_URL, server: [
            'HTTP_HOST' => 'shop.test',
            'HTTP_REFERER' => 'https://shop.test/sale?utm_source=google&gclid=abc&token=secret',
        ])->get();

        $this->assertSame('/sale?utm_source=google&gclid=abc', $context['entryUrl']);
        $this->assertNull($context['entryReferrer']);
    }

    public function testASectionRequestComparesTheRefererHostWithoutThePort(): void
    {
        $this->jar = [];

        $context = $this->context(uri: self::SECTION_URL, server: [
            'HTTP_HOST' => 'shop.test:8443',
            'HTTP_REFERER' => 'https://shop.test:8443/sale',
        ])->get();

        $this->assertSame('/sale', $context['entryUrl']);
    }

    public function testASectionRequestNeverUsesItsOwnUrlAsTheEntry(): void
    {
        $this->jar = [];

        $context = $this->context(uri: self::SECTION_URL, server: [
            'HTTP_HOST' => 'shop.test',
            'HTTP_REFERER' => 'https://evil.test/?utm_source=x',
        ])->get();

        $this->assertSame('/', $context['entryUrl']);
    }

    public function testASectionRequestReadsTheExternalReferrerTheBrowserRecorded(): void
    {
        $this->jar = ['_cwer' => rawurlencode('https://www.google.com/search?q=private')];

        $context = $this->context(uri: self::SECTION_URL, server: [
            'HTTP_HOST' => 'shop.test',
            'HTTP_REFERER' => 'https://shop.test/sale',
        ])->get();

        $this->assertSame('https://www.google.com/search', $context['entryReferrer']);
    }

    public function testAPageRequestStripsTheQueryAndFragmentFromItsReferrer(): void
    {
        $this->jar = [];

        $context = $this->context(uri: 'https://shop.test/sale', server: [
            'HTTP_HOST' => 'shop.test',
            'HTTP_REFERER' => 'https://news.test/story?email=a@b.test#top',
        ])->get();

        $this->assertSame('/sale', $context['entryUrl']);
        $this->assertSame('https://news.test/story', $context['entryReferrer']);
    }
}
