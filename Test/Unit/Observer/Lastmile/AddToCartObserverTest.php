<?php

declare(strict_types=1);

namespace Cwax\Banner\Test\Unit\Observer\Lastmile;

use Cwax\Banner\Model\Lastmile\EventQueue;
use Cwax\Banner\Model\Lastmile\StoreUrl;
use Cwax\Banner\Observer\Lastmile\AddToCartObserver;
use Magento\Framework\App\State;
use Magento\Framework\Event;
use Magento\Framework\Event\Observer;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class AddToCartObserverTest extends TestCase
{
    public function testConfigurableAddSendsOneEventForTheParentItem(): void
    {
        $parent = $this->item('MH01-XS-Black', 52.0);
        $child = $this->item('MH01-XS-Black', 0.0, $parent);

        $queue = $this->createMock(EventQueue::class);
        $queue->expects($this->once())
            ->method('enqueue')
            ->with('add_to_cart', ['productId' => 'MH01-XS-Black', 'value' => 52.0]);

        $this->observer($queue)->execute($this->event([$parent, $child]));
    }

    public function testSimpleAddSendsOneEvent(): void
    {
        $queue = $this->createMock(EventQueue::class);
        $queue->expects($this->once())
            ->method('enqueue')
            ->with('add_to_cart', ['productId' => '24-MB01', 'value' => 34.0]);

        $this->observer($queue)->execute($this->event([$this->item('24-MB01', 34.0)]));
    }

    public function testUsesRefererPath(): void
    {
        $queue = $this->createMock(EventQueue::class);
        $queue->expects($this->once())
            ->method('enqueue')
            ->with('add_to_cart', ['productId' => '24-MB01', 'value' => 34.0, 'path' => '/ocmlabs/fees.html']);

        $this->observer($queue, '/ocmlabs/fees.html')->execute($this->event([$this->item('24-MB01', 34.0)]));
    }

    public function testFallsBackWithoutReferer(): void
    {
        $queue = $this->createMock(EventQueue::class);
        $queue->expects($this->once())
            ->method('enqueue')
            ->with(
                'add_to_cart',
                $this->callback(static fn (array $attributes): bool => !array_key_exists('path', $attributes))
            );

        $this->observer($queue, null)->execute($this->event([$this->item('24-MB01', 34.0)]));
    }

    private function observer(EventQueue $queue, ?string $referer = null): AddToCartObserver
    {
        $state = $this->createMock(State::class);
        $state->method('getAreaCode')->willReturn('frontend');
        $storeUrl = $this->createStub(StoreUrl::class);
        $storeUrl->method('samePageReferer')->willReturn($referer);
        return new AddToCartObserver($queue, $state, $this->createMock(LoggerInterface::class), $storeUrl);
    }

    /** @param list<object> $items */
    private function event(array $items): Observer
    {
        return new Observer(['event' => new Event(['items' => $items])]);
    }

    private function item(string $sku, float $rowTotal, ?object $parent = null): object
    {
        return new class ($sku, $rowTotal, $parent) {
            public function __construct(
                private readonly string $sku,
                private readonly float $rowTotal,
                private readonly ?object $parent
            ) {
            }

            public function getSku(): string
            {
                return $this->sku;
            }

            public function getRowTotal(): float
            {
                return $this->rowTotal;
            }

            public function getParentItem(): ?object
            {
                return $this->parent;
            }
        };
    }
}
