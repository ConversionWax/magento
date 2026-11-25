<?php

declare(strict_types=1);

namespace Cwax\Banner\Test\Unit\Plugin\Lastmile;

use Cwax\Banner\Model\Lastmile\IdentifyRecorder;
use Cwax\Banner\Plugin\Lastmile\GuestPaymentEmailPlugin;
use Magento\Checkout\Api\GuestPaymentInformationManagementInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class GuestPaymentEmailPluginTest extends TestCase
{
    private function plugin(IdentifyRecorder $recorder): GuestPaymentEmailPlugin
    {
        return new GuestPaymentEmailPlugin($recorder, $this->createStub(LoggerInterface::class));
    }

    public function testLumaSavingTheGuestEmailIdentifies(): void
    {
        // Luma checkout sends the guest email to set-payment-information before the order.
        $recorder = $this->createMock(IdentifyRecorder::class);
        $recorder->expects($this->once())->method('identifyFromEmail')->with(null, 'guest@example.com');

        $result = $this->plugin($recorder)->afterSavePaymentInformation(
            $this->createStub(GuestPaymentInformationManagementInterface::class),
            true,
            'masked-cart-id',
            'guest@example.com'
        );

        $this->assertTrue($result);
    }

    public function testARefusedSaveSendsNothing(): void
    {
        // The rate limiter answers false; the email was not saved.
        $recorder = $this->createMock(IdentifyRecorder::class);
        $recorder->expects($this->never())->method('identifyFromEmail');

        $result = $this->plugin($recorder)->afterSavePaymentInformation(
            $this->createStub(GuestPaymentInformationManagementInterface::class),
            false,
            'masked-cart-id',
            'guest@example.com'
        );

        $this->assertFalse($result);
    }

    public function testAFailureNeverChangesTheCheckoutAnswer(): void
    {
        $recorder = $this->createStub(IdentifyRecorder::class);
        $recorder->method('identifyFromEmail')->willThrowException(new \RuntimeException('down'));

        $result = $this->plugin($recorder)->afterSavePaymentInformation(
            $this->createStub(GuestPaymentInformationManagementInterface::class),
            true,
            'masked-cart-id',
            'guest@example.com'
        );

        $this->assertTrue($result);
    }
}
