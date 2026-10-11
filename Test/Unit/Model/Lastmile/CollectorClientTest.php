<?php

declare(strict_types=1);

namespace Cwax\Banner\Test\Unit\Model\Lastmile;

use Cwax\Banner\Model\Config;
use Cwax\Banner\Model\Lastmile\CollectorClient;
use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\HTTP\Client\CurlFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class CollectorClientTest extends TestCase
{
    /** @var array<int, mixed> */
    private array $options = [];
    /** @var array<string, string> */
    private array $headers = [];
    private ?string $postedUrl = null;
    private ?string $postedBody = null;
    private MockObject&LoggerInterface $logger;

    private function client(
        int $status,
        string $body,
        string $url = 'https://collect.test/v1/session',
        ?\Throwable $throws = null,
        string $token = ''
    ): CollectorClient {
        $curl = $this->createMock(Curl::class);
        $curl->method('setOption')->willReturnCallback(function (int $key, $value): void {
            $this->options[$key] = $value;
        });
        $curl->method('addHeader')->willReturnCallback(function (string $name, string $value): void {
            $this->headers[$name] = $value;
        });
        $curl->method('post')->willReturnCallback(function (string $url, $params) use ($throws): void {
            if ($throws !== null) {
                throw $throws;
            }
            $this->postedUrl = $url;
            $this->postedBody = (string) $params;
        });
        $curl->method('getStatus')->willReturn($status);
        $curl->method('getBody')->willReturn($body);

        $factory = $this->createMock(CurlFactory::class);
        $factory->method('create')->willReturn($curl);
        $config = $this->createMock(Config::class);
        $config->method('getSessionUrl')->willReturn($url);
        $config->method('getApiToken')->willReturn($token);
        $this->logger = $this->createMock(LoggerInterface::class);

        return new CollectorClient($factory, $config, $this->logger);
    }

    public function testPostsMintOnlyWithShortTimeoutsAndForwardsIpAndUserAgent(): void
    {
        $client = $this->client(200, '{"summary":"a.b","sessionId":"s"}');

        $result = $client->session(1, ['storeId' => 'x'], '203.0.113.9', 'Mozilla/5.0 Test');

        $this->assertSame('a.b', $result['summary']);
        $this->assertSame('s', $result['sessionId']);
        $this->assertSame('https://collect.test/v1/session', $this->postedUrl);
        $this->assertSame(
            ['storeId' => 'x', 'mintOnly' => true, 'device' => ['ua' => 'Mozilla/5.0 Test']],
            json_decode((string) $this->postedBody, true)
        );
        $this->assertSame(200, $this->options[CURLOPT_CONNECTTIMEOUT_MS]);
        $this->assertSame(300, $this->options[CURLOPT_TIMEOUT_MS]);
        $this->assertSame(1, $this->options[CURLOPT_NOSIGNAL]);
        $this->assertSame('203.0.113.9', $this->headers['X-Forwarded-For']);
        $this->assertSame('application/json', $this->headers['Content-Type']);
        $this->assertArrayNotHasKey('Authorization', $this->headers);
    }

    public function testMintOnlyIsForcedAndDeviceFieldsArePreserved(): void
    {
        $this->client(200, '{"summary":"a.b"}')->session(
            1,
            ['mintOnly' => false, 'device' => ['deviceType' => 'mobile', 'ua' => 'spoof']],
            null,
            'Real UA'
        );

        $sent = json_decode((string) $this->postedBody, true);
        $this->assertTrue($sent['mintOnly']);
        $this->assertSame('Real UA', $sent['device']['ua']);
        $this->assertArrayNotHasKey('deviceType', $sent['device']);
        $this->assertArrayNotHasKey('claimsOnly', $sent);
    }

    public function testUserAgentIsCappedAtTheCollectorLimit(): void
    {
        $this->client(200, '{"summary":"a.b"}')->session(1, [], null, str_repeat('a', 2000));

        $sent = json_decode((string) $this->postedBody, true);
        $this->assertSame(1024, strlen($sent['device']['ua']));
    }

    public function testEmptyUserAgentSendsNoDevice(): void
    {
        $this->client(200, '{"summary":"a.b"}')->session(1, [], null, '');

        $this->assertArrayNotHasKey('device', json_decode((string) $this->postedBody, true));
    }

    public function testOmitsForwardedForWhenTheIpIsUnknown(): void
    {
        $this->client(200, '{"summary":"a.b"}')->session(1, [], null, 'UA');

        $this->assertArrayNotHasKey('X-Forwarded-For', $this->headers);
    }

    public function testSendsBearerTokenWhenConfiguredAndNeverLogsIt(): void
    {
        $client = $this->client(
            503,
            '',
            'https://collect.test/v1/session',
            null,
            'secret-token'
        );
        $this->logger->expects($this->any())->method('info')->willReturnCallback(
            function (string $message, array $context = []): void {
                $this->assertStringNotContainsString('secret-token', $message . json_encode($context));
            }
        );

        $client->session(1, [], '1.2.3.4', 'UA');

        $this->assertSame('Bearer secret-token', $this->headers['Authorization']);
    }

    public function testUnauthorizedLogsOneConfigurationWarningWithoutTheToken(): void
    {
        $client = $this->client(401, '{"error":"invalid credentials"}', 'https://collect.test/v1/session', null, 'secret-token');
        $this->logger->expects($this->once())->method('warning')->with(
            $this->callback(static fn (string $m): bool => !str_contains($m, 'secret-token')),
            $this->callback(static fn (array $c): bool => !str_contains(json_encode($c), 'secret-token'))
        );

        $this->assertNull($client->session(1, [], '1.2.3.4', 'UA'));
    }

    #[DataProvider('failures')]
    public function testFailuresReturnNull(int $status, string $body, ?\Throwable $throws): void
    {
        $this->assertNull(
            $this->client($status, $body, 'https://collect.test/v1/session', $throws)->session(1, [], '1.2.3.4', 'UA')
        );
    }

    /** @return array<string, array{int, string, ?\Throwable}> */
    public static function failures(): array
    {
        return [
            '503' => [503, '{"error":"x"}', null],
            '401' => [401, '{"error":"invalid credentials"}', null],
            '204 (disabled store)' => [204, '', null],
            'not json' => [200, '<html>', null],
            'no summary' => [200, '{"sessionId":"s"}', null],
            'summary not a string' => [200, '{"summary":5}', null],
            'timeout' => [0, '', new \RuntimeException('Operation timed out')],
        ];
    }

    public function testNoUrlMeansNoRequest(): void
    {
        $factory = $this->createMock(CurlFactory::class);
        $factory->expects($this->never())->method('create');
        $config = $this->createMock(Config::class);
        $config->method('getSessionUrl')->willReturn('');
        $client = new CollectorClient($factory, $config, $this->createMock(LoggerInterface::class));

        $this->assertNull($client->session(1, [], '1.2.3.4', 'UA'));
    }
}
