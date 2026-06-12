<?php

namespace Tests\Unit\Services\Hellom;

use App\Services\Hellom\XenditService;
use App\Services\Hellom\XenditSettingsService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class XenditServiceTest extends TestCase
{
    public function test_create_payment_session_normalizes_metadata_values_to_strings(): void
    {
        Http::fake([
            'https://api.xendit.co/sessions' => Http::response([
                'payment_session_id' => 'ps_test_123',
            ], 200),
        ]);

        $service = $this->makeService();

        $service->createPaymentSession([
            'reference_id' => 'ref_123',
            'amount' => 100000,
            'currency' => 'IDR',
            'metadata' => [
                'organization_id' => 99,
                'checkout_intent_id' => 12345,
                'is_manual' => false,
                'extra' => ['id' => 1, 'flag' => true],
                'nullable' => null,
            ],
        ]);

        Http::assertSent(function (Request $request): bool {
            if ($request->url() !== 'https://api.xendit.co/sessions') {
                return false;
            }

            $metadata = data_get($request->data(), 'metadata', []);

            return ($metadata['organization_id'] ?? null) === '99'
                && ($metadata['checkout_intent_id'] ?? null) === '12345'
                && ($metadata['is_manual'] ?? null) === 'false'
                && ($metadata['extra'] ?? null) === '{"id":1,"flag":true}'
                && ($metadata['nullable'] ?? null) === '';
        });
    }

    public function test_create_payout_normalizes_metadata_values_to_strings(): void
    {
        Http::fake([
            'https://api.xendit.co/v2/payouts' => Http::response([
                'id' => 'po_test_123',
                'status' => 'PENDING',
            ], 200),
        ]);

        $service = $this->makeService();

        $service->createPayout([
            'reference_id' => 'wd_test_123',
            'channel_code' => 'ID_BCA',
            'channel_properties' => [
                'account_number' => '1234567890',
                'account_holder_name' => 'Demo Owner',
            ],
            'amount' => 50000,
            'currency' => 'IDR',
            'metadata' => [
                'organization_id' => 77,
                'withdrawal_id' => 9001,
            ],
        ]);

        Http::assertSent(function (Request $request): bool {
            if ($request->url() !== 'https://api.xendit.co/v2/payouts') {
                return false;
            }

            $metadata = data_get($request->data(), 'metadata', []);

            return ($metadata['organization_id'] ?? null) === '77'
                && ($metadata['withdrawal_id'] ?? null) === '9001';
        });
    }

    private function makeService(): XenditService
    {
        $settings = new class extends XenditSettingsService {
            public function getConfig(): array
            {
                return [
                    'secret_key' => 'xnd_development_test_secret_key_123456',
                    'callback_token' => 'xnd_cb_test_token_123456',
                    'is_production' => false,
                    'mode' => 'sandbox',
                    'is_ready' => true,
                ];
            }
        };

        return new XenditService($settings);
    }
}
