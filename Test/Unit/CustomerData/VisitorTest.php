<?php

declare(strict_types=1);

namespace Cwax\Banner\Test\Unit\CustomerData;

use Cwax\Banner\CustomerData\Visitor;
use Cwax\Banner\Model\Config;
use Cwax\Banner\Model\Lastmile\VisitorContext;
use Cwax\Banner\Model\Lastmile\VisitorSyncService;
use Magento\Framework\App\Http\Context as HttpContext;
use Magento\Framework\App\Request\Http as RequestHttp;
use Magento\Framework\App\Response\Http as ResponseHttp;
use Magento\Framework\Session\Config\ConfigInterface as SessionConfig;
use Magento\Framework\Stdlib\Cookie\CookieMetadataFactory;
use Magento\Framework\Stdlib\Cookie\PublicCookieMetadata;
use Magento\Framework\Stdlib\CookieManagerInterface;
use Magento\Framework\Stdlib\DateTime;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class VisitorTest extends TestCase
{
    private VisitorSyncService&MockObject $sync;
    private VisitorContext&MockObject $context;
    /** @var array<string, string> */
    private array $jar = [];
    /** @var string[] names of every Set-Cookie written, in order */
    private array $setCookies = [];
    /** @var array<int, array<string, mixed>|null> what VisitorContext::get() returned inside sync() */
    private array $resolved = [];

    private function stores(): StoreManagerInterface
    {
        $store = $this->createStub(StoreInterface::class);
        $store->method('getId')->willReturn(3);
        $stores = $this->createStub(StoreManagerInterface::class);
        $stores->method('getStore')->willReturn($store);

        return $stores;
    }

    private function request(string $fullActionName): RequestHttp
    {
        $request = $this->createStub(RequestHttp::class);
        $request->method('getFullActionName')->willReturn($fullActionName);
        $request->method('getUriString')->willReturn('https://shop.test/customer/section/load/');
        $request->method('getServer')->willReturn(null);

        return $request;
    }

    /** A real Magento response, so the Cache-Control header VisitorContext reads is the real one. */
    private function realResponse(): ResponseHttp
    {
        $dateTime = $this->createStub(DateTime::class);
        $dateTime->method('gmDate')->willReturn('Thu, 01 Jan 1970 00:00:00 GMT');

        return new ResponseHttp(
            $this->createStub(RequestHttp::class),
            $this->createStub(CookieManagerInterface::class),
            $this->createStub(CookieMetadataFactory::class),
            $this->createStub(HttpContext::class),
            $dateTime,
            $this->createStub(SessionConfig::class)
        );
    }

    /** A real VisitorContext over an in-memory cookie jar, sharing the given response. */
    private function realContext(RequestHttp $request, ResponseHttp $response): VisitorContext
    {
        $manager = $this->createStub(CookieManagerInterface::class);
        $manager->method('getCookie')->willReturnCallback(fn (string $name) => $this->jar[$name] ?? null);
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

        return new VisitorContext($manager, $factory, $request, $response, $this->createStub(LoggerInterface::class));
    }

    /**
     * The section wired to a real VisitorContext. The sync stub stands in for a granted VisitorSyncService:
     * it resolves identity exactly as the real one does after its consent check.
     *
     * @return array{0: Visitor, 1: VisitorContext, 2: ResponseHttp}
     */
    private function wiredSection(string $fullActionName): array
    {
        $config = $this->createStub(Config::class);
        $config->method('isConfigured')->willReturn(true);
        $request = $this->request($fullActionName);
        $response = $this->realResponse();
        $context = $this->realContext($request, $response);
        $sync = $this->createStub(VisitorSyncService::class);
        $sync->method('sync')->willReturnCallback(
            function () use ($context): array {
                $identity = $context->get();
                $this->resolved[] = $identity;

                return ['state' => 'granted', 'synced' => false, 'sid' => (string) ($identity['sessionId'] ?? '')];
            }
        );
        $section = new Visitor(
            $config,
            $this->stores(),
            $sync,
            $context,
            $request,
            $response,
            $this->createStub(LoggerInterface::class)
        );

        return [$section, $context, $response];
    }

    private function section(bool $configured): Visitor
    {
        $config = $this->createStub(Config::class);
        $config->method('isConfigured')->willReturn($configured);
        $this->sync = $this->createMock(VisitorSyncService::class);
        $this->context = $this->createMock(VisitorContext::class);

        return new Visitor(
            $config,
            $this->stores(),
            $this->sync,
            $this->context,
            $this->request('customer_section_load'),
            $this->realResponse(),
            $this->createStub(LoggerInterface::class)
        );
    }

    public function testReturnsOffWhenNotConfiguredAndTouchesNothing(): void
    {
        $section = $this->section(false);
        $this->sync->expects($this->never())->method('sync');
        $this->context->expects($this->never())->method('flush');

        $this->assertSame(['state' => 'off'], $section->getSectionData());
    }

    public function testSyncsAndFlushesWhenConfigured(): void
    {
        $section = $this->section(true);
        $this->sync->expects($this->once())->method('sync')->with(3)
            ->willReturn(['state' => 'granted', 'synced' => true, 'sid' => 's']);
        $this->context->expects($this->once())->method('flush');

        $this->assertSame(
            ['state' => 'granted', 'synced' => true, 'sid' => 's'],
            $section->getSectionData()
        );
    }

    public function testDeniedByTheSyncServiceReturnsNoClaimsAndNeverTouchesTheVisitorContext(): void
    {
        $section = $this->section(true);
        $this->sync->expects($this->once())->method('sync')
            ->willReturn(['state' => 'denied', 'synced' => false, 'sid' => '']);
        $this->context->expects($this->never())->method($this->anything());

        $this->assertSame(['state' => 'denied'], $section->getSectionData());
    }

    public function testTheSectionOutputCarriesOnlyStateSyncedAndSid(): void
    {
        $section = $this->section(true);
        $this->sync->method('sync')->willReturn([
            'state' => 'granted', 'synced' => true, 'sid' => 's', 'summary' => 'x.y', 'visitorId' => 'v',
        ]);

        $this->assertSame(['state', 'synced', 'sid'], array_keys($section->getSectionData()));
    }

    public function testAnExceptionBecomesAnErrorStateWithNoDetails(): void
    {
        $section = $this->section(true);
        $this->sync->method('sync')->willThrowException(new \RuntimeException('boom'));

        $this->assertSame(['state' => 'error'], $section->getSectionData());
    }

    public function testASectionLoadWithNoVisitorCookiesMintsIdentityAndPersistsItOnce(): void
    {
        [$section, $context, $response] = $this->wiredSection('customer_section_load');

        $data = $section->getSectionData();
        // The observer pass at controller_front_send_response_before must add no second Set-Cookie.
        $context->flush();

        $identity = $this->resolved[0];
        $this->assertIsArray($identity);
        $this->assertTrue($identity['isNew']);
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $identity['visitorId']);
        $this->assertSame($identity['sessionId'], $data['sid']);
        $this->assertSame(['_cwid', '_cwms'], $this->setCookies);
        $this->assertSame($identity['visitorId'], $this->jar['_cwid']);
        $this->assertStringContainsString(
            'no-store',
            $response->getHeaders()->get('Cache-Control')->getFieldValue()
        );
    }

    public function testASectionLoadWithAVisitorIdButNoSessionCookieMintsASession(): void
    {
        $this->jar['_cwid'] = '11111111-2222-4333-8444-555555555555';
        [$section] = $this->wiredSection('customer_section_load');

        $section->getSectionData();

        $this->assertIsArray($this->resolved[0]);
        $this->assertSame('11111111-2222-4333-8444-555555555555', $this->resolved[0]['visitorId']);
        $this->assertTrue($this->resolved[0]['isNew']);
        $this->assertArrayHasKey('_cwms', $this->jar);
    }

    public function testOutsideTheSectionEndpointTheResponseIsNotDeclaredUncacheableAndNothingIsMinted(): void
    {
        // Any other caller could be a full-page-cached render: cacheability stays unknown, so no mint.
        [$section, $context, $response] = $this->wiredSection('cms_index_index');

        $section->getSectionData();
        $context->flush();

        $this->assertNull($this->resolved[0]);
        $this->assertFalse($response->getHeaders()->has('Cache-Control'));
        $this->assertSame([], $this->setCookies);
    }
}
