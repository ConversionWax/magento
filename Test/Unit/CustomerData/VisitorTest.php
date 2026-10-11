<?php

declare(strict_types=1);

namespace Cwax\Banner\Test\Unit\CustomerData;

use Cwax\Banner\CustomerData\Visitor;
use Cwax\Banner\Model\Config;
use Cwax\Banner\Model\Lastmile\VisitorContext;
use Cwax\Banner\Model\Lastmile\VisitorSyncService;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class VisitorTest extends TestCase
{
    private VisitorSyncService&MockObject $sync;
    private VisitorContext&MockObject $context;

    private function section(bool $configured): Visitor
    {
        $config = $this->createStub(Config::class);
        $config->method('isConfigured')->willReturn($configured);
        $store = $this->createStub(StoreInterface::class);
        $store->method('getId')->willReturn(3);
        $stores = $this->createStub(StoreManagerInterface::class);
        $stores->method('getStore')->willReturn($store);
        $this->sync = $this->createMock(VisitorSyncService::class);
        $this->context = $this->createMock(VisitorContext::class);

        return new Visitor(
            $config,
            $stores,
            $this->sync,
            $this->context,
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
}
