<?php

declare(strict_types=1);

namespace Cwax\Banner\Plugin\Lastmile;

use Cwax\Banner\Model\Lastmile\IdentifyRecorder;
use Magento\Checkout\Api\GuestPaymentInformationManagementInterface;
use Psr\Log\LoggerInterface;

/**
 * Luma checkout (also the Luma fallback checkout of Hyva stores) sends the guest email to the server
 * before the order only through set-payment-information (REST
 * /V1/guest-carts/:cartId/set-payment-information), which stores it on the billing address. Many
 * payment methods (redirect and iframe methods) call it. Without such a method, the first server
 * write of the guest email is the order placement, where the quote email is set and
 * GuestQuoteEmailObserver sends the identify.
 */
class GuestPaymentEmailPlugin
{
    public function __construct(
        private readonly IdentifyRecorder $identifyRecorder,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Identify the guest after Magento saved the email.
     *
     * @param GuestPaymentInformationManagementInterface $subject
     * @param mixed $result false when Magento refused the save (rate limit)
     * @param mixed $cartId
     * @param mixed $email
     * @return mixed the result, unchanged
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function afterSavePaymentInformation(
        GuestPaymentInformationManagementInterface $subject,
        $result,
        $cartId,
        $email
    ) {
        if ($result !== true) {
            return $result;
        }
        try {
            $this->identifyRecorder->identifyFromEmail(null, is_string($email) ? $email : null);
        } catch (\Throwable $exception) {
            // Analytics must never change the checkout answer.
            $this->logger->error('ConversionWax Last Mile could not handle a guest checkout email.', [
                'exception' => $exception,
            ]);
        }

        return $result;
    }
}
