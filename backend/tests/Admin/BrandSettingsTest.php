<?php

namespace Tests\Admin;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/** P2-7: SVG logos are accepted, colours must be hex, the public brand payload stays small. */
class BrandSettingsTest extends AdminTestCase
{
    public function test_svg_logo_hex_colours_and_no_base64_payload(): void
    {
        Storage::fake('public');
        $admin = $this->superAdmin();
        $svg = UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"><rect width="10" height="10"/></svg>');

        $this->flushHeaders();
        $token = \Illuminate\Support\Str::random(40);
        \App\Models\ApiToken::query()->create(['user_id' => $admin->id, 'name' => 't', 'token_hash' => hash('sha256', $token)]);
        $this->withHeaders(['Authorization' => 'Bearer ' . $token, 'Accept' => 'application/json'])
            ->post('/api/v1/hellom/admin/brand', ['logo' => $svg, 'primary_color' => '#0C0C0C'])
            ->assertOk()->assertJsonPath('data.brand.logo_base64', null);

        $this->api($admin, 'PUT', '/admin/brand', ['primary_color' => 'red;}body{display:none'])->assertStatus(422)->assertJsonValidationErrors(['primary_color']);
    }
}
