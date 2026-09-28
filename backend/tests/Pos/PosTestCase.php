<?php

namespace Tests\Pos;

use App\Models\Category;
use App\Models\DiningTable;
use App\Models\Organization;
use App\Models\Outlet;
use App\Models\PosLoyaltySetting;
use App\Models\Product;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * POS tests run against the MySQL test database (phpunit.pos.xml → hellom_pos_test);
 * the migrations use MySQL-only SQL, so sqlite cannot be used. Each test runs inside a
 * transaction (DatabaseTransactions in subclasses) unless it needs real concurrency.
 * No outgoing HTTP (realtime, payment gateways) and no mail.
 */
abstract class PosTestCase extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (config('database.connections.' . config('database.default') . '.database') !== 'hellom_pos_test') {
            $this->markTestSkipped('Run with: php vendor/bin/phpunit -c phpunit.pos.xml (database hellom_pos_test).');
        }

        Http::preventStrayRequests();
        Http::fake();
        Mail::fake();
        config(['realtime.enabled' => false, 'realtime.secret' => 'test-secret']);
    }

    /** @return array{org: Organization, outlet: Outlet} */
    protected function makeOrganization(string $label = 'A', array $outletSettings = []): array
    {
        $suffix = Str::lower(Str::random(6));
        $org = Organization::query()->create([
            'name' => "Resto {$label} {$suffix}",
            'slug' => "resto-{$label}-{$suffix}",
            'status' => 'active',
            'pos_tenant_slug' => "t-{$label}-{$suffix}",
        ]);
        $outlet = $this->makeOutlet($org, true, $outletSettings, (string) $org->pos_tenant_slug);

        return ['org' => $org, 'outlet' => $outlet];
    }

    protected function makeOutlet(Organization $org, bool $primary = false, array $settings = [], ?string $tenantSlug = null): Outlet
    {
        $suffix = Str::lower(Str::random(6));

        return Outlet::query()->create([
            'organization_id' => $org->id,
            'name' => 'Outlet ' . $suffix,
            'slug' => 'outlet-' . $suffix,
            'tenant_slug' => $tenantSlug ?? ('o-' . $suffix),
            'is_primary' => $primary,
            'is_active' => true,
            'settings' => $settings,
        ]);
    }

    protected function makeProduct(Outlet $outlet, int $price, array $attributes = []): Product
    {
        $category = Category::withoutGlobalScope('tenant')->firstOrCreate(
            ['tenant_id' => $outlet->tenant_slug, 'slug' => 'makanan'],
            ['outlet_id' => $outlet->id, 'name' => 'Makanan', 'is_active' => true, 'sort_order' => 1]
        );

        return Product::withoutGlobalScope('tenant')->create(array_merge([
            'tenant_id' => $outlet->tenant_slug,
            'outlet_id' => $outlet->id,
            'category_id' => $category->id,
            'name' => 'Menu ' . Str::random(4),
            'slug' => 'menu-' . Str::lower(Str::random(8)),
            'price' => $price,
            'is_available' => true,
            'track_stock' => false,
            'stock' => null,
        ], $attributes));
    }

    protected function makeTable(Outlet $outlet, string $code = 'T1'): DiningTable
    {
        return DiningTable::withoutGlobalScope('tenant')->create([
            'tenant_id' => $outlet->tenant_slug,
            'outlet_id' => $outlet->id,
            'code' => $code,
            'name' => 'Meja ' . $code,
            'kind' => DiningTable::KIND_TABLE,
            'is_active' => true,
        ]);
    }

    protected function loyalty(Organization $org, array $attributes): PosLoyaltySetting
    {
        return PosLoyaltySetting::persistForTenant((string) $org->pos_tenant_slug, $attributes);
    }
}
