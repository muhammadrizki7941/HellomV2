<?php

namespace Tests\Unit\Services;

use App\Services\Hellom\IpaymuService;
use App\Services\Hellom\IpaymuSettingsService;
use App\Services\Payments\Gateways\IpaymuGateway;
use App\Services\Payments\PaymentStatus;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Status codes per docs.ipaymu.com/en/docs/transaction/check-transaction. */
class IpaymuGatewayStatusTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public static function codes(): array
    {
        return [
            'pending' => [0, 'Pending', PaymentStatus::PENDING],
            'success' => [1, 'Berhasil', PaymentStatus::PAID],
            'cancelled' => [2, 'Batal', PaymentStatus::FAILED],
            'refund' => [3, 'Refund', PaymentStatus::REFUNDED],
            'error' => [4, 'Error', PaymentStatus::FAILED],
            'failed' => [5, 'Gagal', PaymentStatus::FAILED],
            'success unsettled' => [6, 'Berhasil - Unsettled', PaymentStatus::PAID],
            'escrow stays pending' => [7, 'Escrow', PaymentStatus::PENDING],
            'expired' => [-2, 'Expired', PaymentStatus::EXPIRED],
            'code beats text' => ['0', 'Berhasil', PaymentStatus::PENDING],
            'text only when no code' => [null, 'Berhasil', PaymentStatus::PAID],
        ];
    }

    #[DataProvider('codes')]
    public function test_maps_ipaymu_status_codes(int|string|null $code, string $text, string $expected): void
    {
        $api = Mockery::mock(IpaymuService::class);
        $api->shouldReceive('checkTransaction')->with('123')->andReturn(['Status' => 200, 'Data' => array_filter([
            'TransactionId' => 123, 'ReferenceId' => 'lps_1', 'Amount' => 50000, 'Fee' => 350,
            'Status' => $code, 'StatusDesc' => $text,
        ], fn ($v) => $v !== null)]);

        $gateway = new IpaymuGateway($api, Mockery::mock(IpaymuSettingsService::class));
        $status = $gateway->getStatus('lps_1', null, '123');

        $this->assertSame($expected, $status->state);
        $this->assertSame(50000, $status->amount);
    }
}
