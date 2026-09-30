<?php
// POS cashier permissions browser check (hellom_pos_test only): an org with one outlet, a product,
// an owner and a cashier (linked PosStaff, default permissions). `cleanup` removes it.
// Run from backend/: DB_DATABASE=hellom_pos_test php tests/e2e/pos-cashier-seed.php [cleanup]
require __DIR__ . '/../../vendor/autoload.php';
$app = require __DIR__ . '/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\{ApiToken, AppCatalog, Category, Entitlement, Organization, Outlet, PosStaff, Product, User};
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

if (config('database.connections.mysql.database') !== 'hellom_pos_test') {
    fwrite(STDERR, "Refusing: not hellom_pos_test\n");
    exit(1);
}

$slug = 'resto-e2e-kasir';
if ($org = Organization::query()->where('slug', $slug)->first()) {
    $userIds = DB::table('organization_user')->where('organization_id', $org->id)->pluck('user_id');
    $tenants = Outlet::query()->where('organization_id', $org->id)->pluck('tenant_slug');
    DB::table('order_items')->whereIn('order_id', DB::table('orders')->whereIn('tenant_id', $tenants)->pluck('id'))->delete();
    DB::table('orders')->whereIn('tenant_id', $tenants)->delete();
    DB::table('products')->whereIn('tenant_id', $tenants)->delete();
    DB::table('categories')->whereIn('tenant_id', $tenants)->delete();
    DB::table('pos_staff')->where('organization_id', $org->id)->delete();
    DB::table('outlets')->where('organization_id', $org->id)->delete();
    DB::table('entitlements')->where('organization_id', $org->id)->delete();
    DB::table('organization_user')->where('organization_id', $org->id)->delete();
    DB::table('api_tokens')->whereIn('user_id', $userIds)->delete();
    DB::table('users')->whereIn('id', $userIds)->delete();
    DB::table('organizations')->where('id', $org->id)->delete();
}
if (($argv[1] ?? '') === 'cleanup') {
    echo "cleaned\n";
    exit(0);
}

$org = Organization::query()->create(['name' => 'Resto E2E Kasir', 'slug' => $slug, 'status' => 'active', 'pos_tenant_slug' => 't-e2e-kasir']);
$outlet = Outlet::query()->create(['organization_id' => $org->id, 'name' => 'Outlet Pusat', 'slug' => 'outlet-e2e-kasir', 'tenant_slug' => 't-e2e-kasir',
    'is_primary' => true, 'is_active' => true, 'settings' => []]);
$category = Category::withoutGlobalScope('tenant')->create(['tenant_id' => $outlet->tenant_slug, 'outlet_id' => $outlet->id, 'slug' => 'makanan',
    'name' => 'Makanan', 'is_active' => true, 'sort_order' => 1]);
Product::withoutGlobalScope('tenant')->create(['tenant_id' => $outlet->tenant_slug, 'outlet_id' => $outlet->id, 'category_id' => $category->id,
    'name' => 'Nasi Goreng', 'slug' => 'nasi-goreng-e2e', 'price' => 25000, 'is_available' => true, 'track_stock' => false, 'stock' => null]);
$pos = AppCatalog::query()->firstOrCreate(['slug' => 'pos'], ['name' => 'POS', 'is_active' => true]);
Entitlement::query()->create(['organization_id' => $org->id, 'app_id' => $pos->id, 'status' => 'active', 'starts_at' => now()->subDay(), 'ends_at' => now()->addMonth()]);

$token = function (string $name, string $pivot, string $role) use ($org): array {
    $user = User::query()->create(['name' => $name, 'email' => Str::slug($name) . '-e2e@example.test', 'password' => bcrypt(Str::random(20)),
        'role' => $role, 'current_organization_id' => $org->id]);
    $user->forceFill(['email_verified_at' => now()])->save();
    $org->users()->attach($user->id, ['role' => $pivot]);
    $plain = Str::random(40);
    ApiToken::query()->create(['user_id' => $user->id, 'name' => 'e2e', 'token_hash' => hash('sha256', $plain)]);

    return [$user, $plain];
};
[$owner, $ownerToken] = $token('Pemilik Resto', 'owner', 'admin');
[$cashier, $cashierToken] = $token('Kasir Sinta', 'cashier', 'cashier');
$staff = PosStaff::query()->create(['organization_id' => $org->id, 'outlet_id' => $outlet->id, 'tenant_id' => $outlet->tenant_slug,
    'linked_user_id' => $cashier->id, 'name' => 'Kasir Sinta', 'role' => 'cashier', 'employment_status' => 'active']);

file_put_contents(__DIR__ . '/../../storage/app/e2e_pos_cashier.json', json_encode([
    'owner_token' => $ownerToken, 'cashier_token' => $cashierToken, 'staff_id' => $staff->id, 'outlet_id' => $outlet->id,
], JSON_PRETTY_PRINT));
echo "seeded\n";
