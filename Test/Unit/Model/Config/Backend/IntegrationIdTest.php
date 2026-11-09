<?php

declare(strict_types=1);

namespace Cwax\Banner\Test\Unit\Model\Config\Backend;

use Cwax\Banner\Model\Config\Backend\IntegrationId;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\TestFramework\Unit\Helper\ObjectManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class IntegrationIdTest extends TestCase
{
    private const INTEGRATION_ID = '7c9e6679-7425-40de-944b-e07fc1f90ae7';

    private function save(string $value): string
    {
        /** @var IntegrationId $model */
        $model = (new ObjectManager($this))->getObject(IntegrationId::class);
        $model->setValue($value);
        $model->beforeSave();

        return (string) $model->getValue();
    }

    public function testAUuidIsSavedTrimmed(): void
    {
        $this->assertSame(self::INTEGRATION_ID, $this->save(' ' . self::INTEGRATION_ID . "\n"));
    }

    public function testAnEmptyValueIsAllowed(): void
    {
        $this->assertSame('', $this->save('  '));
    }

    /** @return array<string, array{string}> */
    public static function invalidValues(): array
    {
        return [
            'truncated paste' => [substr(self::INTEGRATION_ID, 0, 35)],
            'website id label' => ['data-cwax-integration-id="' . self::INTEGRATION_ID . '"'],
            'markup' => ['"><script>alert(1)</script>'],
        ];
    }

    #[DataProvider('invalidValues')]
    public function testAnythingElseIsRejected(string $value): void
    {
        $this->expectException(LocalizedException::class);

        $this->save($value);
    }
}
