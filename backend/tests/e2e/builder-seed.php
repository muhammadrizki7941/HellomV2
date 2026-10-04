<?php
// Hellom Page builder e2e: a throwaway seller (hellom_pos_test only) with an active Hellom Page
// entitlement. Writes storage/app/builder_seed.json; "cleanup" removes it and its uploads.
// Run from backend/: DB_DATABASE=hellom_pos_test php tests/e2e/builder-seed.php [cleanup]
require __DIR__ . '/../../vendor/autoload.php';
$app = require __DIR__ . '/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\{ApiToken, AppCatalog, Entitlement, Organization, User};
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

if (config('database.connections.mysql.database') !== 'hellom_pos_test') {
    fwrite(STDERR, "Refusing: not hellom_pos_test\n");
    exit(1);
}

$slug = 'e2e-builder';
if ($org = Organization::query()->where('slug', $slug)->first()) {
    $pageIds = DB::table('organization_landing_pages')->where('organization_id', $org->id)->pluck('id');
    DB::table('landing_page_versions')->whereIn('landing_page_id', $pageIds)->delete();
    DB::table('landing_blocks')->whereIn('landing_page_id', $pageIds)->delete();
    DB::table('organization_landing_pages')->where('organization_id', $org->id)->delete();
    DB::table('landing_stats_daily')->where('organization_id', $org->id)->delete();
    DB::table('file_assets')->where('organization_id', $org->id)->delete();
    $orderIds = DB::table('landing_page_orders')->where('organization_id', $org->id)->pluck('id');
    // Paid test orders (shipping/rental e2e) have Saldo Penjualan rows that block deleting the orders.
    foreach (['seller_balance_ledger', 'seller_withdrawals', 'landing_refunds', 'seller_balances'] as $table) {
        if (Illuminate\Support\Facades\Schema::hasTable($table) && Illuminate\Support\Facades\Schema::hasColumn($table, 'organization_id')) {
            DB::table($table)->where('organization_id', $org->id)->delete();
        }
    }
    DB::table('landing_order_items')->whereIn('order_id', $orderIds)->delete();
    DB::table('landing_page_orders')->whereIn('id', $orderIds)->delete();
    DB::table('landing_bookings')->where('organization_id', $org->id)->delete();
    DB::table('landing_products')->where('organization_id', $org->id)->delete();
    Storage::disk('public')->deleteDirectory('landing-builder/' . $org->id);
    $userIds = DB::table('organization_user')->where('organization_id', $org->id)->pluck('user_id');
    DB::table('organization_user')->where('organization_id', $org->id)->delete();
    DB::table('api_tokens')->whereIn('user_id', $userIds)->delete();
    DB::table('entitlements')->where('organization_id', $org->id)->delete();
    DB::table('audit_logs')->where('organization_id', $org->id)->delete();
    DB::table('users')->whereIn('id', $userIds)->delete();
    $org->delete();
}
// Super admin for the template settings (Fase 7.4) and the template layout it changed.
$adminIds = DB::table('users')->where('email', 'e2e-builder-admin@example.test')->pluck('id');
DB::table('api_tokens')->whereIn('user_id', $adminIds)->delete();
DB::table('audit_logs')->whereIn('user_id', $adminIds)->delete();
DB::table('users')->whereIn('id', $adminIds)->delete();
DB::table('system_settings')->where('key', 'landing_templates')->delete();
if (($argv[1] ?? '') === 'cleanup') {
    echo "cleaned\n";
    exit(0);
}

$org = Organization::query()->create(['name' => 'Toko E2E Builder', 'slug' => $slug, 'landing_username' => $slug, 'status' => 'active']);
$user = User::query()->create(['name' => 'Penjual Builder', 'email' => 'e2e-builder@example.test', 'password' => bcrypt(Str::random(20)), 'role' => 'admin', 'current_organization_id' => $org->id]);
$org->users()->attach($user->id, ['role' => 'owner']);
$appRow = AppCatalog::query()->firstOrCreate(['slug' => 'landing_builder'], ['name' => 'Hellom Page', 'is_active' => true]);
Entitlement::query()->create(['organization_id' => $org->id, 'app_id' => $appRow->id, 'status' => 'active', 'starts_at' => now()->subDay(), 'ends_at' => now()->addMonth()]);
$plain = Str::random(40);
ApiToken::query()->create(['user_id' => $user->id, 'name' => 'e2e', 'token_hash' => hash('sha256', $plain), 'expires_at' => now()->addHours(3)]);

$admin = User::query()->create(['name' => 'Super Admin E2E', 'email' => 'e2e-builder-admin@example.test', 'password' => bcrypt(Str::random(20)), 'role' => 'super_admin']);
$adminPlain = Str::random(40);
ApiToken::query()->create(['user_id' => $admin->id, 'name' => 'e2e', 'token_hash' => hash('sha256', $adminPlain), 'expires_at' => now()->addHours(3)]);

file_put_contents(storage_path('app/builder_seed.json'), json_encode([
    'token' => $plain, 'username' => $slug,
    'admin_token' => $adminPlain, 'admin_user' => ['id' => $admin->id, 'name' => $admin->name, 'email' => $admin->email, 'role' => 'super_admin'],
    'user' => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email, 'role' => $user->role],
]));
echo "seeded\n";
