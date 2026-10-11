<?php

namespace Tests\Pos;

use App\Models\ApiToken;
use App\Models\AppCatalog;
use App\Models\Entitlement;
use App\Models\Order;
use App\Models\Organization;
use App\Models\Outlet;
use App\Models\PosActivityLog;
use App\Models\PosStaff;
use App\Models\Product;
use App\Models\TableBill;
use App\Models\User;
use App\Services\Pos\Audit\AnomalyReport;
use App\Support\Pos\ActivityActions as A;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;

/**
 * Phase 2/3 anti-fraud: item edits (PIN after a printed receipt), manual discount and payment-method
 * change (owner PIN), drawer opened without a sale, split bill, table transfer, bill move/merge —
 * paid orders never move; totals repriced on the server; stock follows; every change logged.
 */
class PosOrderAdjustmentTest extends PosTestCase
{
    use DatabaseTransactions;

    private const API = '/api/v1/hellom/pos';
    private const PIN = '583920';

    /** @return array{0: array<string,string>, 1: User} */
    private function login(Organization $org, string $pivotRole, Outlet $outlet): array
    {
        $user = User::query()->create([
            'name' => ucfirst($pivotRole) . ' ' . Str::random(4), 'email' => Str::lower(Str::random(10)) . '@example.test', 'password' => bcrypt('secret-password'),
            'role' => $pivotRole === 'cashier' ? 'cashier' : 'tenant_admin', 'current_organization_id' => $org->id,
        ]);
        $org->users()->attach($user->id, ['role' => $pivotRole]);
        $app = AppCatalog::query()->firstOrCreate(['slug' => 'pos'], ['name' => 'POS', 'is_active' => true]);
        Entitlement::query()->firstOrCreate(['organization_id' => $org->id, 'app_id' => $app->id], ['status' => 'active', 'starts_at' => now()->subDay(), 'ends_at' => now()->addMonth()]);
        if ($pivotRole === 'cashier') {
            PosStaff::query()->create(['organization_id' => $org->id, 'outlet_id' => $outlet->id, 'tenant_id' => $outlet->tenant_slug, 'linked_user_id' => $user->id,
                'name' => $user->name, 'role' => 'cashier', 'employment_status' => 'active']);
        }
        $plain = Str::random(40);
        ApiToken::query()->create(['user_id' => $user->id, 'name' => 'test', 'token_hash' => hash('sha256', $plain)]);

        return [['Authorization' => "Bearer {$plain}", 'Accept' => 'application/json', 'X-Outlet-Id' => (string) $outlet->id], $user];
    }

    /** Shop with 10% tax, owner (with PIN) and a cashier. */
    private function shop(string $label): array
    {
        ['org' => $org, 'outlet' => $outlet] = $this->makeOrganization($label, ['pricing' => ['tax_percent' => 10, 'service_percent' => 0, 'rounding' => 0]]);
        [$owner, $ownerUser] = $this->login($org, 'owner', $outlet);
        [$cashier] = $this->login($org, 'cashier', $outlet);
        $this->ownerPin($org, $ownerUser->id, self::PIN);

        return compact('org', 'outlet', 'owner', 'cashier', 'ownerUser');
    }

    private function order(array $headers, array $items, ?int $tableId = null): array
    {
        return $this->withHeaders($headers)->postJson(self::API . '/orders', array_filter(['items' => $items, 'table_id' => $tableId]))->assertCreated()->json('data.order');
    }

    private function log(Organization $org, string $action): PosActivityLog
    {
        return PosActivityLog::query()->where('organization_id', $org->id)->where('action', $action)->latest('id')->firstOrFail();
    }

    public function test_item_edits_follow_stock_reprice_and_need_the_pin_after_a_printed_receipt(): void
    {
        ['org' => $org, 'outlet' => $outlet, 'cashier' => $h, 'ownerUser' => $owner] = $this->shop('A');
        $nasi = $this->makeProduct($outlet, 20000, ['name' => 'Nasi Goreng', 'track_stock' => true, 'stock' => 10]);
        $teh = $this->makeProduct($outlet, 5000, ['name' => 'Es Teh']);
        $order = $this->order($h, [['product_id' => $nasi->id, 'quantity' => 3], ['product_id' => $teh->id, 'quantity' => 1]]);
        $this->assertSame(71500, $order['final_amount']); // (60000 + 5000) × 1.1
        $this->assertSame(7, (int) $nasi->fresh()->stock);
        $items = collect(Order::withoutGlobalScope('tenant')->find($order['id'])->items)->keyBy('product_name');
        $nasiItem = $items['Nasi Goreng']->id;
        $tehItem = $items['Es Teh']->id;

        // Reduce before any print: reason needed, no PIN; stock returns; totals repriced on the server.
        $this->withHeaders($h)->patchJson(self::API . "/orders/{$order['id']}/items/{$nasiItem}", ['qty' => 2])->assertStatus(422)->assertJsonPath('errors.reason.0', 'Tulis alasan mengurangi/menghapus item.');
        $this->withHeaders($h)->patchJson(self::API . "/orders/{$order['id']}/items/{$nasiItem}", ['qty' => 2, 'reason' => 'Salah input'])
            ->assertOk()->assertJsonPath('data.order.final_amount', 49500);
        $this->assertSame(8, (int) $nasi->fresh()->stock);
        $reduced = $this->log($org, A::ITEM_QTY_CHANGED);
        $this->assertSame('warning', $reduced->severity);
        $this->assertSame(3, $reduced->before_state['items'][0]['qty']);
        $this->assertEquals(['id' => $nasiItem, 'name' => 'Nasi Goreng', 'from' => 3, 'to' => 2, 'unit_price' => 20000], $reduced->after_state['changed_item']);

        // Adding is fine (stock checked); more than the stock is refused.
        $this->withHeaders($h)->patchJson(self::API . "/orders/{$order['id']}/items/{$nasiItem}", ['qty' => 20])->assertStatus(409);
        $this->withHeaders($h)->patchJson(self::API . "/orders/{$order['id']}/items/{$nasiItem}", ['qty' => 3])->assertOk();
        $this->assertSame(7, (int) $nasi->fresh()->stock);
        $this->assertSame('info', $this->log($org, A::ITEM_QTY_CHANGED)->severity);

        // After a (draft) receipt was printed, removing an item needs the owner PIN.
        $this->withHeaders($h)->postJson(self::API . "/orders/{$order['id']}/receipt/printed")->assertOk();
        $this->withHeaders($h)->patchJson(self::API . "/orders/{$order['id']}/items/{$tehItem}", ['qty' => 0, 'reason' => 'Tidak jadi'])
            ->assertStatus(422)->assertJsonPath('error.code', 'PIN_REQUIRED');
        $this->withHeaders($h)->patchJson(self::API . "/orders/{$order['id']}/items/{$tehItem}", ['qty' => 0, 'reason' => 'Tidak jadi', 'approval_pin' => self::PIN])
            ->assertOk()->assertJsonPath('data.order.final_amount', 66000);
        $removed = $this->log($org, A::ITEM_REMOVED);
        $this->assertSame('critical', $removed->severity);
        $this->assertSame($owner->id, (int) $removed->approver_user_id);
        $this->assertTrue($removed->after_state['receipt_printed']);

        // The last line cannot be removed (void instead); paid orders cannot be edited.
        $this->withHeaders($h)->patchJson(self::API . "/orders/{$order['id']}/items/{$nasiItem}", ['qty' => 0, 'reason' => 'x', 'approval_pin' => self::PIN])
            ->assertStatus(422)->assertJsonPath('error.code', 'LAST_ITEM');
        $this->withHeaders($h)->postJson(self::API . "/orders/{$order['id']}/payment", ['payment_method' => 'cash', 'payment_amount' => 100000])->assertOk();
        $this->withHeaders($h)->patchJson(self::API . "/orders/{$order['id']}/items/{$nasiItem}", ['qty' => 4])->assertStatus(422)->assertJsonPath('error.code', 'ORDER_ALREADY_PAID');
    }

    public function test_manual_discount_needs_the_pin_and_survives_item_changes(): void
    {
        ['org' => $org, 'outlet' => $outlet, 'cashier' => $h, 'ownerUser' => $owner] = $this->shop('B');
        $menu = $this->makeProduct($outlet, 50000);
        $order = $this->order($h, [['product_id' => $menu->id, 'quantity' => 2]]); // 100000 + 10% = 110000
        $itemId = Order::withoutGlobalScope('tenant')->find($order['id'])->items->first()->id;

        $this->withHeaders($h)->postJson(self::API . "/orders/{$order['id']}/discount", ['type' => 'percent', 'value' => 10, 'reason' => 'Teman'])
            ->assertStatus(422)->assertJsonPath('errors.approval_pin.0', 'Minta pemilik toko memasukkan PIN untuk menyetujui.');
        $this->withHeaders($h)->postJson(self::API . "/orders/{$order['id']}/discount", ['type' => 'percent', 'value' => 10, 'reason' => 'Teman', 'approval_pin' => '000111'])
            ->assertStatus(422)->assertJsonPath('error.code', 'PIN_INVALID');
        $this->withHeaders($h)->postJson(self::API . "/orders/{$order['id']}/discount", ['type' => 'fixed', 'value' => 500000, 'reason' => 'x', 'approval_pin' => self::PIN])
            ->assertStatus(422)->assertJsonPath('error.code', 'DISCOUNT_TOO_LARGE');
        $this->withHeaders($h)->postJson(self::API . "/orders/{$order['id']}/discount", ['type' => 'percent', 'value' => 10, 'reason' => 'Pelanggan langganan', 'approval_pin' => self::PIN])
            ->assertOk()->assertJsonPath('data.order.manual_discount_amount', 10000)->assertJsonPath('data.order.final_amount', 99000);

        $log = $this->log($org, A::DISCOUNT_APPLIED);
        $this->assertSame('critical', $log->severity);
        $this->assertSame($owner->id, (int) $log->approver_user_id);
        $this->assertSame(['percent', 10, 110000, 99000], [$log->after_state['discount_type'], $log->after_state['discount_value'], $log->before_state['final_amount'], $log->after_state['final_amount']]);

        // The percentage follows the items; the receipt shows the discount and the real total.
        $this->withHeaders($h)->patchJson(self::API . "/orders/{$order['id']}/items/{$itemId}", ['qty' => 3])->assertOk()
            ->assertJsonPath('data.order.manual_discount_amount', 15000)->assertJsonPath('data.order.final_amount', 148500);
        $this->withHeaders($h)->getJson(self::API . "/orders/{$order['id']}/receipt")->assertOk()
            ->assertJsonPath('data.receipt.manual_discount_amount', 15000)->assertJsonPath('data.receipt.final_amount', 148500);

        // Removing the discount needs no PIN but is logged.
        $this->withHeaders($h)->postJson(self::API . "/orders/{$order['id']}/discount", ['type' => 'none', 'reason' => 'Batal diskon'])
            ->assertOk()->assertJsonPath('data.order.final_amount', 165000);
        $this->assertSame(15000, $this->log($org, A::DISCOUNT_REMOVED)->before_state['manual_discount_amount']);
    }

    public function test_payment_method_change_needs_the_pin_and_only_for_paid_orders(): void
    {
        ['org' => $org, 'outlet' => $outlet, 'cashier' => $h] = $this->shop('C');
        $menu = $this->makeProduct($outlet, 20000);
        $order = $this->order($h, [['product_id' => $menu->id, 'quantity' => 1]]);
        $this->withHeaders($h)->postJson(self::API . "/orders/{$order['id']}/payment-method", ['payment_method' => 'qris', 'reason' => 'x', 'approval_pin' => self::PIN])
            ->assertStatus(422)->assertJsonPath('error.code', 'ORDER_NOT_PAID');
        $this->withHeaders($h)->postJson(self::API . "/orders/{$order['id']}/payment", ['payment_method' => 'cash', 'payment_amount' => 50000])->assertOk();

        $this->withHeaders($h)->postJson(self::API . "/orders/{$order['id']}/payment-method", ['payment_method' => 'qris', 'reason' => 'Ternyata bayar QRIS'])->assertStatus(422);
        $this->withHeaders($h)->postJson(self::API . "/orders/{$order['id']}/payment-method", ['payment_method' => 'qris', 'reason' => 'Ternyata bayar QRIS', 'approval_pin' => self::PIN])
            ->assertOk()->assertJsonPath('data.order.payment_method', 'qris');
        $log = $this->log($org, A::PAYMENT_METHOD_CHANGED);
        $this->assertSame(['cash', 'qris', 'Ternyata bayar QRIS'], [$log->before_state['payment_method'], $log->after_state['payment_method'], $log->reason]);
        $this->assertSame(0, (int) Order::withoutGlobalScope('tenant')->find($order['id'])->payment_change);
    }

    public function test_split_bill_moves_items_to_a_new_order_on_the_same_bill(): void
    {
        ['org' => $org, 'outlet' => $outlet, 'cashier' => $h] = $this->shop('D');
        $table = $this->makeTable($outlet, 'S1');
        $nasi = $this->makeProduct($outlet, 20000, ['name' => 'Nasi', 'track_stock' => true, 'stock' => 10]);
        $teh = $this->makeProduct($outlet, 5000, ['name' => 'Teh']);
        $order = $this->order($h, [['product_id' => $nasi->id, 'quantity' => 2], ['product_id' => $teh->id, 'quantity' => 1]], $table->id);
        $items = Order::withoutGlobalScope('tenant')->find($order['id'])->items->keyBy('product_name');

        $this->withHeaders($h)->postJson(self::API . "/orders/{$order['id']}/split", ['items' => [['item_id' => $items['Nasi']->id, 'qty' => 2], ['item_id' => $items['Teh']->id, 'qty' => 1]]])
            ->assertStatus(422)->assertJsonPath('error.code', 'SPLIT_ALL');
        $res = $this->withHeaders($h)->postJson(self::API . "/orders/{$order['id']}/split", ['items' => [['item_id' => $items['Nasi']->id, 'qty' => 1], ['item_id' => $items['Teh']->id, 'qty' => 1]]])
            ->assertCreated();
        $this->assertSame(22000, $res->json('data.order.final_amount'));
        $this->assertSame(27500, $res->json('data.new_order.final_amount'));
        $this->assertSame($order['table_bill_id'], $res->json('data.new_order.table_bill_id'));
        $this->assertSame($order['id'], $res->json('data.new_order.split_from_order_id'));
        $this->assertSame(8, (int) $nasi->fresh()->stock);

        $log = $this->log($org, A::ORDER_SPLIT);
        $this->assertEquals([['name' => 'Nasi', 'qty' => 1, 'line_total' => 20000], ['name' => 'Teh', 'qty' => 1, 'line_total' => 5000]], $log->after_state['moved_items']);

        // Voiding the split-off order puts its stock back (it was reserved by the original).
        $this->withHeaders($h)->postJson(self::API . "/orders/{$res->json('data.new_order.id')}/cancel", ['reason' => 'Batal', 'approval_pin' => self::PIN])->assertOk();
        $this->assertSame(9, (int) $nasi->fresh()->stock);
    }

    public function test_transfer_and_merge_move_only_unpaid_orders_and_close_the_old_bill(): void
    {
        ['org' => $org, 'outlet' => $outlet, 'cashier' => $h] = $this->shop('E');
        [$t1, $t2, $t3] = [$this->makeTable($outlet, 'M1'), $this->makeTable($outlet, 'M2'), $this->makeTable($outlet, 'M3')];
        $menu = $this->makeProduct($outlet, 10000);
        $line = [['product_id' => $menu->id, 'quantity' => 1]];

        // One order moves from M1 to M2; M1's bill is empty → closed as merged.
        $single = $this->order($h, $line, $t1->id);
        $this->withHeaders($h)->postJson(self::API . "/orders/{$single['id']}/transfer", ['table_id' => $t1->id])->assertStatus(422)->assertJsonPath('error.code', 'SAME_TABLE');
        $moved = $this->withHeaders($h)->postJson(self::API . "/orders/{$single['id']}/transfer", ['table_id' => $t2->id])->assertOk()->json('data.order');
        $this->assertSame((int) $t2->id, (int) Order::withoutGlobalScope('tenant')->find($single['id'])->dining_table_id);
        $this->assertSame(TableBill::STATUS_MERGED, TableBill::query()->find($single['table_bill_id'])->status);
        $this->assertSame(A::TABLE_TRANSFERRED, $this->log($org, A::TABLE_TRANSFERRED)->action);

        // M1 gets a paid order and two unpaid ones; moving the bill to M2 merges the unpaid ones only.
        $paid = $this->order($h, $line, $t1->id);
        $this->withHeaders($h)->postJson(self::API . "/orders/{$paid['id']}/payment", ['payment_method' => 'cash', 'payment_amount' => 20000])->assertOk();
        $this->withHeaders($h)->postJson(self::API . "/orders/{$paid['id']}/transfer", ['table_id' => $t3->id])->assertStatus(422)->assertJsonPath('error.code', 'ORDER_ALREADY_PAID');
        $a = $this->order($h, $line, $t1->id);
        $b = $this->order($h, $line, $t1->id);
        $res = $this->withHeaders($h)->postJson(self::API . "/table-bills/{$a['table_bill_id']}/move", ['table_id' => $t2->id])->assertOk();
        $this->assertSame('merged', $res->json('data.mode'));
        $this->assertSame($moved['table_bill_id'], $res->json('data.bill.id'));
        $this->assertSame(3, $res->json('data.bill.orders_count'));
        $old = TableBill::query()->find($a['table_bill_id']);
        // The paid order settled M1's earlier bill, so these two opened a new one, now empty → merged.
        $this->assertSame([TableBill::STATUS_MERGED, (int) $moved['table_bill_id']], [$old->status, (int) $old->merged_into_bill_id]);
        $this->assertSame(TableBill::STATUS_PAID, TableBill::query()->find($paid['table_bill_id'])->status);
        $this->assertSame((int) $t1->id, (int) Order::withoutGlobalScope('tenant')->find($paid['id'])->dining_table_id);
        $merge = $this->log($org, A::BILL_MERGED);
        $this->assertSame([$a['order_number'], $b['order_number']], $merge->after_state['moved_orders']);

        // Moving to an empty table starts a new bill there.
        $res = $this->withHeaders($h)->postJson(self::API . "/table-bills/{$moved['table_bill_id']}/move", ['table_id' => $t3->id])->assertOk();
        $this->assertSame('moved', $res->json('data.mode'));
        $this->assertSame(TableBill::STATUS_MERGED, TableBill::query()->find($moved['table_bill_id'])->status);
    }

    public function test_drawer_opened_without_a_sale_is_logged_counted_and_flagged(): void
    {
        ['org' => $org, 'outlet' => $outlet, 'cashier' => $h] = $this->shop('F');
        $this->withHeaders($h)->postJson(self::API . '/cash/no-sale', [])->assertStatus(422);
        foreach ([1, 2, 3] as $n) {
            $this->withHeaders($h)->postJson(self::API . '/cash/no-sale', ['reason' => 'Tukar uang kecil'])->assertOk()->assertJsonPath('data.count_today', $n);
        }
        $this->assertSame('critical', $this->log($org, A::DRAWER_NO_SALE)->severity);
        $report = app(AnomalyReport::class)->build($org->id, now()->subDay(), now());
        $flags = array_column($report['cashiers'][0]['flags'], 'type');
        $this->assertContains('no_sales', $flags);
        $this->assertSame(3, $report['cashiers'][0]['no_sales']);
    }
}
