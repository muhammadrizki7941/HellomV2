<?php

namespace Tests\Pos;

use App\Jobs\SendPlatformMail;
use App\Models\ApiToken;
use App\Models\AppCatalog;
use App\Models\Entitlement;
use App\Models\Organization;
use App\Models\Outlet;
use App\Models\PosActivityLog;
use App\Models\PosStaff;
use App\Models\User;
use App\Services\Pos\Audit\ActivityLogger;
use App\Services\Pos\Audit\AnomalyReport;
use App\Support\Pos\ActivityActions;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

/**
 * POS anti-fraud trail: owner PIN (create, unlock, lockout, email reset to the primary owner),
 * void/refund need the owner PIN and are logged with approver + device + session, receipts count
 * reprints, the log is append-only and hash-chained, fraud alerts flag outliers.
 */
class PosAuditTest extends PosTestCase
{
    use DatabaseTransactions;

    private const API = '/api/v1/hellom/pos';

    /** @return array{0: array<string,string>, 1: User} */
    private function login(Organization $org, string $pivotRole, ?Outlet $outlet = null, string $email = null): array
    {
        $user = User::query()->create([
            'name' => ucfirst($pivotRole) . ' ' . Str::random(4), 'email' => $email ?? Str::lower(Str::random(10)) . '@example.test', 'password' => bcrypt('secret-password'),
            'role' => $pivotRole === 'cashier' ? 'cashier' : 'tenant_admin', 'current_organization_id' => $org->id,
        ]);
        $org->users()->attach($user->id, ['role' => $pivotRole]);
        $app = AppCatalog::query()->firstOrCreate(['slug' => 'pos'], ['name' => 'POS', 'is_active' => true]);
        Entitlement::query()->firstOrCreate(['organization_id' => $org->id, 'app_id' => $app->id], ['status' => 'active', 'starts_at' => now()->subDay(), 'ends_at' => now()->addMonth()]);
        if ($pivotRole === 'cashier' && $outlet) {
            PosStaff::query()->create(['organization_id' => $org->id, 'outlet_id' => $outlet->id, 'tenant_id' => $outlet->tenant_slug, 'linked_user_id' => $user->id,
                'name' => $user->name, 'role' => 'cashier', 'employment_status' => 'active']);
        }
        $plain = Str::random(40);
        ApiToken::query()->create(['user_id' => $user->id, 'name' => 'test', 'token_hash' => hash('sha256', $plain)]);
        $headers = ['Authorization' => "Bearer {$plain}", 'Accept' => 'application/json', 'X-Device-Id' => 'kasir-depan-01'];
        if ($outlet) {
            $headers['X-Outlet-Id'] = (string) $outlet->id;
        }

        return [$headers, $user];
    }

    private function order(array $headers, Outlet $outlet, bool $paid = false): array
    {
        $product = $this->makeProduct($outlet, 25000);
        $order = $this->withHeaders($headers)->postJson(self::API . '/orders', ['items' => [['product_id' => $product->id, 'quantity' => 2]]])->assertCreated()->json('data.order');
        if ($paid) {
            $this->withHeaders($headers)->postJson(self::API . "/orders/{$order['id']}/payment", ['payment_method' => 'cash', 'payment_amount' => 100000])->assertOk();
        }

        return $order;
    }

    public function test_owner_creates_pin_on_first_visit_and_only_the_owner_opens_the_log(): void
    {
        ['org' => $org, 'outlet' => $outlet] = $this->makeOrganization('A');
        [$owner] = $this->login($org, 'owner', $outlet);
        [$admin] = $this->login($org, 'admin', $outlet);
        [$cashier] = $this->login($org, 'cashier', $outlet);

        $this->withHeaders($owner)->getJson(self::API . '/owner-pin')->assertOk()
            ->assertJsonPath('data.is_owner', true)->assertJsonPath('data.pin_set', false);
        $this->withHeaders($cashier)->getJson(self::API . '/owner-pin')->assertForbidden();
        $this->withHeaders($admin)->postJson(self::API . '/owner-pin', ['pin' => '583920', 'pin_confirmation' => '583920'])
            ->assertForbidden()->assertJsonPath('error.code', 'OWNER_ONLY');

        $this->withHeaders($owner)->postJson(self::API . '/owner-pin', ['pin' => '123456', 'pin_confirmation' => '123456'])
            ->assertStatus(422)->assertJsonPath('error.code', 'PIN_WEAK');
        $this->withHeaders($owner)->postJson(self::API . '/owner-pin', ['pin' => '583920', 'pin_confirmation' => '583921'])
            ->assertStatus(422)->assertJsonPath('errors.pin.0', 'Konfirmasi PIN belum sama.');
        $this->withHeaders($owner)->postJson(self::API . '/owner-pin', ['pin' => '583920', 'pin_confirmation' => '583920'])->assertCreated()
            ->assertJsonPath('data.pin_set', true);
        $this->withHeaders($owner)->postJson(self::API . '/owner-pin', ['pin' => '583920', 'pin_confirmation' => '583920'])->assertStatus(409);
        $this->assertNotSame('583920', DB::table('pos_owner_pins')->where('organization_id', $org->id)->value('pin_hash'));

        // The log needs a fresh PIN unlock.
        $this->withHeaders($owner)->getJson(self::API . '/activity')->assertStatus(401)->assertJsonPath('error.code', 'PIN_UNLOCK_REQUIRED');
        $this->withHeaders($owner)->postJson(self::API . '/owner-pin/unlock', ['pin' => '111222'])->assertStatus(422)
            ->assertJsonPath('error.code', 'PIN_INVALID')->assertJsonPath('data.attempts_left', 4);
        $this->withHeaders($admin)->postJson(self::API . '/owner-pin/unlock', ['pin' => '583920'])->assertForbidden();
        $token = $this->withHeaders($owner)->postJson(self::API . '/owner-pin/unlock', ['pin' => '583920'])->assertOk()->json('data.token');

        $this->withHeaders($admin + ['X-Owner-Pin-Token' => $token])->getJson(self::API . '/activity')->assertForbidden();
        $logs = $this->withHeaders($owner + ['X-Owner-Pin-Token' => $token])->getJson(self::API . '/activity')->assertOk()->json('data.logs');
        $this->assertSame([ActivityActions::AUDIT_UNLOCKED, ActivityActions::PIN_FAILED, ActivityActions::PIN_SET], array_column($logs, 'action'));
        $this->assertSame('owner', $logs[0]['actor']['role']);
        $this->assertMatchesRegularExpression('/T\d{2}:\d{2}:\d{2}\.\d{3}/', $logs[0]['occurred_at']);
        $this->assertSame('kasir-depan-01', $logs[0]['device_id']);
        $this->assertStringStartsWith('token:', (string) $logs[0]['session_ref']);
    }

    public function test_void_and_refund_need_the_owner_pin_and_are_logged_with_the_approver(): void
    {
        ['org' => $org, 'outlet' => $outlet] = $this->makeOrganization('B');
        [$owner, $ownerUser] = $this->login($org, 'owner', $outlet);
        [$cashier] = $this->login($org, 'cashier', $outlet);
        $order = $this->order($cashier, $outlet);

        // No PIN made yet → cashier is told to ask the owner.
        $this->withHeaders($cashier)->postJson(self::API . "/orders/{$order['id']}/cancel", ['reason' => 'Pelanggan batal', 'approval_pin' => '583920'])
            ->assertStatus(409)->assertJsonPath('error.code', 'PIN_NOT_SET');
        $pin = $this->ownerPin($org, $ownerUser->id, '583920');

        $this->withHeaders($cashier)->postJson(self::API . "/orders/{$order['id']}/cancel", ['reason' => 'Pelanggan batal'])
            ->assertStatus(422)->assertJsonPath('errors.approval_pin.0', 'Minta pemilik toko memasukkan PIN untuk menyetujui.');
        $this->withHeaders($cashier)->postJson(self::API . "/orders/{$order['id']}/cancel", ['reason' => 'Pelanggan batal', 'approval_pin' => '000111'])
            ->assertStatus(422)->assertJsonPath('error.code', 'PIN_INVALID');
        // The status endpoint cannot skip the PIN.
        $this->withHeaders($cashier)->patchJson(self::API . "/orders/{$order['id']}/status", ['status' => 'cancelled', 'reason' => 'x'])->assertStatus(422);
        $this->assertSame('new', DB::table('orders')->where('id', $order['id'])->value('status'));

        $this->withHeaders($cashier)->postJson(self::API . "/orders/{$order['id']}/cancel", ['reason' => 'Pelanggan batal', 'approval_pin' => $pin])
            ->assertOk()->assertJsonPath('data.order.status', 'cancelled');

        $void = PosActivityLog::query()->where('organization_id', $org->id)->where('action', ActivityActions::ORDER_VOID)->sole();
        $this->assertSame('critical', $void->severity);
        $this->assertSame('cashier', $void->actor_role);
        $this->assertSame($ownerUser->id, (int) $void->approver_user_id);
        $this->assertSame('owner_pin', $void->approval_method);
        $this->assertSame('Pelanggan batal', $void->reason);
        $this->assertSame('new', $void->before_state['status']);
        $this->assertSame(2, $void->before_state['items'][0]['qty']);
        $this->assertSame('cancelled', $void->after_state['status']);
        $this->assertSame(1, PosActivityLog::query()->where('organization_id', $org->id)->where('action', ActivityActions::PIN_FAILED)->count());

        // Refund (owner account here) also needs the PIN.
        $paid = $this->order($owner, $outlet, true);
        $this->withHeaders($owner)->postJson(self::API . "/orders/{$paid['id']}/refund", ['reason' => 'Salah input'])->assertStatus(422);
        $this->withHeaders($owner)->postJson(self::API . "/orders/{$paid['id']}/refund", ['reason' => 'Salah input', 'approval_pin' => $pin])->assertOk();
        $refund = PosActivityLog::query()->where('organization_id', $org->id)->where('action', ActivityActions::ORDER_REFUND)->sole();
        $this->assertSame('paid', $refund->before_state['payment_status']);
        $this->assertSame('refunded', $refund->after_state['payment_status']);
        $this->assertSame(1, PosActivityLog::query()->where('organization_id', $org->id)->where('action', ActivityActions::PAYMENT_RECORDED)->count());
    }

    public function test_five_wrong_pins_lock_and_tell_the_owner(): void
    {
        Bus::fake([SendPlatformMail::class]);
        ['org' => $org, 'outlet' => $outlet] = $this->makeOrganization('C');
        [, $ownerUser] = $this->login($org, 'owner', $outlet, 'pemilik-c@example.test');
        [$cashier] = $this->login($org, 'cashier', $outlet);
        $pin = $this->ownerPin($org, $ownerUser->id, '583920');
        $order = $this->order($cashier, $outlet);

        foreach ([4, 3, 2, 1] as $left) {
            $this->withHeaders($cashier)->postJson(self::API . "/orders/{$order['id']}/cancel", ['reason' => 'x', 'approval_pin' => '999000'])
                ->assertStatus(422)->assertJsonPath('data.attempts_left', $left);
        }
        $this->withHeaders($cashier)->postJson(self::API . "/orders/{$order['id']}/cancel", ['reason' => 'x', 'approval_pin' => '999000'])
            ->assertStatus(423)->assertJsonPath('error.code', 'PIN_LOCKED');
        $this->withHeaders($cashier)->postJson(self::API . "/orders/{$order['id']}/cancel", ['reason' => 'x', 'approval_pin' => $pin])->assertStatus(423);

        $this->assertSame(1, PosActivityLog::query()->where('organization_id', $org->id)->where('action', ActivityActions::PIN_LOCKED)->count());
        Bus::assertDispatched(SendPlatformMail::class, fn (SendPlatformMail $job) => $job->to === ['pemilik-c@example.test'] && str_contains($job->subject, 'terkunci'));
    }

    public function test_reset_link_goes_only_to_the_primary_owner(): void
    {
        Bus::fake([SendPlatformMail::class]);
        ['org' => $org, 'outlet' => $outlet] = $this->makeOrganization('D');
        [, $primary] = $this->login($org, 'owner', $outlet, 'utama-d@example.test');
        [$second] = $this->login($org, 'owner', $outlet, 'kedua-d@example.test');
        $this->ownerPin($org, $primary->id, '583920');

        $this->withHeaders($second)->postJson(self::API . '/owner-pin/forgot')->assertOk()->assertJsonPath('data.sent_to', 'ut•••••@example.test');
        $url = null;
        Bus::assertDispatched(SendPlatformMail::class, function (SendPlatformMail $job) use (&$url) {
            $url = $job->payload['cta_url'] ?? null;

            return $job->to === ['utama-d@example.test'];
        });
        Bus::assertNotDispatched(SendPlatformMail::class, fn (SendPlatformMail $job) => $job->to === ['kedua-d@example.test']);
        parse_str((string) parse_url((string) $url, PHP_URL_QUERY), $query);
        $token = (string) $query['token'];

        $this->getJson("/api/v1/hellom/public/pos-pin-reset/{$token}")->assertOk()->assertJsonPath('data.organization', $org->name);
        $this->postJson("/api/v1/hellom/public/pos-pin-reset/{$token}", ['pin' => '777777', 'pin_confirmation' => '777777'])->assertStatus(422)->assertJsonPath('error.code', 'PIN_WEAK');
        $this->postJson("/api/v1/hellom/public/pos-pin-reset/{$token}", ['pin' => '260417', 'pin_confirmation' => '260417'])->assertOk();
        $this->postJson("/api/v1/hellom/public/pos-pin-reset/{$token}", ['pin' => '260418', 'pin_confirmation' => '260418'])->assertStatus(410);

        $this->withHeaders($second)->postJson(self::API . '/owner-pin/unlock', ['pin' => '583920'])->assertStatus(422);
        $this->withHeaders($second)->postJson(self::API . '/owner-pin/unlock', ['pin' => '260417'])->assertOk();
        $reset = PosActivityLog::query()->where('organization_id', $org->id)->where('action', ActivityActions::PIN_RESET)->sole();
        $this->assertSame($primary->id, (int) $reset->actor_user_id);
    }

    public function test_receipts_count_reprints_and_cash_close_is_logged(): void
    {
        ['org' => $org, 'outlet' => $outlet] = $this->makeOrganization('E');
        [$cashier] = $this->login($org, 'cashier', $outlet);
        $staffId = (int) PosStaff::query()->where('organization_id', $org->id)->value('id');
        $this->withHeaders($cashier)->postJson(self::API . "/staff/{$staffId}/cash/open", ['opening_cash' => 100000])->assertCreated();
        $order = $this->order($cashier, $outlet);

        $this->withHeaders($cashier)->postJson(self::API . "/orders/{$order['id']}/receipt/printed")->assertOk()
            ->assertJsonPath('data.print_no', 1)->assertJsonPath('data.kind', 'draft');
        $this->withHeaders($cashier)->postJson(self::API . "/orders/{$order['id']}/payment", ['payment_method' => 'cash', 'payment_amount' => 100000])->assertOk();
        $this->withHeaders($cashier)->postJson(self::API . "/orders/{$order['id']}/receipt/printed")->assertOk()
            ->assertJsonPath('data.print_no', 2)->assertJsonPath('data.kind', 'final');
        $this->assertSame('warning', PosActivityLog::query()->where('organization_id', $org->id)->where('action', ActivityActions::RECEIPT_REPRINTED)->sole()->severity);

        $this->withHeaders($cashier)->postJson(self::API . "/staff/{$staffId}/cash/close", ['closing_cash' => 100000])->assertOk()
            ->assertJsonPath('data.cash_log.difference_cash', null);
        $close = PosActivityLog::query()->where('organization_id', $org->id)->where('action', ActivityActions::CASH_CLOSED)->sole();
        $this->assertSame(-(int) $order['final_amount'], $close->after_state['difference_cash']);
        $this->assertTrue($close->after_state['blind']);
        $this->assertSame('critical', $close->severity); // short ≥ Rp50.000
    }

    public function test_log_is_append_only_and_the_chain_detects_tampering(): void
    {
        ['org' => $org] = $this->makeOrganization('F');
        $logger = app(ActivityLogger::class);
        $first = $logger->record($org->id, ActivityActions::PRODUCT_UPDATED, ['target_type' => 'product', 'target_id' => 1, 'before' => ['price' => 10000, 'name' => 'Kopi'], 'after' => ['price' => 1, 'name' => 'Kopi']]);
        $logger->record($org->id, ActivityActions::PRODUCT_DELETED, ['target_type' => 'product', 'target_id' => 1]);
        $this->assertSame(['ok' => true, 'checked' => 2, 'broken_id' => null, 'problem' => null], $logger->verify($org->id));
        $this->assertSame($first->hash, PosActivityLog::query()->find($first->id)->hash);

        try {
            $first->forceFill(['reason' => 'diubah'])->save();
            $this->fail('model update allowed');
        } catch (LogicException) {
        }
        try {
            $first->delete();
            $this->fail('model delete allowed');
        } catch (LogicException) {
        }
        foreach (['update', 'delete'] as $op) {
            try {
                $op === 'update' ? DB::table('pos_activity_logs')->where('id', $first->id)->update(['reason' => 'x']) : DB::table('pos_activity_logs')->where('id', $first->id)->delete();
                $this->fail("raw {$op} allowed");
            } catch (QueryException $e) {
                $this->assertStringContainsString('append-only', $e->getMessage());
            }
        }

        // A row slipped in without the chain is caught.
        $row = PosActivityLog::query()->find($first->id)->getAttributes();
        unset($row['id']);
        DB::table('pos_activity_logs')->insert(['action' => ActivityActions::ORDER_VOID, 'hash' => str_repeat('a', 64)] + $row);
        $result = $logger->verify($org->id);
        $this->assertFalse($result['ok']);
    }

    public function test_fraud_alerts_flag_a_cashier_far_above_the_shop_average(): void
    {
        ['org' => $org] = $this->makeOrganization('G');
        $honest = User::query()->create(['name' => 'Kasir Jujur', 'email' => Str::random(8) . '@example.test', 'password' => bcrypt('x')]);
        $risky = User::query()->create(['name' => 'Kasir Risiko', 'email' => Str::random(8) . '@example.test', 'password' => bcrypt('x')]);
        $logger = app(ActivityLogger::class);
        $log = fn (User $u, string $action, array $after = []) => $logger->record($org->id, $action, ['actor' => $u, 'actor_role' => 'cashier', 'after' => $after]);
        foreach (range(1, 20) as $i) {
            $log($honest, ActivityActions::PAYMENT_RECORDED);
        }
        $log($honest, ActivityActions::ORDER_VOID);
        foreach (range(1, 6) as $i) {
            $log($risky, ActivityActions::PAYMENT_RECORDED);
        }
        foreach (range(1, 4) as $i) {
            $log($risky, ActivityActions::ORDER_VOID);
            $log($risky, ActivityActions::RECEIPT_REPRINTED);
        }
        $log($risky, ActivityActions::CASH_CLOSED, ['difference_cash' => -75000]);

        $report = app(AnomalyReport::class)->build($org->id, now()->subDay(), now());
        $this->assertSame('Kasir Risiko', $report['cashiers'][0]['name']);
        $types = array_column($report['cashiers'][0]['flags'], 'type');
        $this->assertContains('voids', $types);
        $this->assertContains('reprints', $types);
        $this->assertContains('cash_short', $types);
        $this->assertSame('critical', $report['cashiers'][0]['flags'][array_search('cash_short', $types, true)]['severity']);
        $this->assertSame([], $report['cashiers'][1]['flags']);
    }
}
