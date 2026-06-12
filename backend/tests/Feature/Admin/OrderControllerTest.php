<?php

namespace Tests\Feature\Admin;

use App\Models\Order;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderControllerTest extends TestCase
{
    use RefreshDatabase;

    protected $tenant;
    protected $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->user = User::factory()->create();
        // Note: In a real multi-tenant setup, you'd have a pivot table for user-tenant relationships
        // For this test, we'll assume the user has access to the tenant
    }

    public function test_update_customer_name()
    {
        $table = \App\Models\DiningTable::factory()->create(['tenant_id' => $this->tenant->id]);
        $order = Order::factory()->create([
            'tenant_id' => $this->tenant->id,
            'dining_table_id' => $table->id,
            'customer_name' => 'John Doe',
        ]);

        // Test the controller method directly
        $controller = new \App\Http\Controllers\Admin\OrderController();
        $request = new \Illuminate\Http\Request([
            'customer_name' => 'Jane Smith',
        ]);
        $request->setMethod('PATCH');

        $response = $controller->updateCustomerName($request, $this->tenant->slug, $order->order_number, app(\App\Services\Realtime\RealtimeClient::class));

        $order->refresh();
        $this->assertEquals('Jane Smith', $order->customer_name);
    }

    public function test_update_customer_name_validation()
    {
        $table = \App\Models\DiningTable::factory()->create(['tenant_id' => $this->tenant->id]);
        $order = Order::factory()->create([
            'tenant_id' => $this->tenant->id,
            'dining_table_id' => $table->id,
        ]);

        // Test the controller method directly
        $controller = new \App\Http\Controllers\Admin\OrderController();
        $request = new \Illuminate\Http\Request([
            'customer_name' => str_repeat('a', 81), // Too long
        ]);
        $request->setMethod('PATCH');

        try {
            $controller->updateCustomerName($request, $this->tenant->slug, $order->order_number, app(\App\Services\Realtime\RealtimeClient::class));
            $this->fail('Expected validation exception');
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->assertArrayHasKey('customer_name', $e->errors());
        }
    }
}