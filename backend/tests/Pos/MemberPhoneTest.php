<?php

namespace Tests\Pos;

use App\Services\Pos\MemberService;
use App\Support\PhoneNumber;
use Illuminate\Foundation\Testing\DatabaseTransactions;

/** One phone = one member per tenant (organization), in any format; other tenants are separate. */
class MemberPhoneTest extends PosTestCase
{
    use DatabaseTransactions;

    public function test_phone_formats_are_one_member_per_tenant_but_separate_across_tenants(): void
    {
        $this->assertSame('628123456789', PhoneNumber::normalize('08123456789'));
        $this->assertSame('628123456789', PhoneNumber::normalize('628123456789'));
        $this->assertSame('628123456789', PhoneNumber::normalize('+62 812-3456-789'));

        ['org' => $orgA, 'outlet' => $outletA] = $this->makeOrganization('A');
        $outletA2 = $this->makeOutlet($orgA);
        ['org' => $orgB, 'outlet' => $outletB] = $this->makeOrganization('B');
        $members = app(MemberService::class);

        [$first, $created] = $members->register($orgA, 'Budi', '08123456789', null, $outletA->id, $outletA->tenant_slug);
        $this->assertTrue($created);
        [$again, $createdAgain] = $members->register($orgA, 'Budi Lain', '628123456789', null, $outletA2->id, $outletA2->tenant_slug);
        $this->assertFalse($createdAgain);
        $this->assertSame($first->id, $again->id);
        $this->assertSame($first->id, $members->findByPhone($orgA, '+628123456789')?->id);

        [$other, $createdOther] = $members->register($orgB, 'Budi', '+628123456789', null, $outletB->id, $outletB->tenant_slug);
        $this->assertTrue($createdOther);
        $this->assertNotSame($first->id, $other->id);
        $this->assertSame('628123456789', $other->phone_normalized);
    }

    public function test_self_order_and_cashier_registration_give_the_same_member(): void
    {
        ['org' => $org, 'outlet' => $outlet] = $this->makeOrganization('A');
        $table = $this->makeTable($outlet);
        $product = $this->makeProduct($outlet, 15000);

        $response = $this->postJson('/api/v1/hellom/pos/customer/order', [
            'table_token' => $table->public_id,
            'customer_name' => 'Sari',
            'customer_phone' => '0812 1111 2222',
            'register_member' => true,
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->assertCreated();

        [$fromCashier, $created] = app(MemberService::class)->register($org, 'Sari', '+6281211112222', null, $outlet->id, $outlet->tenant_slug);
        $this->assertFalse($created);
        $this->assertSame($response->json('data.member.id'), $fromCashier->id);
    }
}
