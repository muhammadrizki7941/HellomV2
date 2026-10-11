<?php

namespace App\Support\Hpp;

/**
 * Starter data per business type so a beginner does not start from an empty screen. Prices are
 * examples (2026, Indonesia) — the first purchase or an edit replaces them.
 * ingredient: [name, kind, category, use_unit, buy_unit, buy_factor, buy_price, yield_pct]
 */
final class Templates
{
    public const BUSINESS_LABELS = [
        'fnb' => 'Makanan & minuman', 'retail' => 'Toko / retail', 'service' => 'Jasa',
        'production' => 'Produksi', 'digital' => 'Produk digital',
    ];

    private const INGREDIENTS = [
        'fnb' => [
            ['Biji kopi', 'ingredient', 'Minuman', 'g', 'kg', 1000, 180000, 100],
            ['Susu UHT', 'ingredient', 'Minuman', 'ml', 'liter', 1000, 20000, 100],
            ['Gula pasir', 'ingredient', 'Bumbu & gula', 'g', 'kg', 1000, 18000, 100],
            ['Gula aren cair', 'ingredient', 'Bumbu & gula', 'ml', 'liter', 1000, 45000, 100],
            ['Minyak goreng', 'ingredient', 'Dapur', 'ml', 'liter', 1000, 20000, 100],
            ['Telur ayam', 'ingredient', 'Dapur', 'pcs', 'tray', 30, 60000, 100],
            ['Tepung terigu', 'ingredient', 'Dapur', 'g', 'kg', 1000, 14000, 100],
            ['Beras', 'ingredient', 'Dapur', 'g', 'kg', 1000, 15000, 100],
            ['Daging ayam', 'ingredient', 'Protein', 'g', 'kg', 1000, 38000, 80],
            ['Es batu', 'ingredient', 'Minuman', 'porsi', 'kantong', 100, 10000, 100],
            ['Cup + tutup 16oz', 'packaging', 'Kemasan', 'pcs', 'pak', 50, 50000, 100],
            ['Sedotan', 'packaging', 'Kemasan', 'pcs', 'pak', 100, 20000, 100],
            ['Paper bowl / box', 'packaging', 'Kemasan', 'pcs', 'pak', 50, 75000, 100],
            ['Kantong plastik', 'packaging', 'Kemasan', 'pcs', 'pak', 100, 15000, 100],
        ],
        'retail' => [
            ['Barang dagangan (contoh)', 'ingredient', 'Barang', 'pcs', 'lusin', 12, 120000, 100],
            ['Kardus pengiriman', 'packaging', 'Kemasan', 'pcs', 'pak', 25, 50000, 100],
            ['Bubble wrap', 'packaging', 'Kemasan', 'cm', 'meter', 100, 4000, 100],
            ['Lakban', 'packaging', 'Kemasan', 'pcs', 'roll', 1, 12000, 100],
            ['Kantong belanja', 'packaging', 'Kemasan', 'pcs', 'pak', 100, 25000, 100],
        ],
        'service' => [
            ['Shampo', 'ingredient', 'Bahan habis pakai', 'ml', 'liter', 1000, 60000, 100],
            ['Handuk sekali pakai', 'ingredient', 'Bahan habis pakai', 'pcs', 'pak', 50, 50000, 100],
            ['Sarung tangan', 'ingredient', 'Bahan habis pakai', 'pcs', 'dus', 100, 45000, 100],
        ],
        'production' => [
            ['Tepung terigu', 'ingredient', 'Bahan baku', 'g', 'kg', 1000, 14000, 100],
            ['Gula pasir', 'ingredient', 'Bahan baku', 'g', 'kg', 1000, 18000, 100],
            ['Mentega', 'ingredient', 'Bahan baku', 'g', 'kg', 1000, 60000, 100],
            ['Telur ayam', 'ingredient', 'Bahan baku', 'pcs', 'tray', 30, 60000, 100],
            ['Label stiker', 'packaging', 'Kemasan', 'lembar', 'rim', 500, 75000, 100],
            ['Plastik kemasan', 'packaging', 'Kemasan', 'pcs', 'pak', 100, 20000, 100],
        ],
        'digital' => [
            ['Lisensi aset / font', 'ingredient', 'Aset', 'pcs', 'pcs', 1, 0, 100],
        ],
    ];

    /** overhead: [name, category, amount per month] */
    private const OVERHEADS = [
        'fnb' => [['Sewa tempat', 'rent', 2500000], ['Listrik', 'utility', 700000], ['Air', 'utility', 150000], ['Gas', 'utility', 250000], ['Gaji karyawan', 'salary', 2000000], ['Internet', 'utility', 200000]],
        'retail' => [['Sewa tempat', 'rent', 1500000], ['Listrik', 'utility', 300000], ['Gaji karyawan', 'salary', 2000000], ['Internet', 'utility', 200000]],
        'service' => [['Sewa tempat', 'rent', 2000000], ['Listrik', 'utility', 500000], ['Penyusutan alat', 'depreciation', 300000]],
        'production' => [['Sewa tempat produksi', 'rent', 2000000], ['Listrik', 'utility', 600000], ['Gas', 'utility', 300000], ['Penyusutan mesin', 'depreciation', 400000]],
        'digital' => [['Langganan tools desain', 'subscription', 150000], ['Hosting & domain', 'subscription', 100000], ['Internet', 'utility', 300000]],
    ];

    /** channel: [name, kind, fee %, flat fee] */
    public const CHANNELS = [
        ['Kasir (tunai)', 'cash', 0, 0],
        ['QRIS', 'qris', 0.7, 0],
        ['Hellom Page (online)', 'hellom', 5, 0],
        ['GoFood', 'gofood', 20, 0],
        ['GrabFood', 'grabfood', 20, 0],
        ['ShopeeFood', 'shopeefood', 20, 0],
    ];

    /** @return list<array<string, mixed>> */
    public static function ingredients(string $businessType): array
    {
        return array_map(fn (array $r) => [
            'name' => $r[0], 'kind' => $r[1], 'category' => $r[2], 'use_unit' => $r[3], 'buy_unit' => $r[4],
            'buy_factor' => $r[5], 'last_buy_price' => $r[6], 'cost_per_unit' => $r[5] > 0 ? round($r[6] / $r[5], 4) : 0, 'yield_pct' => $r[7],
        ], self::INGREDIENTS[$businessType] ?? self::INGREDIENTS['fnb']);
    }

    /** @return list<array{name: string, category: string, amount: int}> */
    public static function overheads(string $businessType): array
    {
        return array_map(fn (array $r) => ['name' => $r[0], 'category' => $r[1], 'amount' => $r[2]], self::OVERHEADS[$businessType] ?? self::OVERHEADS['fnb']);
    }
}
