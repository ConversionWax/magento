<?php

declare(strict_types=1);

namespace Cwax\Banner\Test\Unit\Model\Lastmile;

use Cwax\Banner\Model\Lastmile\EmailHasher;
use Cwax\Banner\Model\Lastmile\EventQueue;
use Cwax\Banner\Model\Lastmile\IdentifyRecorder;
use Magento\Checkout\Model\Session as CheckoutSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class IdentifyRecorderTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $sessionData = [];

    private function session(): CheckoutSession
    {
        // setData reaches the session storage through SessionManager::__call.
        $session = $this->getMockBuilder(CheckoutSession::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getData', '__call'])
            ->getMock();
        $session->method('getData')->willReturnCallback(
            fn (string $key = '') => $this->sessionData[$key] ?? null
        );
        $session->method('__call')->willReturnCallback(
            function (string $method, array $args) use ($session): CheckoutSession {
                if ($method === 'setData') {
                    $this->sessionData[$args[0]] = $args[1];
                }
                return $session;
            }
        );

        return $session;
    }

    private function recorder(EventQueue $queue, ?CheckoutSession $session = null): IdentifyRecorder
    {
        $hasher = $this->createStub(EmailHasher::class);
        $hasher->method('hash')->willReturnCallback(
            static fn (?string $email): ?string => $email === null || trim($email) === ''
                ? null
                : hash('sha256', strtolower(trim($email)))
        );

        return new IdentifyRecorder(
            $queue,
            $hasher,
            $session ?? $this->session(),
            $this->createStub(LoggerInterface::class)
        );
    }

    public function testAGuestEmailSendsOneIdentifyWithTheHashOnly(): void
    {
        $queue = $this->createMock(EventQueue::class);
        $queue->expects($this->once())
            ->method('enqueue')
            ->with('identify', ['emailHash' => hash('sha256', 'guest@example.com')])
            ->willReturn(true);

        $this->recorder($queue)->identifyFromEmail(null, ' Guest@Example.com ');
    }

    public function testTheRawEmailNeverReachesTheQueue(): void
    {
        $queue = $this->createMock(EventQueue::class);
        $queue->expects($this->once())
            ->method('enqueue')
            ->willReturnCallback(function (string $type, array $attributes): bool {
                $this->assertStringNotContainsString('@', json_encode($attributes, JSON_THROW_ON_ERROR));
                return true;
            });

        $this->recorder($queue)->identifyFromEmail('7', 'guest@example.com');
    }

    public function testALoggedInEmailCarriesTheCustomerId(): void
    {
        $queue = $this->createMock(EventQueue::class);
        $queue->expects($this->once())
            ->method('enqueue')
            ->with('identify', ['customerId' => '7', 'emailHash' => hash('sha256', 'shopper@example.com')])
            ->willReturn(true);

        $this->recorder($queue)->identifyFromEmail('7', 'shopper@example.com');
    }

    public function testTheSameEmailInALaterRequestOfTheSameSessionSendsNothing(): void
    {
        $queue = $this->createMock(EventQueue::class);
        $queue->expects($this->once())->method('enqueue')->willReturn(true);
        $session = $this->session();

        // Each request gets a new recorder instance; the checkout session survives.
        $this->recorder($queue, $session)->identifyFromEmail(null, 'guest@example.com');
        $this->recorder($queue, $session)->identifyFromEmail(null, 'GUEST@example.com');
    }

    public function testAChangedEmailSendsANewIdentify(): void
    {
        $queue = $this->createMock(EventQueue::class);
        $queue->expects($this->exactly(2))->method('enqueue')->willReturn(true);
        $session = $this->session();

        $this->recorder($queue, $session)->identifyFromEmail(null, 'first@example.com');
        $this->recorder($queue, $session)->identifyFromEmail(null, 'second@example.com');
    }

    public function testTheSameEmailTwiceInOneRequestSendsOneIdentify(): void
    {
        $queue = $this->createMock(EventQueue::class);
        $queue->expects($this->once())->method('enqueue')->willReturn(true);

        $recorder = $this->recorder($queue);
        $recorder->identifyFromEmail(null, 'guest@example.com');
        $recorder->identifyFromEmail(null, 'guest@example.com');
    }

    public function testWithoutConsentTheEmailIsNotRememberedSoALaterConsentStillSends(): void
    {
        // EventQueue answers false when consent (or config) stops the event.
        $session = $this->session();
        $denied = $this->createMock(EventQueue::class);
        $denied->expects($this->once())->method('enqueue')->willReturn(false);
        $this->recorder($denied, $session)->identifyFromEmail(null, 'guest@example.com');
        $this->assertSame([], array_filter($this->sessionData));

        $granted = $this->createMock(EventQueue::class);
        $granted->expects($this->once())->method('enqueue')->willReturn(true);
        $this->recorder($granted, $session)->identifyFromEmail(null, 'guest@example.com');
    }

    public function testTheSessionKeepsAHashNotTheEmail(): void
    {
        $queue = $this->createStub(EventQueue::class);
        $queue->method('enqueue')->willReturn(true);

        $this->recorder($queue)->identifyFromEmail(null, 'guest@example.com');

        $this->assertNotSame([], $this->sessionData);
        $this->assertStringNotContainsString('@', json_encode($this->sessionData, JSON_THROW_ON_ERROR));
    }

    /** @return array<string, array{0: string}> */
    public static function notAnEmail(): array
    {
        return [
            'empty' => [''],
            'spaces' => ['   '],
            'half typed' => ['guest@'],
            'no at sign' => ['guest.example.com'],
        ];
    }

    /** @dataProvider notAnEmail */
    public function testAGuestValueThatIsNotAnEmailSendsNothing(string $value): void
    {
        $queue = $this->createMock(EventQueue::class);
        $queue->expects($this->never())->method('enqueue');

        $this->recorder($queue)->identifyFromEmail(null, $value);
    }

    public function testLoginStillSendsOncePerRequestEvenWhenTheSessionHasTheSameIdentity(): void
    {
        // 0.5.5 behaviour: a login always sends its identify (once per request).
        $queue = $this->createMock(EventQueue::class);
        $queue->expects($this->exactly(2))->method('enqueue')->willReturn(true);
        $session = $this->session();

        $this->recorder($queue, $session)->identifyCustomer('7', 'shopper@example.com');
        $this->recorder($queue, $session)->identifyCustomer('7', 'shopper@example.com');
    }

    public function testALoginInTheSameRequestAsANewsletterSignupSendsOneIdentify(): void
    {
        // Registration with the newsletter box saves the subscriber before customer_register_success
        // and customer_login are dispatched in the same request.
        $queue = $this->createMock(EventQueue::class);
        $queue->expects($this->once())->method('enqueue')->willReturn(true);

        $recorder = $this->recorder($queue);
        $recorder->identifyFromEmail('7', 'shopper@example.com');
        $recorder->identifyCustomer('7', 'shopper@example.com');
        $recorder->identifyCustomer('7', 'shopper@example.com');
    }

    public function testANewsletterSignupAfterLoginInTheSameSessionSendsNothing(): void
    {
        $queue = $this->createMock(EventQueue::class);
        $queue->expects($this->once())->method('enqueue')->willReturn(true);
        $session = $this->session();

        $this->recorder($queue, $session)->identifyCustomer('7', 'shopper@example.com');
        $this->recorder($queue, $session)->identifyFromEmail('7', 'shopper@example.com');
    }

    public function testAFailingQueueNeverThrows(): void
    {
        $queue = $this->createStub(EventQueue::class);
        $queue->method('enqueue')->willThrowException(new \RuntimeException('queue down'));

        $this->recorder($queue)->identifyFromEmail(null, 'guest@example.com');
        $this->addToAssertionCount(1);
    }

    public function testAFailingSessionNeverThrows(): void
    {
        $queue = $this->createStub(EventQueue::class);
        $queue->method('enqueue')->willReturn(true);
        $session = $this->createStub(CheckoutSession::class);
        $session->method('getData')->willThrowException(new \RuntimeException('session down'));

        $this->recorder($queue, $session)->identifyFromEmail(null, 'guest@example.com');
        $this->addToAssertionCount(1);
    }
}
