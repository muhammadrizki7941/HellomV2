<?php
// Live notifications e2e data (hellom_pos_test only): a super admin and a shop owner, each with an
// API token. `fire <n>` rings both bells the way the money flows do (Notifier: seller "Penjualan baru",
// super admin "Dana masuk" + a pending withdrawal) — through the real realtime server.
// Run: DB_DATABASE=hellom_pos_test REALTIME_SERVER_URL=… REALTIME_SERVER_SECRET=… php tests/e2e/notifications-seed.php [fire N|cleanup]
require __DIR__ . '/../../vendor/autoload.php';
$app = require __DIR__ . '/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\ApiToken;
use App\Models\Organization;
use App\Models\User;
use App\Services\Notifications\Notifier;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

if (config('database.connections.mysql.database') !== 'hellom_pos_test') {
    fwrite(STDERR, "Refusing: not hellom_pos_test\n");
    exit(1);
}

$adminEmail = 'admin-notif@example.test';
$ownerEmail = 'owner-notif@example.test';
$orgSlug = 'toko-notif-e2e';
$marker = 'Toko Notif E2E';
$mode = $argv[1] ?? 'seed';

if ($mode === 'fire') {
    $n = (int) ($argv[2] ?? 1);
    $org = Organization::query()->where('slug', $orgSlug)->firstOrFail();
    $notifier = app(Notifier::class);
    $amount = 75000 + $n * 1000;
    $notifier->organization((int) $org->id, 'sale', 'Penjualan baru · ' . Notifier::rupiah($amount), "Pesanan E2E-{$n} dibayar: Kelas Foto ({$marker}).",
        '/dashboard/apps/landing-builder?tab=pesanan', ['order_reference' => "E2E-{$n}"]);
    $notifier->admin('landing_sale', 'Dana masuk Hellom Page · ' . Notifier::rupiah($amount), "{$marker} menjual Kelas Foto (E2E-{$n}).",
        ['action_url' => '/admin/keuangan-penjual', 'email' => false]);
    $notifier->admin('withdrawal', 'Penarikan dana diajukan · Rp 50.000', "{$marker} mengajukan penarikan Rp 50.000 (E2E-{$n}).",
        ['action_url' => '/admin/keuangan-penjual', 'action_type' => 'review_withdrawal', 'action_status' => 'pending', 'email' => false]);
    echo "fired {$n}\n";
    exit(0);
}

// ── cleanup (also before seeding, so the script can be re-run) ──
$userIds = User::query()->whereIn('email', [$adminEmail, $ownerEmail])->pluck('id');
$org = Organization::query()->where('slug', $orgSlug)->first();
DB::transaction(function () use ($userIds, $org, $marker) {
    DB::table('owner_notifications')->where('message', 'like', "%{$marker}%")->delete();
    DB::table('consumer_notifications')->whereIn('user_id', $userIds)->delete();
    if ($org) {
        DB::table('organization_user')->where('organization_id', $org->id)->delete();
        $org->delete();
    }
    DB::table('api_tokens')->whereIn('user_id', $userIds)->delete();
    User::query()->whereIn('id', $userIds)->delete();
});
if ($mode === 'cleanup') {
    echo "cleaned\n";
    exit(0);
}

$admin = User::query()->create(['name' => 'Admin Notif', 'email' => $adminEmail, 'password' => bcrypt(Str::random(20)), 'role' => 'super_admin', 'email_verified_at' => now()]);
$adminToken = Str::random(40);
ApiToken::query()->create(['user_id' => $admin->id, 'name' => 'e2e', 'token_hash' => hash('sha256', $adminToken)]);

$org = Organization::query()->create(['name' => $marker, 'slug' => $orgSlug, 'status' => 'active']);
$owner = User::query()->create(['name' => 'Pemilik Notif', 'email' => $ownerEmail, 'password' => bcrypt(Str::random(20)), 'role' => 'admin',
    'current_organization_id' => $org->id, 'email_verified_at' => now()]);
$org->users()->attach($owner->id, ['role' => 'owner']);
$ownerToken = Str::random(40);
ApiToken::query()->create(['user_id' => $owner->id, 'name' => 'e2e', 'token_hash' => hash('sha256', $ownerToken)]);

file_put_contents(__DIR__ . '/../../storage/app/e2e_notifications.json', json_encode([
    'admin_token' => $adminToken,
    'owner_token' => $ownerToken,
], JSON_PRETTY_PRINT));
echo "seeded\n";
