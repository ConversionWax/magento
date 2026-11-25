<?php

declare(strict_types=1);

namespace Cwax\Banner\Model\Lastmile;

use Magento\Checkout\Model\Session as CheckoutSession;
use Psr\Log\LoggerInterface;

/**
 * The one place that queues `identify` events, so every source shares the same rules:
 *
 * - The email is hashed with EmailHasher; the raw email never reaches the queue or the session.
 * - EventQueue applies the consent resolvers, exactly as for every other commerce event.
 * - One identify per identity (customer ID plus email hash) per request, for every source.
 * - Guest checkout email and newsletter signup also send one identify per identity per visitor
 *   session: the session keeps the last identity that was queued, so a reload or a second save of the
 *   same email sends nothing and a changed email sends a new identify. Login and registration keep
 *   the 0.5.5 rule (one per request) and do not read the session.
 * - An identity is remembered only when EventQueue queued the event, so an email that was stopped by
 *   missing consent is sent when the visitor gives consent and saves the email again.
 *
 * Magento shares one instance per request.
 */
class IdentifyRecorder
{
    private const SESSION_KEY = 'cwax_lastmile_identify_key';

    /** @var array<string, true> identities handled in this request */
    private array $seen = [];

    public function __construct(
        private readonly EventQueue $eventQueue,
        private readonly EmailHasher $emailHasher,
        private readonly CheckoutSession $checkoutSession,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Login and registration: the customer is known.
     */
    public function identifyCustomer(string $customerId, ?string $email): void
    {
        $this->record($customerId, $email, false);
    }

    /**
     * Guest checkout email and newsletter signup. The customer ID is set only for a logged-in customer.
     */
    public function identifyFromEmail(?string $customerId, ?string $email): void
    {
        $email = trim((string) $email);
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return;
        }
        $this->record($customerId, $email, true);
    }

    private function record(?string $customerId, ?string $email, bool $oncePerSession): void
    {
        try {
            $customerId = trim((string) $customerId);
            $emailHash = $this->emailHasher->hash($email);
            if ($customerId === '' && $emailHash === null) {
                return;
            }

            $key = $customerId . '|' . (string) $emailHash;
            if (isset($this->seen[$key])) {
                return;
            }
            $this->seen[$key] = true;
            if ($oncePerSession && $this->lastSessionKey() === $key) {
                return;
            }

            $queued = $this->eventQueue->enqueue('identify', array_filter([
                'customerId' => $customerId,
                'emailHash' => $emailHash,
            ], static fn (?string $value): bool => $value !== null && $value !== ''));
            if ($queued) {
                $this->rememberSessionKey($key);
            }
        } catch (\Throwable $exception) {
            // Analytics must never stop login, registration, checkout or a newsletter signup.
            $this->logger->error('ConversionWax Last Mile could not build an identify event.', [
                'exception' => $exception,
            ]);
        }
    }

    private function lastSessionKey(): ?string
    {
        try {
            $value = $this->checkoutSession->getData(self::SESSION_KEY);
        } catch (\Throwable) {
            return null;
        }

        return is_string($value) ? $value : null;
    }

    private function rememberSessionKey(string $key): void
    {
        try {
            $this->checkoutSession->setData(self::SESSION_KEY, $key);
        } catch (\Throwable $exception) {
            $this->logger->warning('ConversionWax Last Mile could not remember an identify in the session.', [
                'exception' => $exception,
            ]);
        }
    }
}
