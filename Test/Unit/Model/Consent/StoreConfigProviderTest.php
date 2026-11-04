<?php

declare(strict_types=1);

namespace Cwax\Banner\Test\Unit\Model\Consent;

use Cwax\Banner\Api\StoreConfigProviderInterface;
use Cwax\Banner\Model\Config;
use Cwax\Banner\Model\Consent\ConsentConfigCacheType;
use Cwax\Banner\Model\Consent\StoreConfigProvider;
use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\HTTP\Client\CurlFactory;
use Magento\Framework\Serialize\Serializer\Json;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class StoreConfigProviderTest extends TestCase
{
    private const WEBSITE_ID = '0f8fad5b-d9cb-469f-a165-70867728950e';

    /** @var list<string> */
    private array $requested = [];

    /** @var array<string, string> */
    private array $headers = [];

    /** @var list<array{string, string, int}> */
    private array $saved = [];

    protected function setUp(): void
    {
        $this->requested = [];
        $this->headers = [];
        $this->saved = [];
    }

    public function testConsentModeIsReadFromTheIntegrationsConfigRouteWithTheToken(): void
    {
        $provider = $this->provider(200, '{"consentMode":"wait_for_consent","enabled":true}');

        $this->assertSame(
            StoreConfigProviderInterface::CONSENT_MODE_WAIT_FOR_CONSENT,
            $provider->getConsentMode(1)
        );
        $this->assertSame(['https://dev.collect.conversionwax.com/v1/integrations/config'], $this->requested);
        $this->assertSame('Bearer secret-token', $this->headers['Authorization'] ?? null);
        $this->assertSame(
            [[
                StoreConfigProviderInterface::CONSENT_MODE_WAIT_FOR_CONSENT,
                'cwax_banner_consent_mode_' . self::WEBSITE_ID,
                300,
            ]],
            $this->saved
        );
    }

    public function testNoUrlCarriesTheWebsiteId(): void
    {
        $this->provider(200, '{"consentMode":"capture_immediately"}')->getConsentMode(1);

        $this->assertStringNotContainsString(self::WEBSITE_ID, $this->requested[0]);
    }

    /** @return array<string, array{int, string}> */
    public static function unresolvedProvider(): array
    {
        return [
            'unknown or inactive token' => [401, ''],
            'server error' => [503, ''],
            'not json' => [200, 'nope'],
            'unknown mode' => [200, '{"consentMode":"something_else"}'],
        ];
    }

    #[DataProvider('unresolvedProvider')]
    public function testFailuresResolveToNullAndAreNegativelyCached(int $status, string $body): void
    {
        $this->assertNull($this->provider($status, $body)->getConsentMode(1));
        $this->assertSame([['__unresolved__', 'cwax_banner_consent_mode_' . self::WEBSITE_ID, 30]], $this->saved);
    }

    public function testNoTokenMeansNoRequest(): void
    {
        $this->assertNull($this->provider(200, '{"consentMode":"capture_immediately"}', '')->getConsentMode(1));
        $this->assertSame([], $this->requested);
    }

    public function testACachedModeIsUsedWithoutARequest(): void
    {
        $provider = $this->provider(200, '', 'secret-token', 'capture_immediately');

        $this->assertSame(StoreConfigProviderInterface::CONSENT_MODE_CAPTURE_IMMEDIATELY, $provider->getConsentMode(1));
        $this->assertSame([], $this->requested);
    }

    private function provider(
        int $status,
        string $body,
        string $token = 'secret-token',
        string|false $cached = false
    ): StoreConfigProvider {
        $config = $this->createStub(Config::class);
        $config->method('getWebPropertyId')->willReturn(self::WEBSITE_ID);
        $config->method('getApiToken')->willReturn($token);
        $config->method('getCollectorHost')->willReturn('https://dev.collect.conversionwax.com');

        $cache = $this->createStub(ConsentConfigCacheType::class);
        $cache->method('load')->willReturn($cached);
        $cache->method('save')->willReturnCallback(
            function (string $data, string $key, array $tags, int $lifetime): bool {
                $this->saved[] = [$data, $key, $lifetime];
                return true;
            }
        );

        $curl = $this->createStub(Curl::class);
        $curl->method('addHeader')->willReturnCallback(
            function (string $name, string $value): void {
                $this->headers[$name] = $value;
            }
        );
        $curl->method('get')->willReturnCallback(
            function (string $url): void {
                $this->requested[] = $url;
            }
        );
        $curl->method('getStatus')->willReturn($status);
        $curl->method('getBody')->willReturn($body);
        $curls = $this->createStub(CurlFactory::class);
        $curls->method('create')->willReturn($curl);

        return new StoreConfigProvider(
            $config,
            $cache,
            $curls,
            new Json(),
            $this->createStub(LoggerInterface::class)
        );
    }
}
