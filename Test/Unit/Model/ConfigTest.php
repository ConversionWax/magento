<?php

declare(strict_types=1);

namespace Cwax\Banner\Test\Unit\Model;

use Cwax\Banner\Model\Config;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ConfigTest extends TestCase
{
    private const WEBSITE_ID = '0f8fad5b-d9cb-469f-a165-70867728950e';
    private const INTEGRATION_ID = '7c9e6679-7425-40de-944b-e07fc1f90ae7';

    public function testTheStorefrontSnippetSwitchFollowsTheStoreFlag(): void
    {
        $scopeConfig = $this->createStub(ScopeConfigInterface::class);
        $scopeConfig->method('isSetFlag')->willReturnCallback(
            static fn (string $path, string $scope, int $storeId): bool =>
                $path === 'conversionwax_lastmile/connection/storefront_snippet' && $storeId === 1
        );
        $config = new Config($scopeConfig, $this->createStub(EncryptorInterface::class));

        $this->assertTrue($config->isStorefrontSnippetEnabled(1));
        $this->assertFalse($config->isStorefrontSnippetEnabled(2));
    }

    /** @return array<string, array{string, string}> */
    public static function integrationIdProvider(): array
    {
        return [
            'uuid' => [self::INTEGRATION_ID, self::INTEGRATION_ID],
            'surrounding spaces' => ['  ' . self::INTEGRATION_ID . ' ', self::INTEGRATION_ID],
            'empty' => ['', ''],
            'truncated' => [substr(self::INTEGRATION_ID, 0, 30), ''],
            'markup' => ['"><script>alert(1)</script>', ''],
        ];
    }

    #[DataProvider('integrationIdProvider')]
    public function testOnlyAUuidIsUsedAsTheIntegrationId(string $stored, string $expected): void
    {
        $config = $this->configWith(['conversionwax_lastmile/connection/integration_id' => $stored]);

        $this->assertSame($expected, $config->getIntegrationId(1));
    }

    /** @return array<string, array{bool, bool, string, bool}> */
    public static function snippetProvider(): array
    {
        return [
            'configured, switch on, integration id' => [true, true, self::INTEGRATION_ID, true],
            'switch off' => [true, false, self::INTEGRATION_ID, false],
            'no integration id' => [true, true, '', false],
            'not configured' => [false, true, self::INTEGRATION_ID, false],
        ];
    }

    #[DataProvider('snippetProvider')]
    public function testTheSnippetLoadsOnlyWhenConfiguredSwitchedOnAndIdentified(
        bool $enabled,
        bool $storefront,
        string $integrationId,
        bool $expected
    ): void {
        $scopeConfig = $this->createStub(ScopeConfigInterface::class);
        $scopeConfig->method('isSetFlag')->willReturnCallback(
            static fn (string $path): bool => match ($path) {
                'conversionwax_lastmile/connection/enabled' => $enabled,
                'conversionwax_lastmile/connection/storefront_snippet' => $storefront,
                default => false,
            }
        );
        $scopeConfig->method('getValue')->willReturnCallback(
            static fn (string $path): string => match ($path) {
                'conversionwax_lastmile/connection/web_property_id' => self::WEBSITE_ID,
                'conversionwax_lastmile/connection/api_token' => 'encrypted',
                'conversionwax_lastmile/connection/integration_id' => $integrationId,
                default => '',
            }
        );
        $encryptor = $this->createStub(EncryptorInterface::class);
        $encryptor->method('decrypt')->willReturn('token');

        $this->assertSame($expected, (new Config($scopeConfig, $encryptor))->shouldLoadSnippet(1));
    }

    /** @return array<string, array{string, string}> */
    public static function snippetUrlProvider(): array
    {
        return [
            'production' => ['production', 'https://assets.conversionwax.com/lastmile/v1/lm.js'],
            'staging' => ['staging', 'https://dev.assets.conversionwax.com/lastmile/v1/lm.js'],
            'unset falls back to production' => ['', 'https://assets.conversionwax.com/lastmile/v1/lm.js'],
            'merchant URL ignored' => [
                'https://evil.example/lm.js',
                'https://assets.conversionwax.com/lastmile/v1/lm.js',
            ],
        ];
    }

    #[DataProvider('snippetUrlProvider')]
    public function testEnvironmentSelectsTheSnippetUrl(string $environment, string $url): void
    {
        $config = $this->configWith(['conversionwax_lastmile/connection/environment' => $environment]);

        $this->assertSame($url, $config->getSnippetUrl(1));
    }

    #[DataProvider('environmentProvider')]
    public function testEnvironmentSelectsTheCollectorUrls(
        string $environment,
        string $host,
        string $eventsUrl
    ): void {
        $config = $this->configWith(['conversionwax_lastmile/connection/environment' => $environment]);

        $this->assertSame($host, $config->getCollectorHost(1));
        $this->assertSame($eventsUrl, $config->getEventsUrl(1));
    }

    /** @return array<string, array{string, string, string}> */
    public static function environmentProvider(): array
    {
        return [
            'production' => [
                'production',
                'https://collect.conversionwax.com',
                'https://collect.conversionwax.com/v1/integrations/events',
            ],
            'staging' => [
                'staging',
                'https://dev.collect.conversionwax.com',
                'https://dev.collect.conversionwax.com/v1/integrations/events',
            ],
            'unset falls back to production' => [
                '',
                'https://collect.conversionwax.com',
                'https://collect.conversionwax.com/v1/integrations/events',
            ],
            'unknown value falls back to production' => [
                'local',
                'https://collect.conversionwax.com',
                'https://collect.conversionwax.com/v1/integrations/events',
            ],
        ];
    }

    #[DataProvider('merchantUrlProvider')]
    public function testNoMerchantEnteredUrlIsAccepted(string $value): void
    {
        $config = $this->configWith([
            'conversionwax_lastmile/connection/environment' => $value,
            'conversionwax_lastmile/connection/endpoint' => $value,
        ]);

        $this->assertSame('https://collect.conversionwax.com/v1/integrations/events', $config->getEventsUrl(1));
    }

    /** @return array<string, array{string}> */
    public static function merchantUrlProvider(): array
    {
        return [
            'foreign host' => ['https://evil.example/v1/x'],
            'staging URL typed in' => ['https://dev.collect.conversionwax.com/v1/integrations/events'],
            'allowed name as subdomain of foreign host' => ['https://collect.conversionwax.com.evil.com'],
            'bare host' => ['dev.collect.conversionwax.com'],
        ];
    }

    public function testEnvironmentIsReadPerStore(): void
    {
        $scopeConfig = $this->createStub(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturnCallback(
            static fn (string $path, string $scope, int $storeId): string =>
                $path === 'conversionwax_lastmile/connection/environment' && $storeId === 2 ? 'staging' : ''
        );
        $config = new Config($scopeConfig, $this->createStub(EncryptorInterface::class));

        $this->assertSame('https://collect.conversionwax.com', $config->getCollectorHost(1));
        $this->assertSame('https://dev.collect.conversionwax.com', $config->getCollectorHost(2));
    }

    public function testConfiguredNeedsOnlyEnabledWebsiteIdAndToken(): void
    {
        $scopeConfig = $this->createStub(ScopeConfigInterface::class);
        $scopeConfig->method('isSetFlag')->willReturn(true);
        $scopeConfig->method('getValue')->willReturnCallback(
            static fn (string $path): string => match ($path) {
                'conversionwax_lastmile/connection/web_property_id' => self::WEBSITE_ID,
                'conversionwax_lastmile/connection/api_token' => 'encrypted',
                default => '',
            }
        );
        $encryptor = $this->createStub(EncryptorInterface::class);
        $encryptor->method('decrypt')->willReturn('token');
        $config = new Config($scopeConfig, $encryptor);

        $this->assertTrue($config->isConfigured(1));
    }

    /**
     * @param array<string, string> $values
     */
    private function configWith(array $values): Config
    {
        $scopeConfig = $this->createStub(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturnCallback(
            static fn (string $path): string => $values[$path] ?? ''
        );

        return new Config($scopeConfig, $this->createStub(EncryptorInterface::class));
    }
}
