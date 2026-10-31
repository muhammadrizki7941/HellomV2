<?php

namespace App\Services\Payments;

use Illuminate\Http\Request;

final class IpaymuCallbackSignature
{
    public function valid(Request $request, string $merchantVa): bool
    {
        $signature = trim((string) $request->header('X-Signature', ''));
        if ($signature === '' || $merchantVa === '') {
            return false;
        }

        return hash_equals($this->sign($request->all(), $merchantVa), $signature);
    }

    /** @param array<string,mixed> $payload */
    public function sign(array $payload, string $merchantVa): string
    {
        foreach (['trx_id', 'status_code', 'transaction_status_code', 'paid_off'] as $key) {
            if (isset($payload[$key]) && is_numeric($payload[$key])) {
                $payload[$key] = (int) $payload[$key];
            }
        }

        if (array_key_exists('is_escrow', $payload)) {
            $payload['is_escrow'] = filter_var($payload['is_escrow'], FILTER_VALIDATE_BOOLEAN);
        }

        if (!array_key_exists('additional_info', $payload)) {
            $payload['additional_info'] = [];
        } elseif ($payload['additional_info'] === '[]') {
            $payload['additional_info'] = [];
        }

        ksort($payload, SORT_STRING);
        $json = json_encode($payload);
        if (!is_string($json)) {
            return '';
        }

        return hash_hmac('sha256', $json, $merchantVa);
    }
}
