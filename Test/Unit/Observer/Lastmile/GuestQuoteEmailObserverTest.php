<?php

declare(strict_types=1);

namespace Cwax\Banner\Test\Unit\Observer\Lastmile;

use Cwax\Banner\Model\Lastmile\IdentifyRecorder;
use Cwax\Banner\Observer\Lastmile\GuestQuoteEmailObserver;
use Magento\Framework\Event;
use Magento\Framework\Event\Observer;
use Magento\Quote\Model\Quote;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class GuestQuoteEmailObserverTest extends TestCase
{
    private function quote(?string $customerId, ?string $email, bool $emailChanged): Quote
    {
        $quote = $this->createStub(Quote::class);
        $quote->method('getData')->willReturnCallback(
            static fn (string $key = '') => ['customer_id' => $customerId, 'customer_email' => $email][$key] ?? null
        );
        $quote->method('dataHasChangedFor')->willReturnCallback(
            static fn (string $field): bool => $field === 'customer_email' && $emailChanged
        );

        return $quote;
    }

    private function dispatch(IdentifyRecorder $recorder, mixed $quote): void
    {
        (new GuestQuoteEmailObserver($recorder, $this->createStub(LoggerInterface::class)))
            ->execute(new Observer(['event' => new Event(['quote' => $quote])]));
    }

    public function testAGuestEmailSavedOnTheQuoteIdentifies(): void
    {
        // Hyva Checkout and other one page checkouts save the guest email on the quote.
        $recorder = $this->createMock(IdentifyRecorder::class);
        $recorder->expects($this->once())->method('identifyFromEmail')->with(null, 'guest@example.com');

        $this->dispatch($recorder, $this->quote(null, 'guest@example.com', true));
    }

    public function testAnUnchangedEmailSendsNothing(): void
    {
        // Every later quote save (totals, shipping, payment) keeps the same email.
        $recorder = $this->createMock(IdentifyRecorder::class);
        $recorder->expects($this->never())->method('identifyFromEmail');

        $this->dispatch($recorder, $this->quote(null, 'guest@example.com', false));
    }

    public function testALoggedInCustomerQuoteSendsNothing(): void
    {
        // The login already sent identify with the customer id.
        $recorder = $this->createMock(IdentifyRecorder::class);
        $recorder->expects($this->never())->method('identifyFromEmail');

        $this->dispatch($recorder, $this->quote('7', 'shopper@example.com', true));
    }

    public function testAnEmailThatWasClearedSendsNothing(): void
    {
        $recorder = $this->createMock(IdentifyRecorder::class);
        $recorder->expects($this->never())->method('identifyFromEmail');

        $this->dispatch($recorder, $this->quote(null, '', true));
    }

    public function testAnEventWithoutAQuoteSendsNothing(): void
    {
        $recorder = $this->createMock(IdentifyRecorder::class);
        $recorder->expects($this->never())->method('identifyFromEmail');

        $this->dispatch($recorder, null);
    }

    public function testAFailureNeverStopsTheQuoteSave(): void
    {
        $recorder = $this->createStub(IdentifyRecorder::class);
        $recorder->method('identifyFromEmail')->willThrowException(new \RuntimeException('down'));

        $this->dispatch($recorder, $this->quote(null, 'guest@example.com', true));
        $this->addToAssertionCount(1);
    }
}
