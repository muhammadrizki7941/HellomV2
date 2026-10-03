<?php

namespace App\Http\Controllers\Api\V1\Hellom;

use App\Services\Landing\TemplateLibrary;
use App\Support\ImageOptimizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * Super admin › Template Hellom Page (Fase 7.4): images of each template's slots, show/hide, order.
 * New templates are added as data files (resources/landing/templates), not from here.
 */
class AdminLandingTemplateController extends BaseApiController
{
    private const FOLDER = 'landing-templates';

    public function __construct(private readonly TemplateLibrary $library)
    {
    }

    public function index(): JsonResponse
    {
        return $this->ok(['templates' => $this->library->forAdmin()], 'Template Hellom Page');
    }

    public function updateLayout(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'order' => ['required', 'array', 'max:200'],
            'order.*' => ['string', 'max:40'],
            'hidden' => ['present', 'array', 'max:200'],
            'hidden.*' => ['string', 'max:40'],
        ]);
        $before = $this->library->overrides();
        $this->library->saveLayout($validated['order'], $validated['hidden']);
        $after = $this->library->overrides();
        $this->adminAudit($request, 'landing_templates.layout_updated', 'system_setting', null, [
            'hidden' => [$before['hidden'], $after['hidden']],
            'order_changed' => $before['order'] !== $after['order'],
        ]);

        return $this->ok(['templates' => $this->library->forAdmin()], 'Urutan & tampilan template disimpan');
    }

    public function uploadImage(Request $request, string $templateId, string $slot): JsonResponse
    {
        $validated = $request->validate([
            'file' => ['required', 'file', 'max:8192', 'mimes:jpg,jpeg,png,webp'],
        ], [
            'file.required' => 'Pilih gambar dulu.',
            'file.max' => 'Ukuran gambar maksimal 8 MB.',
            'file.mimes' => 'Pakai gambar JPG, PNG, atau WebP.',
        ]);
        $file = $validated['file'];
        if (!$file instanceof UploadedFile) {
            return $this->fail('File tidak valid', ['code' => 'INVALID_UPLOAD_FILE'], 422);
        }
        try {
            $path = ImageOptimizer::storeWebp($file, self::FOLDER);
        } catch (RuntimeException $e) {
            return $this->fail($e->getMessage(), ['code' => 'IMAGE_UNREADABLE'], 422);
        }
        $url = self::publicBase() . $path;
        try {
            $previous = $this->library->setImage($templateId, $slot, $url);
        } catch (InvalidArgumentException $e) {
            ImageOptimizer::delete($path);

            return $this->fail($e->getMessage(), ['code' => 'TEMPLATE_SLOT_UNKNOWN'], 404);
        }
        $this->deleteOwn($previous);
        $this->adminAudit($request, 'landing_templates.image_set', 'system_setting', null, ['template' => $templateId, 'slot' => $slot]);

        return $this->ok(['templates' => $this->library->forAdmin()], 'Gambar template disimpan');
    }

    public function deleteImage(Request $request, string $templateId, string $slot): JsonResponse
    {
        try {
            $previous = $this->library->setImage($templateId, $slot, null);
        } catch (InvalidArgumentException $e) {
            return $this->fail($e->getMessage(), ['code' => 'TEMPLATE_SLOT_UNKNOWN'], 404);
        }
        $this->deleteOwn($previous);
        $this->adminAudit($request, 'landing_templates.image_removed', 'system_setting', null, ['template' => $templateId, 'slot' => $slot]);

        return $this->ok(['templates' => $this->library->forAdmin()], 'Gambar template dihapus');
    }

    /** "/media/" — same relative URLs as the sellers' own uploads (FileAssetController). */
    private static function publicBase(): string
    {
        return '/' . trim((string) config('filesystems.disks.public.url', '/media'), '/') . '/';
    }

    /**
     * Remove a replaced picture only when it is one of ours and no seller page uses it any more
     * (pages that applied the template keep pointing at the file).
     */
    private function deleteOwn(?string $url): void
    {
        $base = self::publicBase();
        if (!$url || !str_starts_with($url, $base . self::FOLDER . '/')) {
            return;
        }
        $path = substr($url, strlen($base));
        $inUse = DB::table('organization_landing_pages')->where('draft_document', 'like', '%' . basename($path) . '%')->exists()
            || DB::table('landing_page_versions')->where('document', 'like', '%' . basename($path) . '%')->exists();
        if (!$inUse) {
            ImageOptimizer::delete($path);
        }
    }
}
