<?php
// Super admin smoke test data (hellom_pos_test only): a super admin with an API token, a shop
// with a pending manual transfer, an invoice and a digital product purchase, so every admin
// page has something to show. Run: DB_DATABASE=hellom_pos_test php tests/e2e/admin-smoke-seed.php [cleanup]
require __DIR__ . '/../../vendor/autoload.php';
$app = require __DIR__ . '/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\{ApiToken, AppCatalog, CheckoutIntent, DigitalProduct, Organization, Plan, ProductPurchase, Subscription, User};
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

if (config('database.connections.mysql.database') !== 'hellom_pos_test') {
    fwrite(STDERR, "Refusing: not hellom_pos_test\n");
    exit(1);
}

$adminEmail = 'admin-smoke@example.test';
$ownerEmail = 'owner-smoke@example.test';
$orgSlug = 'toko-smoke-admin';

// ── cleanup (also before seeding, so the script can be re-run) ──
$userIds = User::query()->whereIn('email', [$adminEmail, $ownerEmail])->pluck('id');
$org = Organization::query()->where('slug', $orgSlug)->first();
DB::transaction(function () use ($userIds, $org) {
    if ($org) {
        DB::table('invoices')->where('organization_id', $org->id)->delete();
        DB::table('checkout_intents')->where('organization_id', $org->id)->delete();
        DB::table('subscriptions')->where('organization_id', $org->id)->delete();
        DB::table('audit_logs')->where('organization_id', $org->id)->delete();
        DB::table('organization_user')->where('organization_id', $org->id)->delete();
        $org->delete();
    }
    DB::table('product_purchases')->whereIn('user_id', $userIds)->delete();
    DB::table('digital_products')->where('slug', 'ebook-smoke-admin')->delete();
    DB::table('plans')->where('slug', 'smoke-admin-bulanan')->delete();
    DB::table('audit_logs')->whereIn('user_id', $userIds)->delete();
    DB::table('api_tokens')->whereIn('user_id', $userIds)->delete();
    User::query()->whereIn('id', $userIds)->delete();
});
if (($argv[1] ?? '') === 'cleanup') {
    echo "cleaned\n";
    exit(0);
}

$admin = User::query()->create(['name' => 'Admin Smoke', 'email' => $adminEmail, 'password' => bcrypt(Str::random(20)), 'role' => 'super_admin']);
$token = Str::random(40);
ApiToken::query()->create(['user_id' => $admin->id, 'name' => 'smoke', 'token_hash' => hash('sha256', $token)]);

$org = Organization::query()->create(['name' => 'Toko Smoke Admin', 'slug' => $orgSlug, 'status' => 'active']);
$owner = User::query()->create(['name' => 'Pemilik Smoke', 'email' => $ownerEmail, 'password' => bcrypt(Str::random(20)), 'role' => 'admin', 'current_organization_id' => $org->id]);
$org->users()->attach($owner->id, ['role' => 'owner']);

$app = AppCatalog::query()->firstOrCreate(['slug' => 'landing_builder'], ['name' => 'Hellom Page', 'is_active' => true]);
$plan = Plan::query()->create(['slug' => 'smoke-admin-bulanan', 'name' => 'Smoke Bulanan', 'type' => 'subscription', 'price' => 99000, 'is_active' => true, 'is_visible' => false]);
$subscription = Subscription::query()->create(['organization_id' => $org->id, 'app_id' => $app->id, 'plan_id' => $plan->id, 'status' => 'pending_payment', 'amount' => 99000, 'currency' => 'IDR', 'billing_cycle' => 'monthly']);
CheckoutIntent::query()->create(['organization_id' => $org->id, 'user_id' => $owner->id, 'app_id' => $app->id, 'plan_id' => $plan->id, 'subscription_id' => $subscription->id,
    'intent_token' => 'ci_smoke_' . Str::random(12), 'status' => 'manual_review', 'amount' => 99000, 'currency' => 'IDR', 'metadata' => ['manual_payment_method' => 'bank_transfer']]);
DB::table('invoices')->insert(['organization_id' => $org->id, 'subscription_id' => $subscription->id, 'invoice_number' => 'INV-SMOKE-' . Str::upper(Str::random(6)), 'status' => 'issued',
    'amount' => 99000, 'tax' => 0, 'total' => 99000, 'currency' => 'IDR', 'issued_at' => now(), 'created_at' => now(), 'updated_at' => now()]);

$product = DigitalProduct::query()->create(['slug' => 'ebook-smoke-admin', 'name' => 'Ebook Smoke', 'category' => 'ebook', 'type' => 'paid', 'price' => 50000, 'currency' => 'IDR', 'is_published' => false]);
ProductPurchase::query()->create(['user_id' => $owner->id, 'product_id' => $product->id, 'transaction_code' => 'PUR-SMOKE' . Str::upper(Str::random(6)), 'amount_paid' => 50000,
    'payment_method' => 'bank_transfer', 'payment_status' => 'pending', 'payment_gateway' => 'manual']);

file_put_contents(__DIR__ . '/../../storage/app/admin_smoke_seed.json', json_encode([
    'token' => $token,
    'user' => ['id' => $admin->id, 'name' => $admin->name, 'email' => $admin->email, 'role' => 'super_admin'],
    'organization_id' => $org->id,
], JSON_PRETTY_PRINT));
echo "seeded\n";
