<?php

namespace App\Support\Pos;

use App\Models\PosStaff;

/**
 * What a POS staff account (cashier or POS admin without an org owner/admin role) may do.
 * Org owners/admins (managers) are never limited by this. The org admin switches features
 * on/off per staff in POS › Staff; `orders` (Kasir & pesanan) is always on.
 *
 * Enforced on the API by the `posPermission:<key>` middleware (routes/api/pos.php) and
 * mirrored in the POS menu (pos_access.permissions).
 */
final class PosPermissions
{
    /** Always granted: taking orders, payment, receipts, table bills, lookups needed for that. */
    public const BASE = 'orders';

    /** Owner/admin only, never grantable (staff management could raise its own rights). */
    public const MANAGER = 'manager';

    /**
     * key => [label, description, default for cashier, default for POS admin staff].
     * Order = display order in the admin UI.
     */
    public const CATALOG = [
        'orders' => ['Kasir & pesanan', 'Buat pesanan, terima pembayaran, cetak struk, tagihan meja. Selalu aktif.', true, true],
        'order_cancel' => ['Batalkan pesanan', 'Membatalkan pesanan yang belum dibayar.', true, true],
        'order_refund' => ['Refund pesanan', 'Mengembalikan uang pesanan yang sudah dibayar.', false, true],
        'tables' => ['Kelola meja & QR', 'Tambah, ubah, hapus meja dan buat ulang QR meja.', true, true],
        'products' => ['Kelola produk & kategori', 'Tambah, ubah, hapus menu, harga, stok, dan kategori.', false, true],
        'members' => ['Member', 'Lihat daftar member, tambah dan ubah data member.', true, true],
        'member_points' => ['Poin & data member', 'Sesuaikan poin, gabung member ganda, tanda kecurangan, export data member.', false, true],
        'loyalty' => ['Pengaturan loyalty', 'Ubah aturan poin dan hadiah.', false, true],
        'customer_hub' => ['Promo & reservasi', 'Kelola promo, area/meja reservasi, dan status reservasi.', false, true],
        'reports' => ['Laporan', 'Lihat dan export laporan penjualan outlet.', false, true],
        'cash_control' => ['Buka/tutup kas', 'Membuka dan menutup kas shift sendiri.', true, true],
        'outlet_settings' => ['Pengaturan pesanan outlet', 'Jam buka, status buka/tutup, pengaturan pesanan online.', false, true],
    ];

    /** Old keys stored before this catalogue existed. */
    private const LEGACY = ['transactions' => 'members'];

    /** @return array<string, bool> */
    public static function defaults(string $role): array
    {
        $index = self::normalizeRole($role) === 'admin' ? 3 : 2;

        return array_map(fn (array $def) => (bool) $def[$index], self::CATALOG);
    }

    /**
     * Full permission map for a role from stored (possibly partial / legacy) values.
     *
     * @return array<string, bool>
     */
    public static function normalize(string $role, ?array $stored): array
    {
        $result = self::defaults($role);
        foreach ((array) $stored as $key => $value) {
            $key = self::LEGACY[$key] ?? $key;
            if (array_key_exists($key, $result)) {
                $result[$key] = filter_var($value, FILTER_VALIDATE_BOOLEAN);
            }
        }
        $result[self::BASE] = true;

        return $result;
    }

    /** @return array<string, bool> */
    public static function forStaff(PosStaff $staff): array
    {
        return self::normalize((string) $staff->role, is_array($staff->permissions) ? $staff->permissions : null);
    }

    public static function allows(PosStaff $staff, string $key): bool
    {
        if ($key === self::MANAGER) {
            return false;
        }

        return self::forStaff($staff)[$key] ?? false;
    }

    /** Catalogue for the admin UI. @return list<array{key:string,label:string,description:string,locked:bool,default_cashier:bool,default_admin:bool}> */
    public static function catalog(): array
    {
        $items = [];
        foreach (self::CATALOG as $key => [$label, $description, $cashier, $admin]) {
            $items[] = ['key' => $key, 'label' => $label, 'description' => $description, 'locked' => $key === self::BASE,
                'default_cashier' => $cashier, 'default_admin' => $admin];
        }

        return $items;
    }

    private static function normalizeRole(string $role): string
    {
        return $role === 'owner' ? 'admin' : $role;
    }
}
