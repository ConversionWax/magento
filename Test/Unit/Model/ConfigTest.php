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
    #[DataProvider('sessionUrlProvider')]
    public function testSessionUrlIsDerivedFromTheEventsEndpoint(string $endpoint, string $expected): void
    {
        $scopeConfig = $this->createStub(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturnCallback(
            static fn (string $path): string => $path === 'conversionwax_lastmile/connection/endpoint'
                ? $endpoint
                : ''
        );
        $config = new Config($scopeConfig, $this->createStub(EncryptorInterface::class));

        $this->assertSame($expected, $config->getSessionUrl(1));
    }

    /** @return array<string, array{string, string}> */
    public static function sessionUrlProvider(): array
    {
        return [
            'production' => [
                'https://collect.conversionwax.com/v1/integrations/magento/events',
                'https://collect.conversionwax.com/v1/session',
            ],
            'staging with alias path' => [
                'https://dev.collect.conversionwax.com/v1/adapters/magento/events',
                'https://dev.collect.conversionwax.com/v1/session',
            ],
            'custom port and trailing slash' => [
                'https://collector.example.test:8443/v1/integrations/magento/events/',
                'https://collector.example.test:8443/v1/session',
            ],
            'plain http is refused' => ['http://collector.example.test/v1/x', ''],
            'empty endpoint' => ['', ''],
            'garbage' => ['not a url', ''],
        ];
    }
}
