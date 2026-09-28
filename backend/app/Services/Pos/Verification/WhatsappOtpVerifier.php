<?php

namespace App\Services\Pos\Verification;

use App\Models\PosMember;
use App\Services\Pos\PricingException;

/**
 * Placeholder for OTP over WhatsApp. Not bound by default: sending an OTP needs a
 * WhatsApp provider, which is not set up yet. To enable it, implement send()/check()
 * against the provider and bind this class to PointRedemptionVerifier.
 */
final class WhatsappOtpVerifier implements PointRedemptionVerifier
{
    public function verify(PosMember $member, array $input): void
    {
        throw new PricingException('Verifikasi OTP WhatsApp belum tersedia.', [], 'OTP_NOT_AVAILABLE', 501);
    }

    public function requirement(): array
    {
        return ['type' => 'otp', 'channel' => 'whatsapp'];
    }
}
