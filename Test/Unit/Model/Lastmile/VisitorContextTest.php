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

    /** @var array<string, string> */
    private array $jar = [self::VISITOR_COOKIE_NAME => self::VISITOR_ID];

    private const VISITOR_COOKIE_NAME = '_cwid';

    /** A fresh VisitorContext per call simulates a fresh request reading the shared cookie jar. */
    private function context(): VisitorContext
    {
        $manager = $this->createStub(CookieManagerInterface::class);
        $manager->method('getCookie')->willReturnCallback(fn (string $n) => $this->jar[$n] ?? null);
        $manager->method('setPublicCookie')->willReturnCallback(
            function (string $name, string $value): void {
                $this->jar[$name] = $value;
            }
        );
        $factory = $this->createStub(CookieMetadataFactory::class);
        $factory->method('createPublicCookieMetadata')->willReturnCallback(
            static fn (): PublicCookieMetadata => new PublicCookieMetadata()
        );
        $request = $this->createStub(Http::class);
        $request->method('getUriString')->willReturn('https://shop.test/');
        $request->method('getServer')->willReturn(null);
        // A known-uncacheable response, so identity can be minted and flush() writes cookies.
        $headers = new Headers();
        $headers->addHeader(new GenericHeader('Cache-Control', 'no-store'));
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
}
