<?php

namespace Tests\Feature\Admin;

use App\Models\DiningTable;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CashierBulkDeleteTest extends TestCase
{
    use RefreshDatabase;

    public function test_bulk_delete_only_deletes_cancelled_orders(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
        ]);

        $table = DiningTable::query()->create([
            'public_id' => 'tbl-test-1',
            'code' => 'T01',
            'name' => 'Table 1',
            'is_active' => true,
        ]);

        $cancelledOrder = Order::query()->create([
            'order_number' => 'ORD-CANCEL-001',
            'dining_table_id' => $table->id,
            'table_label' => 'Table 1',
            'customer_name' => 'Cancel User',
            'status' => Order::STATUS_CANCELLED,
            'total_amount' => 10000,
            'payment_method' => 'cash',
            'payment_status' => 'unpaid',
        ]);

        $activeOrder = Order::query()->create([
            'order_number' => 'ORD-ACTIVE-001',
            'dining_table_id' => $table->id,
            'table_label' => 'Table 1',
            'customer_name' => 'Active User',
            'status' => Order::STATUS_NEW,
            'total_amount' => 12000,
            'payment_method' => 'cash',
            'payment_status' => 'unpaid',
        ]);

        $response = $this
            ->actingAs($admin)
            ->post(route('admin.cashier.orders.bulk'), [
                'action' => 'delete',
                'order_numbers' => [$cancelledOrder->order_number, $activeOrder->order_number],
            ]);

        $response
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('action', 'delete')
            ->assertJsonPath('updated.0', $cancelledOrder->order_number)
            ->assertJsonCount(1, 'updated')
            ->assertJsonCount(1, 'failed')
            ->assertJsonPath('failed.0.order_number', $activeOrder->order_number);

        $this->assertSoftDeleted('orders', ['id' => $cancelledOrder->id]);
        $this->assertDatabaseHas('orders', [
            'id' => $activeOrder->id,
            'deleted_at' => null,
        ]);
    }
}
