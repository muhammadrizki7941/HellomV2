<?php

namespace Tests\Pos;

use App\Models\ApiToken;
use App\Models\AppCatalog;
use App\Models\Entitlement;
use App\Models\Organization;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * POS › Menu product form: an update arrives as multipart POST + _method=PATCH (a real multipart
 * PATCH reaches PHP empty → "The category id field is required"); phone photos up to 8 MB are
 * stored compressed; errors come back in Indonesian in the standard 422 envelope.
 */
class PosProductFormTest extends PosTestCase
{
    use DatabaseTransactions;

    private function owner(Organization $org): array
    {
        $user = User::query()->create(['name' => 'Owner', 'email' => Str::lower(Str::random(10)) . '@example.test', 'password' => bcrypt('secret-password'),
            'role' => 'tenant_admin', 'current_organization_id' => $org->id]);
        $org->users()->attach($user->id, ['role' => 'owner']);
        $app = AppCatalog::query()->firstOrCreate(['slug' => 'pos'], ['name' => 'POS', 'is_active' => true]);
        Entitlement::query()->firstOrCreate(['organization_id' => $org->id, 'app_id' => $app->id], ['status' => 'active', 'starts_at' => now()->subDay(), 'ends_at' => now()->addMonth()]);
        $plain = Str::random(40);
        ApiToken::query()->create(['user_id' => $user->id, 'name' => 't', 'token_hash' => hash('sha256', $plain)]);

        return ['Authorization' => "Bearer {$plain}", 'Accept' => 'application/json'];
    }

    public function test_update_from_the_menu_form_saves_with_photo_and_options(): void
    {
        Storage::fake('public');
        ['org' => $org, 'outlet' => $outlet] = $this->makeOrganization();
        $product = $this->makeProduct($outlet, 20000, ['name' => 'Nasi Goreng']);
        $headers = $this->owner($org);

        // Exactly what PosMenu sends: multipart, POST + _method=PATCH, booleans as "true"/"false".
        $this->withHeaders($headers)->post("/api/v1/hellom/pos/products/{$product->id}", [
            '_method' => 'PATCH',
            'category_id' => $product->category_id,
            'name' => 'Nasi Goreng Spesial',
            'description' => '',
            'price' => '25000',
            'is_available' => 'true',
            'track_stock' => 'true',
            'stock' => '12',
            'options' => [['name' => 'Level pedas', 'type' => 'single', 'is_required' => 'true', 'values' => [['name' => 'Sedang', 'price_delta' => '0'], ['name' => 'Pedas', 'price_delta' => '2000']]]],
            'image' => UploadedFile::fake()->image('dari-hp.jpg', 3000, 2000)->size(5000), // 5 MB phone photo
        ])->assertOk();

        $fresh = Product::withoutGlobalScope('tenant')->with('options.values')->find($product->id);
        $this->assertSame(['Nasi Goreng Spesial', 25000, true, 12], [$fresh->name, (int) $fresh->price, (bool) $fresh->track_stock, (int) $fresh->stock]);
        $this->assertStringEndsWith('.webp', (string) $fresh->image_path);
        Storage::disk('public')->assertExists($fresh->image_path);
        $this->assertSame(['Sedang', 'Pedas'], $fresh->options->first()->values->pluck('name')->all());
    }

    public function test_errors_are_indonesian_per_field_and_another_shops_category_is_refused(): void
    {
        ['org' => $org, 'outlet' => $outlet] = $this->makeOrganization('A');
        ['outlet' => $otherOutlet] = $this->makeOrganization('B');
        $product = $this->makeProduct($outlet, 20000);
        $foreign = $this->makeProduct($otherOutlet, 10000);
        $headers = $this->owner($org);

        $response = $this->withHeaders($headers)->post("/api/v1/hellom/pos/products/{$product->id}", ['_method' => 'PATCH', 'name' => '', 'price' => ''])
            ->assertStatus(422);
        $this->assertSame('Pilih kategori produk dulu.', $response->json('errors.category_id.0'));
        $this->assertSame('Nama produk wajib diisi.', $response->json('errors.name.0'));
        $this->assertSame('Harga wajib diisi.', $response->json('errors.price.0'));
        $this->assertNull($response->json('debug_received'));

        $this->withHeaders($headers)->post("/api/v1/hellom/pos/products/{$product->id}", ['_method' => 'PATCH', 'category_id' => $foreign->category_id, 'name' => 'X', 'price' => '1000'])
            ->assertStatus(422)->assertJsonPath('errors.category_id.0', 'Kategori tidak ditemukan. Pilih kategori yang ada.');
        $this->withHeaders($headers)->post('/api/v1/hellom/pos/products', ['category_id' => $product->category_id, 'name' => 'Es Teh', 'price' => '5000', 'image' => UploadedFile::fake()->create('menu.pdf', 100, 'application/pdf')])
            ->assertStatus(422)->assertJsonPath('errors.image.0', 'Foto harus JPG, PNG, atau WebP.');
    }
}
