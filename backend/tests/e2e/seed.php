<?php
// Fase 5 full journey (hellom_pos_test only): iPaymu in SANDBOX mode (pointed at the local mock
// via IPAYMU_SANDBOX_URL), a super admin, and cleanup of everything the journey creates.
// Run: DB_DATABASE=hellom_pos_test php tests/e2e/seed.php [cleanup]   (from backend/)
require __DIR__ . '/../../vendor/autoload.php';
$app = require __DIR__ . '/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\{ApiToken, Organization, User};
use App\Services\Hellom\IpaymuSettingsService;
use App\Services\Hellom\PaymentGatewaySettingsService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

if (config('database.connections.mysql.database') !== 'hellom_pos_test') {
    fwrite(STDERR, "Refusing: not hellom_pos_test\n");
    exit(1);
}

$sellerEmail = 'rina-e2e-full@example.test';
$adminEmail = 'admin-e2e-full@example.test';

// ── cleanup ──
$sellerIds = User::query()->whereIn('email', [$sellerEmail, $adminEmail])->pluck('id');
$orgIds = DB::table('organization_user')->whereIn('user_id', $sellerIds)->pluck('organization_id')->unique();
foreach ($orgIds as $orgId) {
    $orderIds = DB::table('landing_page_orders')->where('organization_id', $orgId)->pluck('id');
    DB::table('finance_journal_entries')->where('organization_id', $orgId)->delete(); // lines cascade
    DB::table('seller_balance_ledger')->where('organization_id', $orgId)->delete();
    DB::table('seller_balances')->where('organization_id', $orgId)->delete();
    DB::table('seller_withdrawals')->where('organization_id', $orgId)->delete();
    DB::table('landing_order_items')->whereIn('order_id', $orderIds)->delete();
    DB::table('landing_refunds')->where('organization_id', $orgId)->delete();
    DB::table('landing_page_orders')->whereIn('id', $orderIds)->delete();
    foreach (DB::table('landing_products')->where('organization_id', $orgId)->whereNotNull('file_path')->pluck('file_path') as $path) {
        Storage::disk('local')->delete($path);
    }
    foreach (['landing_coupons', 'landing_products', 'landing_blocks', 'landing_page_versions', 'landing_stats_daily', 'landing_tracking_settings',
        'landing_username_redirects', 'organization_landing_pages', 'landing_reports', 'organization_payout_profiles', 'entitlements', 'subscriptions'] as $table) {
        if (DB::getSchemaBuilder()->hasColumn($table, 'organization_id')) {
            DB::table($table)->where('organization_id', $orgId)->delete();
        }
    }
    DB::table('payment_events')->where('organization_id', $orgId)->delete();
    DB::table('organization_user')->where('organization_id', $orgId)->delete();
    DB::table('organizations')->where('id', $orgId)->delete();
}
DB::table('api_tokens')->whereIn('user_id', $sellerIds)->delete();
DB::table('users')->whereIn('id', $sellerIds)->delete();
if (($argv[1] ?? '') === 'cleanup') {
    echo "cleaned\n";
    exit(0);
}

// ── seed ──
app(IpaymuSettingsService::class)->saveConfig(['va' => '0000001234567890', 'api_key' => 'e2e-sandbox-key', 'callback_token' => 'e2e-callback-token',
    'is_production' => false, 'payment_methods' => ['va', 'qris']]);
app(PaymentGatewaySettingsService::class)->saveRuntimeConfig(['active_provider' => 'ipaymu', 'sale_commission_percent' => 5]);

$admin = User::query()->create(['name' => 'Admin E2E', 'email' => $adminEmail, 'password' => bcrypt(Str::random(20)), 'role' => 'super_admin']);
$admin->forceFill(['email_verified_at' => now()])->save();
$adminToken = Str::random(40);
ApiToken::query()->create(['user_id' => $admin->id, 'name' => 'e2e', 'token_hash' => hash('sha256', $adminToken)]);

file_put_contents(__DIR__ . '/../../storage/app/e2e_full_seed.json', json_encode([
    'seller_email' => $sellerEmail,
    'admin_token' => $adminToken,
    'admin_user' => ['id' => $admin->id, 'name' => $admin->name, 'email' => $admin->email, 'role' => 'super_admin'],
], JSON_PRETTY_PRINT));
echo "seeded\n";
