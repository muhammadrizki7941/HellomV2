<?php

namespace Tests\Landing;

use App\Http\Middleware\Api\EnsureAppEntitlement;
use App\Services\Landing\LandingDocumentService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Fase 0 (link-in-bio): editor images. A phone photo (> 4 MB) used to be refused while the
 * editor promised 8 MB; the stored /media URL must load as an image and survive draft →
 * publish → server-rendered page.
 */
class LandingAssetUploadTest extends SellerFinanceTestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(EnsureAppEntitlement::class);
        Storage::fake('public');
    }

    /** A real JPEG of about $bytes: a small photo padded with APP15 segments (decoders skip them). */
    private function photo(int $bytes, string $name = 'foto-hp.jpg'): UploadedFile
    {
        $image = imagecreatetruecolor(1200, 900);
        imagefill($image, 0, 0, imagecolorallocate($image, 200, 120, 40));
        ob_start();
        imagejpeg($image, null, 90);
        $jpeg = (string) ob_get_clean();
        imagedestroy($image);

        $segment = "\xFF\xEF" . pack('n', 65535) . str_repeat("\0", 65533);
        $padding = str_repeat($segment, max(0, intdiv($bytes - strlen($jpeg), strlen($segment)) + 1));
        $path = tempnam(sys_get_temp_dir(), 'jpg');
        file_put_contents($path, substr($jpeg, 0, 2) . $padding . substr($jpeg, 2)); // after SOI

        return new UploadedFile($path, $name, 'image/jpeg', null, true);
    }

    private function upload(array $seller, UploadedFile $file)
    {
        return $this->withHeaders(['Authorization' => "Bearer {$seller['token']}", 'Accept' => 'application/json'])
            ->post('/api/v1/hellom/apps/landing-builder/assets/upload', ['file' => $file]);
    }

    public function test_phone_photo_is_stored_as_webp_and_loads_on_the_published_page(): void
    {
        $seller = $this->seller();
        $seller['org']->forceFill(['landing_username' => 'foto' . $seller['org']->id])->save();
        $photo = $this->photo(5 * 1024 * 1024);
        $this->assertGreaterThan(4096 * 1024, $photo->getSize());

        $url = $this->upload($seller, $photo)->assertCreated()->json('data.url');

        $this->assertMatchesRegularExpression('#^/media/landing-builder/' . $seller['org']->id . '/[^/]+\.webp$#', $url);
        // The URL itself serves the image (not the app shell).
        $this->get($url)->assertOk()->assertHeader('Content-Type', 'image/webp');

        $documents = app(LandingDocumentService::class);
        $draft = $documents->saveDraft($seller['page'], ['blocks' => [
            ['id' => 'p1', 'type' => 'profile', 'content' => ['name' => 'Toko Foto', 'avatarUrl' => $url]],
            ['id' => 'i1', 'type' => 'image', 'content' => ['imageUrl' => $url, 'caption' => 'Produk kami']],
        ]], null);
        $this->assertSame($url, $draft['document']['blocks'][1]['content']['imageUrl']);
        $documents->publish($seller['page']->fresh(), $seller['user']);

        $this->get('/foto' . $seller['org']->id)->assertOk()
            ->assertSee('src="' . $url . '"', false)
            ->assertSee('class="avatar" src="' . $url . '"', false);
    }

    public function test_too_large_or_wrong_type_gets_a_clear_message(): void
    {
        $seller = $this->seller();

        $this->upload($seller, $this->photo(9 * 1024 * 1024))->assertStatus(422)
            ->assertJsonPath('errors.file.0', 'Ukuran file maksimal 8 MB.');
        $this->upload($seller, UploadedFile::fake()->create('logo.svg', 10, 'image/svg+xml'))->assertStatus(422)
            ->assertJsonPath('errors.file.0', 'Format file belum didukung. Pakai JPG, PNG, WebP, GIF, atau PDF.');
    }
}
