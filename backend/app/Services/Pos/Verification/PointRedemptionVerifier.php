<?php

namespace App\Services\Pos\Verification;

use App\Models\PosMember;
use App\Services\Pos\PricingException;

/**
 * Proof that the person redeeming points really is the member. The default binding is
 * NameConfirmationVerifier; a WhatsApp OTP verifier can replace it in AppServiceProvider
 * without touching LoyaltyService or the controllers.
 */
interface PointRedemptionVerifier
{
    /**
     * @param array<string,mixed> $input fields from the request (e.g. confirm_member_name, otp_code)
     * @throws PricingException when verification fails
     */
    public function verify(PosMember $member, array $input): void;

    /** What the UI must ask for, e.g. ['type' => 'name'] or ['type' => 'otp', 'channel' => 'whatsapp']. */
    public function requirement(): array;
}
