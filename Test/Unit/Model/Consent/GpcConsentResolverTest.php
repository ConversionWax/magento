<?php

declare(strict_types=1);

namespace Cwax\Banner\Test\Unit\Model\Consent;

use Cwax\Banner\Api\Data\ConsentDecisionInterface;
use Cwax\Banner\Model\Consent\GpcConsentResolver;
use Magento\Framework\App\RequestInterface;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for GpcConsentResolver: a veto-only resolver that denies both
 * signals when Sec-GPC: 1 is present, and otherwise abstains.
 */
class GpcConsentResolverTest extends TestCase
{
    private const STORE_ID = 1;

    private function makeResolver(RequestInterface $request): GpcConsentResolver
    {
        return new GpcConsentResolver($request);
    }

    private function requestWithHeader(?string $value): RequestInterface
    {
        $request = $this->createMock(RequestInterface::class);
        $request->method('getServer')
            ->with('HTTP_SEC_GPC')
            ->willReturn($value);

        return $request;
    }

    public function testHeaderValueOneDeniesBothSignals(): void
    {
        $resolver = $this->makeResolver($this->requestWithHeader('1'));

        $decision = $resolver->resolve(self::STORE_ID);

        $this->assertFalse($decision->isStorageGranted());
        $this->assertFalse($decision->isMeasurementGranted());
        $this->assertSame(['sec_gpc'], $decision->getDenialReasons());
    }

    public function testHeaderAbsentAbstains(): void
    {
        $resolver = $this->makeResolver($this->requestWithHeader(null));

        $decision = $resolver->resolve(self::STORE_ID);

        $this->assertNull($decision->getSignal(ConsentDecisionInterface::SIGNAL_STORAGE));
        $this->assertNull($decision->getSignal(ConsentDecisionInterface::SIGNAL_MEASUREMENT));
        $this->assertSame([], $decision->getDenialReasons());
    }

    /**
     * @dataProvider nonSignalHeaderValueProvider
     */
    public function testNonSignalHeaderValuesAbstain(string $headerValue): void
    {
        $resolver = $this->makeResolver($this->requestWithHeader($headerValue));

        $decision = $resolver->resolve(self::STORE_ID);

        $this->assertFalse($decision->isStorageGranted());
        $this->assertFalse($decision->isMeasurementGranted());
        $this->assertNull($decision->getSignal(ConsentDecisionInterface::SIGNAL_STORAGE));
        $this->assertSame([], $decision->getDenialReasons());
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function nonSignalHeaderValueProvider(): array
    {
        return [
            'zero' => ['0'],
            'empty string' => [''],
            'word true' => ['true'],
            'value with trailing space' => ['1 '],
            'value with leading space' => [' 1'],
            'other digit' => ['2'],
        ];
    }
}
