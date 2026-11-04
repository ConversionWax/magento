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
    public function testHyvaSupportFollowsTheStoreFlag(): void
    {
        $scopeConfig = $this->createStub(ScopeConfigInterface::class);
        $scopeConfig->method('isSetFlag')->willReturnCallback(
            static fn (string $path, string $scope, int $storeId): bool =>
                $path === 'conversionwax_lastmile/connection/hyva_enabled' && $storeId === 1
        );
        $config = new Config($scopeConfig, $this->createStub(EncryptorInterface::class));

        $this->assertTrue($config->isHyvaEnabled(1));
        $this->assertFalse($config->isHyvaEnabled(2));
    }

    #[DataProvider('environmentProvider')]
    public function testEnvironmentSelectsTheCollectorUrls(
        string $environment,
        string $host,
        string $eventsUrl,
        string $sessionUrl
    ): void {
        $config = $this->configWith(['conversionwax_lastmile/connection/environment' => $environment]);

        $this->assertSame($host, $config->getCollectorHost(1));
        $this->assertSame($eventsUrl, $config->getEventsUrl(1));
        $this->assertSame($sessionUrl, $config->getSessionUrl(1));
    }

    /** @return array<string, array{string, string, string, string}> */
    public static function environmentProvider(): array
    {
        return [
            'production' => [
                'production',
                'https://collect.conversionwax.com',
                'https://collect.conversionwax.com/v1/integrations/events',
                'https://collect.conversionwax.com/v1/session',
            ],
            'staging' => [
                'staging',
                'https://dev.collect.conversionwax.com',
                'https://dev.collect.conversionwax.com/v1/integrations/events',
                'https://dev.collect.conversionwax.com/v1/session',
            ],
            'unset falls back to production' => [
                '',
                'https://collect.conversionwax.com',
                'https://collect.conversionwax.com/v1/integrations/events',
                'https://collect.conversionwax.com/v1/session',
            ],
            'unknown value falls back to production' => [
                'local',
                'https://collect.conversionwax.com',
                'https://collect.conversionwax.com/v1/integrations/events',
                'https://collect.conversionwax.com/v1/session',
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
        $this->assertSame('https://collect.conversionwax.com/v1/session', $config->getSessionUrl(1));
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
                'conversionwax_lastmile/connection/web_property_id' => '0f8fad5b-d9cb-469f-a165-70867728950e',
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
