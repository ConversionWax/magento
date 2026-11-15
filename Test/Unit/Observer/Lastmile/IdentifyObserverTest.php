<?php

declare(strict_types=1);

namespace Cwax\Banner\Test\Unit\Observer\Lastmile;

use Cwax\Banner\Model\Lastmile\EmailHasher;
use Cwax\Banner\Model\Lastmile\EventQueue;
use Cwax\Banner\Observer\Lastmile\IdentifyObserver;
use Magento\Framework\Event;
use Magento\Framework\Event\Observer;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class IdentifyObserverTest extends TestCase
{
    public function testSignInSendsOneIdentify(): void
    {
        $queue = $this->createMock(EventQueue::class);
        $queue->expects($this->once())
            ->method('enqueue')
            ->with('identify', ['customerId' => '7', 'emailHash' => 'hash']);

        $this->observer($queue)->execute($this->event('7'));
    }

    public function testRegistrationThenAutoLoginSendsOneIdentify(): void
    {
        // Magento dispatches customer_register_success and then customer_login for the new
        // customer in the same request; both reach this shared observer instance.
        $queue = $this->createMock(EventQueue::class);
        $queue->expects($this->once())->method('enqueue');

        $observer = $this->observer($queue);
        $observer->execute($this->event('7'));
        $observer->execute($this->event('7'));
    }

    public function testADifferentCustomerInTheSameRequestStillSendsIdentify(): void
    {
        $queue = $this->createMock(EventQueue::class);
        $queue->expects($this->exactly(2))->method('enqueue');

        $observer = $this->observer($queue);
        $observer->execute($this->event('7'));
        $observer->execute($this->event('8'));
    }

    public function testCustomerWithoutIdSendsNothing(): void
    {
        $queue = $this->createMock(EventQueue::class);
        $queue->expects($this->never())->method('enqueue');

        $this->observer($queue)->execute($this->event(''));
    }

    private function observer(EventQueue $queue): IdentifyObserver
    {
        $hasher = $this->createMock(EmailHasher::class);
        $hasher->method('hash')->willReturn('hash');

        return new IdentifyObserver($queue, $hasher, $this->createMock(LoggerInterface::class));
    }

    private function event(string $customerId): Observer
    {
        $customer = new class ($customerId) {
            public function __construct(private readonly string $id)
            {
            }

            public function getId(): string
            {
                return $this->id;
            }

            public function getEmail(): string
            {
                return 'shopper@example.com';
            }
        };
        $event = new Event(['customer' => $customer]);

        return new Observer(['event' => $event]);
    }
}
