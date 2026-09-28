<?php

namespace App\Services\Pos\Verification;

use App\Models\PosMember;
use App\Services\Pos\PricingException;
use Illuminate\Support\Str;

/** The cashier/customer re-types the member's name; compared case- and space-insensitively. */
final class NameConfirmationVerifier implements PointRedemptionVerifier
{
    public function verify(PosMember $member, array $input): void
    {
        $typed = self::canonical((string) ($input['confirm_member_name'] ?? ''));
        if ($typed === '' || $typed !== self::canonical((string) $member->name)) {
            throw new PricingException('Nama member tidak cocok. Minta pelanggan menyebutkan nama yang terdaftar.', [], 'MEMBER_NAME_MISMATCH');
        }
    }

    public function requirement(): array
    {
        return ['type' => 'name'];
    }

    private static function canonical(string $value): string
    {
        return (string) Str::of($value)->lower()->squish();
    }
}
