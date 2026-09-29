<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Paid multi-page plan for Hellom Page (owner decision Q3 / after Fase 4). Created hidden:
 * the super admin sets the final price in Admin › App Management and makes it visible.
 * Slug prefix landing_ = sold for the landing_builder app; quota = max_landing_pages.
 */
return new class extends Migration
{
    private const SLUG = 'landing_pro_monthly';

    public function up(): void
    {
        if (DB::table('plans')->where('slug', self::SLUG)->exists()) {
            return;
        }
        DB::table('plans')->insert([
            'slug' => self::SLUG,
            'name' => 'Hellom Page Pro - Bulanan',
            'type' => 'subscription',
            'price' => 49000,
            'is_active' => true,
            'is_visible' => false,
            'description' => 'Lebih dari satu halaman toko: halaman khusus kelas, promo, atau produk.',
            'features' => json_encode(['Sampai 5 halaman toko', 'Semua fitur Hellom Page']),
            'billing_cycles' => json_encode(['monthly']),
            'duration_days' => 30,
            'max_outlets' => 1,
            'max_landing_pages' => 5,
            'sort_order' => 100,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        $id = DB::table('plans')->where('slug', self::SLUG)->value('id');
        if ($id && !DB::table('subscriptions')->where('plan_id', $id)->exists() && !DB::table('entitlements')->where('plan_id', $id)->exists()) {
            DB::table('plans')->where('id', $id)->delete();
        }
    }
};
