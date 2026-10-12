<?php

declare(strict_types=1);

namespace Cwax\Banner\Test\Unit\Model\Lastmile;

use Cwax\Banner\Api\ConsentResolverInterface;
use Cwax\Banner\Api\Data\ConsentDecisionInterface;
use Cwax\Banner\Model\Config;
use Cwax\Banner\Model\Consent\ConsentDecision;
use Cwax\Banner\Model\Lastmile\CollectorClient;
use Cwax\Banner\Model\Lastmile\VisitorContext;
use Cwax\Banner\Model\Lastmile\VisitorCookies;
use Cwax\Banner\Model\Lastmile\VisitorSyncService;
use Magento\Framework\App\Request\Http;
use Magento\Framework\HTTP\PhpEnvironment\RemoteAddress;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

class VisitorSyncServiceTest extends TestCase
{
    private const SID = '11111111-1111-4111-8111-111111111111';
    private const VID = '22222222-2222-4222-8222-222222222222';
    private const PROPERTY = '33333333-3333-4333-8333-333333333333';
    private const PENDING = ['orderId' => '000000123', 'value' => 49.5, 'currency' => 'USD', 'ts' => 1700000100000];

    // Collaborators named in $mocks are mocks (the test sets expectations on them); the rest are stubs.
    private VisitorContext&Stub $context;
    private VisitorCookies&Stub $cookies;
    private CollectorClient&Stub $client;
    /** @var array<int, array<string, mixed>> */
    private array $calls = [];

    /**
     * @param array<string, mixed> $overrides
     * @param array<string, string> $server
     * @param string[] $mocks
     */
    private function service(
        array $overrides = [],
        ?string $summary = null,
        ?string $cookieSid = null,
        bool $storage = true,
        array $server = [],
        array $mocks = []
    ): VisitorSyncService {
        $make = fn (string $name, string $class): Stub => in_array($name, $mocks, true)
            ? $this->createMock($class)
            : $this->createStub($class);
        $this->context = $make('context', VisitorContext::class);
        $this->context->method('get')->willReturn($overrides + [
            'visitorId' => self::VID,
            'sessionId' => self::SID,
            'startedAt' => 1700000000000,
            'lastActivityAt' => 1700000090000,
            'entryUrl' => '/customer/section/load/',
            'entryReferrer' => 'https://shop.test/landing',
            'isNew' => false,
            'number' => 1,
            'hadPurchase' => false,
            'pendingPurchase' => null,
            'geo' => null,
        ]);
        $this->cookies = $make('cookies', VisitorCookies::class);
        $this->cookies->method('getSummary')->willReturn($summary);
        $this->cookies->method('getSessionId')->willReturn($cookieSid);
        $this->client = $make('client', CollectorClient::class);
        $config = $this->createStub(Config::class);
        $config->method('getWebPropertyId')->willReturn(self::PROPERTY);
        $remote = $this->createStub(RemoteAddress::class);
        $remote->method('getRemoteAddress')->willReturn('203.0.113.9');
        $server += ['HTTP_USER_AGENT' => 'Mozilla/5.0 Test', 'HTTP_HOST' => 'shop.test'];
        $request = $this->createStub(Http::class);
        $request->method('getServer')->willReturnCallback(
            static fn (string $key): ?string => $server[$key] ?? null
        );
        $consent = $this->createStub(ConsentResolverInterface::class);
        $consent->method('resolve')->willReturn(
            new ConsentDecision([ConsentDecisionInterface::SIGNAL_STORAGE => $storage])
        );

        return new VisitorSyncService(
            $config,
            $this->context,
            $this->cookies,
            $this->client,
            $remote,
            $request,
            $consent
        );
    }

    /** @param array<int, array<string, mixed>|null> $responses */
    private function respond(array $responses): void
    {
        $this->client->method('session')->willReturnCallback(
            function (int $storeId, array $body, ?string $ip, string $ua) use (&$responses): ?array {
                $this->calls[] = ['body' => $body, 'ip' => $ip, 'ua' => $ua];
                return array_shift($responses);
            }
        );
    }

    public function testDeniedVisitorMakesNoRequestWritesNothingAndClearsStaleCookies(): void
    {
        $service = $this->service(
            ['pendingPurchase' => self::PENDING],
            'stale.sig',
            self::SID,
            storage: false,
            mocks: ['context', 'cookies', 'client']
        );
        $this->context->expects($this->never())->method('get');
        $this->context->expects($this->never())->method('clearPendingPurchase');
        $this->context->expects($this->never())->method('flush');
        $this->client->expects($this->never())->method('session');
        $this->cookies->expects($this->never())->method('writeSession');
        $this->cookies->expects($this->never())->method('writeSummary');
        $this->cookies->expects($this->once())->method('clearAll');

        $this->assertSame(['state' => 'denied', 'synced' => false, 'sid' => ''], $service->sync(1));
    }

    public function testSkipsTheCollectorWhenTheSessionIsAlreadySynced(): void
    {
        $service = $this->service([], 'a.b', self::SID, mocks: ['cookies', 'client']);
        $this->client->expects($this->never())->method('session');
        $this->cookies->expects($this->never())->method('writeSummary');
        $this->cookies->expects($this->never())->method('clearAll');

        $this->assertSame(
            ['state' => 'granted', 'synced' => true, 'sid' => self::SID],
            $service->sync(1)
        );
    }

    public function testMissingSessionCookieResyncsEvenWithAnExistingSummary(): void
    {
        $service = $this->service([], 'a.b', null, mocks: ['cookies']);
        $this->respond([['summary' => 'next.sig']]);
        $this->cookies->expects($this->once())->method('writeSession');

        $service->sync(1);

        $this->assertCount(1, $this->calls);
        $this->assertSame('a.b', $this->calls[0]['body']['summary']);
    }

    public function testNewSessionSendsAFreshMintAndWritesBothCookies(): void
    {
        $service = $this->service(
            server: ['HTTP_REFERER' => 'https://shop.test/?utm_source=google&utm_medium=cpc&token=secret'],
            mocks: ['cookies']
        );
        $this->respond([[
            'summary' => 'new.sig',
            'sessionId' => 'ignored',
            'entry' => ['source' => 'google', 'medium' => 'paid'],
            'geo' => ['country' => 'US', 'region' => 'CA'],
        ]]);
        $this->cookies->expects($this->once())->method('writeSummary')->with('new.sig', 1);
        $this->cookies->expects($this->once())->method('writeSession')->with([
            'sid' => self::SID,
            'st' => 1700000000000,
            'la' => 1700000000000,
            'pv' => 1,
            'src' => 'google',
            'med' => 'paid',
            'geo' => 'US,CA',
        ]);

        $result = $service->sync(1);

        $body = $this->calls[0]['body'];
        $this->assertSame(self::PROPERTY, $body['storeId']);
        $this->assertSame(self::VID, $body['visitorId']);
        $this->assertNull($body['summary']);
        $this->assertSame('magento-session-' . self::SID, $body['requestId']);
        $this->assertTrue($body['mintOnly']);
        $this->assertSame(1700000000000, $body['session']['startedAt']);
        $this->assertSame(1700000000000, $body['session']['lastActivityAt']);
        $this->assertSame(self::SID, $body['session']['sessionId']);
        $this->assertSame(
            ['url' => '/?utm_source=google&utm_medium=cpc', 'referrer' => null, 'ts' => 1700000000000, 'isLanding' => true],
            $body['arrival']
        );
        $this->assertSame(['ua' => 'Mozilla/5.0 Test'], $body['device']);
        $this->assertArrayNotHasKey('purchase', $body);
        $this->assertArrayNotHasKey('identify', $body);
        $this->assertSame('203.0.113.9', $this->calls[0]['ip']);
        $this->assertSame('Mozilla/5.0 Test', $this->calls[0]['ua']);
        $this->assertTrue($result['synced']);
    }

    public function testArrivalFallsBackToTheContextUrlForAForeignOrMissingReferer(): void
    {
        $service = $this->service(
            server: ['HTTP_REFERER' => 'https://evil.test/?utm_source=x'],
            mocks: ['cookies']
        );
        $this->respond([['summary' => 'new.sig']]);
        $this->cookies->expects($this->once())->method('writeSession');

        $service->sync(1);

        $this->assertSame('/customer/section/load/', $this->calls[0]['body']['arrival']['url']);
    }

    public function testDeviceIsOmittedEntirelyWhenTheUserAgentIsEmpty(): void
    {
        $service = $this->service(
            ['pendingPurchase' => self::PENDING],
            server: ['HTTP_USER_AGENT' => ''],
            mocks: ['cookies']
        );
        $this->respond([['summary' => 'new.sig'], ['summary' => 'bought.sig']]);

        $service->sync(1);

        $this->assertCount(2, $this->calls);
        $this->assertArrayNotHasKey('device', $this->calls[0]['body']);
        $this->assertArrayNotHasKey('device', $this->calls[1]['body']);
    }

    public function testAPortInTheHostHeaderDoesNotBreakTheSameHostRefererCheck(): void
    {
        $service = $this->service(
            server: ['HTTP_HOST' => 'shop.test:8443', 'HTTP_REFERER' => 'https://shop.test/sale?utm_source=x&token=1'],
            mocks: ['cookies']
        );
        $this->respond([['summary' => 'new.sig']]);

        $service->sync(1);

        $this->assertSame('/sale?utm_source=x', $this->calls[0]['body']['arrival']['url']);
    }

    public function testAReturningVisitorEchoesTheExistingSummaryOnANewSession(): void
    {
        $service = $this->service([], 'old.sig', '99999999-9999-4999-8999-999999999999', mocks: ['cookies']);
        $this->respond([['summary' => 'next.sig']]);
        $this->cookies->expects($this->once())->method('writeSession');

        $service->sync(1);

        $this->assertSame('old.sig', $this->calls[0]['body']['summary']);
    }

    public function testCollectorFailureWritesNothingAndReportsUnsynced(): void
    {
        $service = $this->service(mocks: ['cookies']);
        $this->respond([null]);
        $this->cookies->expects($this->never())->method('writeSummary');
        $this->cookies->expects($this->never())->method('writeSession');

        $this->assertSame(
            ['state' => 'granted', 'synced' => false, 'sid' => self::SID],
            $service->sync(1)
        );
    }

    public function testASessionResponseWithoutEntryFallsBackToDirect(): void
    {
        $service = $this->service(mocks: ['cookies']);
        $this->respond([['summary' => 'new.sig']]);
        $this->cookies->expects($this->once())->method('writeSession')->with(
            $this->callback(static fn (array $s): bool => $s['src'] === 'direct' && $s['med'] === 'direct' && !isset($s['geo']))
        );

        $service->sync(1);
    }

    public function testAPendingPurchaseIsSyncedAsClaimsOnlyAfterTheSessionStep(): void
    {
        $service = $this->service(['pendingPurchase' => self::PENDING], mocks: ['context', 'cookies']);
        $this->respond([['summary' => 'session.sig'], ['summary' => 'purchase.sig']]);
        $written = [];
        $this->cookies->expects($this->exactly(2))->method('writeSummary')->willReturnCallback(function (string $s) use (&$written): void {
            $written[] = $s;
        });
        $this->context->expects($this->once())->method('clearPendingPurchase');

        $result = $service->sync(1);

        $purchaseBody = $this->calls[1]['body'];
        $this->assertSame('session.sig', $purchaseBody['summary']);
        $this->assertSame('magento-purchase-000000123', $purchaseBody['requestId']);
        $this->assertTrue($purchaseBody['mintOnly']);
        $this->assertSame(
            ['orderId' => '000000123', 'value' => 49.5, 'currency' => 'USD', 'productIds' => [], 'ts' => 1700000100000],
            $purchaseBody['purchase']
        );
        $this->assertGreaterThan($purchaseBody['session']['startedAt'], $purchaseBody['session']['lastActivityAt']);
        $this->assertArrayNotHasKey('arrival', $purchaseBody);
        $this->assertSame(['session.sig', 'purchase.sig'], $written);
        $this->assertTrue($result['synced']);
    }

    public function testAFailedPurchaseSyncKeepsTheMarker(): void
    {
        $service = $this->service(['pendingPurchase' => self::PENDING], 'cur.sig', self::SID, mocks: ['context']);
        $this->respond([null]);
        $this->context->expects($this->never())->method('clearPendingPurchase');

        $result = $service->sync(1);

        $this->assertCount(1, $this->calls);
        $this->assertFalse($result['synced']);
    }

    public function testNoPurchaseSyncWhenTheSessionStepFailed(): void
    {
        $service = $this->service(['pendingPurchase' => self::PENDING], mocks: ['context']);
        $this->respond([null]);
        $this->context->expects($this->never())->method('clearPendingPurchase');

        $service->sync(1);

        $this->assertCount(1, $this->calls);
    }
}
