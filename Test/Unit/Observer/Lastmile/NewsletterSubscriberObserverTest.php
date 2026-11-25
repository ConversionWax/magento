<?php

declare(strict_types=1);

namespace Cwax\Banner\Test\Unit\Observer\Lastmile;

use Cwax\Banner\Model\Lastmile\IdentifyRecorder;
use Cwax\Banner\Observer\Lastmile\NewsletterSubscriberObserver;
use Magento\Framework\Event;
use Magento\Framework\Event\Observer;
use Magento\Newsletter\Model\Subscriber;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class NewsletterSubscriberObserverTest extends TestCase
{
    private function subscriber(int $status, ?int $origStatus, ?string $customerId = '0'): Subscriber
    {
        $subscriber = $this->createStub(Subscriber::class);
        $subscriber->method('getData')->willReturnCallback(
            static fn (string $key = '') => [
                'subscriber_status' => (string) $status,
                'subscriber_email' => 'reader@example.com',
                'customer_id' => $customerId,
            ][$key] ?? null
        );
        $subscriber->method('getOrigData')->willReturnCallback(
            static fn (?string $key = null) => $key === 'subscriber_status' && $origStatus !== null
                ? (string) $origStatus
                : null
        );

        return $subscriber;
    }

    private function dispatch(IdentifyRecorder $recorder, mixed $subscriber): void
    {
        (new NewsletterSubscriberObserver($recorder, $this->createStub(LoggerInterface::class)))
            ->execute(new Observer(['event' => new Event(['subscriber' => $subscriber])]));
    }

    public function testAGuestSignupIdentifiesWithTheEmailOnly(): void
    {
        $recorder = $this->createMock(IdentifyRecorder::class);
        $recorder->expects($this->once())->method('identifyFromEmail')->with(null, 'reader@example.com');

        $this->dispatch($recorder, $this->subscriber(Subscriber::STATUS_SUBSCRIBED, null));
    }

    public function testALoggedInSignupCarriesTheCustomerId(): void
    {
        $recorder = $this->createMock(IdentifyRecorder::class);
        $recorder->expects($this->once())->method('identifyFromEmail')->with('7', 'reader@example.com');

        $this->dispatch(
            $recorder,
            $this->subscriber(Subscriber::STATUS_SUBSCRIBED, Subscriber::STATUS_UNSUBSCRIBED, '7')
        );
    }

    public function testASignupThatNeedsConfirmationIdentifies(): void
    {
        // With "Need to Confirm" on, the signup saves status Not Activated.
        $recorder = $this->createMock(IdentifyRecorder::class);
        $recorder->expects($this->once())->method('identifyFromEmail');

        $this->dispatch($recorder, $this->subscriber(Subscriber::STATUS_NOT_ACTIVE, null));
    }

    public function testTheConfirmationLinkSendsNothing(): void
    {
        // The link is often opened on another device; the signup already sent identify.
        $recorder = $this->createMock(IdentifyRecorder::class);
        $recorder->expects($this->never())->method('identifyFromEmail');

        $this->dispatch(
            $recorder,
            $this->subscriber(Subscriber::STATUS_SUBSCRIBED, Subscriber::STATUS_NOT_ACTIVE)
        );
    }

    public function testAnAlreadySubscribedSaveSendsNothing(): void
    {
        $recorder = $this->createMock(IdentifyRecorder::class);
        $recorder->expects($this->never())->method('identifyFromEmail');

        $this->dispatch(
            $recorder,
            $this->subscriber(Subscriber::STATUS_SUBSCRIBED, Subscriber::STATUS_SUBSCRIBED, '7')
        );
    }

    public function testAnUnsubscribeSendsNothing(): void
    {
        $recorder = $this->createMock(IdentifyRecorder::class);
        $recorder->expects($this->never())->method('identifyFromEmail');

        $this->dispatch(
            $recorder,
            $this->subscriber(Subscriber::STATUS_UNSUBSCRIBED, Subscriber::STATUS_SUBSCRIBED, '7')
        );
    }

    public function testAnEventWithoutASubscriberSendsNothing(): void
    {
        $recorder = $this->createMock(IdentifyRecorder::class);
        $recorder->expects($this->never())->method('identifyFromEmail');

        $this->dispatch($recorder, null);
    }

    public function testAFailureNeverStopsTheSignup(): void
    {
        $recorder = $this->createStub(IdentifyRecorder::class);
        $recorder->method('identifyFromEmail')->willThrowException(new \RuntimeException('down'));

        $this->dispatch($recorder, $this->subscriber(Subscriber::STATUS_SUBSCRIBED, null));
        $this->addToAssertionCount(1);
    }
}
