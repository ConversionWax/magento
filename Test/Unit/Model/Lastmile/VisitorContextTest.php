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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class VisitorContextTest extends TestCase
{
    private const VISITOR_ID = '11111111-2222-4333-8444-555555555555';
    private const LM_SESSION_ID = '66666666-7777-4888-8999-aaaaaaaaaaaa';

    /** @var array<string, string> */
    private array $jar = ['_cwid' => self::VISITOR_ID];

    /** @var string[] names of every Set-Cookie written, in order */
    private array $setCookies = [];

    /** @var array<string, PublicCookieMetadata> the metadata of the last write of each cookie */
    private array $metadata = [];

    /** @var string[] */
    private array $noCacheCalls = [];

    /**
     * A fresh VisitorContext per call simulates a fresh request reading the shared cookie jar.
     *
     * @param string|null $cacheControl the response's Cache-Control, or null for no header yet
     * @param array<string, string> $server
     */
    private function context(
        ?string $cacheControl = null,
        string $method = 'POST',
        string $uri = 'https://shop.test/checkout/cart/add/',
        array $server = []
    ): VisitorContext {
        $manager = $this->createStub(CookieManagerInterface::class);
        $manager->method('getCookie')->willReturnCallback(fn (string $n) => $this->jar[$n] ?? null);
        $manager->method('setPublicCookie')->willReturnCallback(
            function (string $name, string $value, PublicCookieMetadata $metadata): void {
                $this->jar[$name] = $value;
                $this->setCookies[] = $name;
                $this->metadata[$name] = $metadata;
            }
        );
        $factory = $this->createStub(CookieMetadataFactory::class);
        $factory->method('createPublicCookieMetadata')->willReturnCallback(
            static fn (): PublicCookieMetadata => new PublicCookieMetadata()
        );
        $request = $this->createStub(Http::class);
        $request->method('getMethod')->willReturn($method);
        $request->method('getUriString')->willReturn($uri);
        $request->method('getServer')->willReturnCallback(
            static fn (string $key): ?string => $server[$key] ?? null
        );
        $headers = new Headers();
        if ($cacheControl !== null) {
            $headers->addHeader(new GenericHeader('Cache-Control', $cacheControl));
        }
        $response = $this->createStub(ResponseHttp::class);
        $response->method('getHeaders')->willReturn($headers);
        $response->method('setNoCacheHeaders')->willReturnCallback(
            function () use ($headers, $response): ResponseHttp {
                $this->noCacheCalls[] = 'setNoCacheHeaders';
                $headers->clearHeaders();
                $headers->addHeader(
                    new GenericHeader('Cache-Control', 'max-age=0, must-revalidate, no-cache, no-store')
                );
                return $response;
            }
        );

        return new VisitorContext($manager, $factory, $request, $response, $this->createStub(LoggerInterface::class));
    }

    /** @param array<string, mixed> $session */
    private function lmSession(array $session = []): string
    {
        $now = (int) round(microtime(true) * 1000);

        return rawurlencode(json_encode($session + [
            'sid' => self::LM_SESSION_ID,
            'st' => $now - 60000,
            'la' => $now - 1000,
            'pv' => 3,
            'src' => 'google',
            'med' => 'organic',
        ]));
    }

    /** @return array<string, mixed> */
    private function writtenSession(): array
    {
        return json_decode(rawurldecode($this->jar['_cws']), true);
    }

    public function testAnLmJsSessionIsAdoptedAndNeverWritten(): void
    {
        $this->jar['_cws'] = $this->lmSession(['geo' => 'US,CA']);
        $context = $this->context();

        $identity = $context->get();
        $context->flush();

        $this->assertSame(self::VISITOR_ID, $identity['visitorId']);
        $this->assertSame(self::LM_SESSION_ID, $identity['sessionId']);
        $this->assertFalse($identity['isNew'], 'lm.js already started this session');
        $this->assertSame('US,CA', $identity['geo']);
        $this->assertSame(['_cwid'], $this->setCookies);
    }

    public function testAnLmJsSessionIsAdoptedEvenWhenIdle(): void
    {
        // lm.js rolls its own session over on the next page; Magento never fights it for the cookie.
        $this->jar['_cws'] = $this->lmSession(['la' => 1]);
        $context = $this->context();

        $this->assertSame(self::LM_SESSION_ID, $context->get()['sessionId']);
        $context->flush();
        $this->assertNotContains('_cws', $this->setCookies);
    }

    public function testTheExistingVisitorIdIsSentAgainForFourHundredDays(): void
    {
        $this->jar['_cws'] = $this->lmSession();
        $context = $this->context();
        $context->get();
        $context->flush();

        $this->assertSame(self::VISITOR_ID, $this->jar['_cwid']);
        $metadata = $this->metadata['_cwid'];
        $this->assertSame(34560000, $metadata->getDuration());
        $this->assertSame('/', $metadata->getPath());
        $this->assertTrue($metadata->getSecure());
        $this->assertFalse($metadata->getHttpOnly());
        $this->assertSame('Lax', $metadata->getSameSite());
    }

    public function testACommerceRequestWithoutIdentityMintsVisitorAndSession(): void
    {
        $this->jar = [];
        $context = $this->context();

        $identity = $context->get();
        $context->flush();

        $this->assertIsArray($identity);
        $this->assertTrue($identity['isNew']);
        $this->assertSame($identity['visitorId'], $this->jar['_cwid']);
        $session = $this->writtenSession();
        $this->assertSame($identity['sessionId'], $session['sid']);
        $this->assertSame($session['st'], $session['la'], 'st === la marks a freshly minted session');
        $this->assertSame(1, $session['mg']);
        $this->assertSame(['_cwid', '_cws'], $this->setCookies);
        $this->assertNull($this->metadata['_cws']->getDuration(), '_cws is a session cookie');
        $this->assertSame('/', $this->metadata['_cws']->getPath());
        $this->assertTrue($this->metadata['_cws']->getSecure());
    }

    public function testAKnownVisitorWithoutASessionGetsOneOfMagentosOwn(): void
    {
        $context = $this->context();

        $identity = $context->get();
        $context->flush();

        $this->assertSame(self::VISITOR_ID, $identity['visitorId']);
        $this->assertTrue($identity['isNew']);
        $this->assertSame($identity['sessionId'], $this->writtenSession()['sid']);
    }

    public function testAMagentoSessionIsKeptAliveAcrossRequests(): void
    {
        $first = $this->context();
        $sessionId = $first->get()['sessionId'];
        $first->flush();
        $minted = $this->writtenSession();

        $second = $this->context();
        $identity = $second->get();
        $second->flush();

        $this->assertSame($sessionId, $identity['sessionId']);
        $this->assertFalse($identity['isNew']);
        $this->assertSame($minted['st'], $identity['startedAt']);
        $this->assertGreaterThanOrEqual($minted['la'], $this->writtenSession()['la']);
        $this->assertSame(1, $this->writtenSession()['mg']);
    }

    public function testKeepingAMagentoSessionAliveKeepsEveryKeyLmJsAdded(): void
    {
        $this->jar['_cws'] = $this->lmSession(['mg' => 1, 'po' => ['100'], 'geo' => 'DE']);
        $context = $this->context();
        $context->get();
        $context->flush();

        $session = $this->writtenSession();
        $this->assertSame(['100'], $session['po']);
        $this->assertSame('DE', $session['geo']);
        $this->assertSame(3, $session['pv']);
    }

    public function testAnIdleMagentoSessionIsReplaced(): void
    {
        $this->jar['_cws'] = $this->lmSession(['mg' => 1, 'la' => (int) round(microtime(true) * 1000) - 1800001]);
        $context = $this->context();

        $identity = $context->get();

        $this->assertTrue($identity['isNew']);
        $this->assertNotSame(self::LM_SESSION_ID, $identity['sessionId']);
    }

    /** @return array<string, array{string}> */
    public static function cacheableHeaders(): array
    {
        return [
            'public with max-age' => ['public, max-age=86400, s-maxage=86400'],
            'public with a short max-age' => ['public, max-age=60'],
        ];
    }

    #[DataProvider('cacheableHeaders')]
    public function testACacheableGetNeverMintsOrWritesCookies(string $cacheControl): void
    {
        $this->jar = [];
        $context = $this->context($cacheControl, 'GET', 'https://shop.test/blue-shirt.html');

        $this->assertNull($context->get());
        $context->flush();
        $this->assertSame([], $this->setCookies);
    }

    public function testACacheableGetNeverRefreshesAKnownVisitor(): void
    {
        $this->jar['_cws'] = $this->lmSession();
        $context = $this->context('public, max-age=86400', 'GET');

        $this->assertIsArray($context->get(), 'events may still use the identity');
        $context->flush();
        $this->assertSame([], $this->setCookies);
    }

    public function testAGetWithUnknownCacheabilityNeverMints(): void
    {
        $this->jar = [];

        $this->assertNull($this->context(null, 'GET')->get());
    }

    public function testAnUnknownRequestMethodIsJudgedByItsHeaderOnly(): void
    {
        $this->jar = [];

        $this->assertNull($this->context(null, '')->get());
    }

    public function testDeclaringAGetUncacheableAllowsAMintThatPersists(): void
    {
        $this->jar = [];
        $context = $this->context(null, 'GET', 'https://shop.test/checkout/');

        $context->declareUncacheable();
        $identity = $context->get();
        $context->flush();

        $this->assertSame(['setNoCacheHeaders'], $this->noCacheCalls);
        $this->assertTrue($identity['isNew']);
        $this->assertSame(['_cwid', '_cws'], $this->setCookies);
    }

    public function testAPostLaterMarkedPublicIsMintedButNeverWritten(): void
    {
        $this->jar = [];
        $context = $this->context('public, max-age=600');

        $this->assertIsArray($context->get());
        $context->flush();
        $this->assertSame([], $this->setCookies);
    }

    public function testARepeatedFlushWithUnchangedStateWritesNoSecondSetCookie(): void
    {
        $this->jar = [];
        $context = $this->context();
        $context->get();

        $context->flush();
        $context->flush();

        $this->assertSame(['_cwid', '_cws'], $this->setCookies);
    }

    public function testNothingIsWrittenWhenNoEventResolvedIdentity(): void
    {
        $this->context()->flush();

        $this->assertSame([], $this->setCookies);
    }

    public function testSessionNumberAndPurchaseComeFromTheSummaryClaims(): void
    {
        $this->jar['_cws'] = $this->lmSession();
        $claims = rtrim(strtr(base64_encode(json_encode(['v' => 1, 'sc' => 4, 'hp' => 1])), '+/', '-_'), '=');
        $this->jar['_cwvs'] = $claims . '.signature';

        $identity = $this->context()->get();

        $this->assertSame(4, $identity['number']);
        $this->assertTrue($identity['hadPurchase']);
    }

    public function testWithoutClaimsTheSessionIsTheFirstWithNoPurchase(): void
    {
        $identity = $this->context()->get();

        $this->assertSame(1, $identity['number']);
        $this->assertFalse($identity['hadPurchase']);
    }

    /** @return array<string, array{string}> */
    public static function brokenSessions(): array
    {
        return [
            'not JSON' => ['abc'],
            'no sid' => [rawurlencode('{"st":1}')],
            'sid not a UUID' => [rawurlencode('{"sid":"x","la":1}')],
        ];
    }

    #[DataProvider('brokenSessions')]
    public function testABrokenSessionCookieIsReplacedByAMint(string $raw): void
    {
        $this->jar['_cws'] = $raw;

        $identity = $this->context()->get();

        $this->assertTrue($identity['isNew']);
    }

    public function testAMintTakesTheEntryFromTheSameHostReferer(): void
    {
        $this->jar = [];

        $context = $this->context(server: [
            'HTTP_HOST' => 'shop.test',
            'HTTP_REFERER' => 'https://shop.test/sale?utm_source=google&gclid=abc&token=secret',
        ])->get();

        $this->assertSame('/sale?utm_source=google&gclid=abc', $context['entryUrl']);
        $this->assertNull($context['entryReferrer']);
    }

    public function testTheRefererHostIsComparedWithoutThePort(): void
    {
        $this->jar = [];

        $context = $this->context(server: [
            'HTTP_HOST' => 'shop.test:8443',
            'HTTP_REFERER' => 'https://shop.test:8443/sale',
        ])->get();

        $this->assertSame('/sale', $context['entryUrl']);
    }

    public function testAnExternalRefererIsTheReferrerWithoutQueryOrFragment(): void
    {
        $this->jar = [];

        $context = $this->context(uri: 'https://shop.test/sale', server: [
            'HTTP_HOST' => 'shop.test',
            'HTTP_REFERER' => 'https://news.test/story?email=a@b.test#top',
        ])->get();

        $this->assertSame('/sale', $context['entryUrl']);
        $this->assertSame('https://news.test/story', $context['entryReferrer']);
    }

    public function testAGuestCartIdNeverReachesTheEntryUrl(): void
    {
        $this->jar = [];

        $context = $this->context(uri: 'https://shop.test/rest/default/V1/guest-carts/s3cr3tMask/payment-information')
            ->get();

        $this->assertSame('/rest/default/V1/guest-carts/-/payment-information', $context['entryUrl']);
    }
}
