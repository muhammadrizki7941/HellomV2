<?php

namespace App\Http\Controllers\Api\V1\Hellom;

use App\Models\FileAsset;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class FileAssetController extends BaseApiController
{
    /** Max total storage per organization in bytes (100 MB) */
    private const ORG_QUOTA_BYTES = 100 * 1024 * 1024;

    public function index(Request $request): JsonResponse
    {
        $organizationId = $this->resolveOrganizationId($request);
        if ($organizationId <= 0) {
            return $this->fail('No active organization', ['code' => 'NO_ACTIVE_ORGANIZATION'], 403);
        }

        $limit = max(1, min((int) $request->query('limit', 30), 100));

        $items = FileAsset::query()
            ->where('organization_id', $organizationId)
            ->where('app_slug', 'landing_builder')
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(fn(FileAsset $asset) => $this->assetPayload($asset))
            ->values();

        return $this->ok(['items' => $items], 'File assets');
    }

    public function upload(Request $request): JsonResponse
    {
        $organizationId = $this->resolveOrganizationId($request);
        if ($organizationId <= 0) {
            return $this->fail('No active organization', ['code' => 'NO_ACTIVE_ORGANIZATION'], 403);
        }

        $validated = $request->validate([
            'file' => ['required', 'file', 'max:4096', 'mimes:jpg,jpeg,png,webp,gif,svg,pdf'],
        ]);

        $file = $validated['file'];
        if (!$file instanceof UploadedFile) {
            return $this->fail('Invalid upload file', ['code' => 'INVALID_UPLOAD_FILE'], 422);
        }

        // ─── Org Quota Check ───
        $usedBytes = (int) FileAsset::query()
            ->where('organization_id', $organizationId)
            ->sum('size_bytes');

        if (($usedBytes + (int) $file->getSize()) > self::ORG_QUOTA_BYTES) {
            return $this->fail(__('hellom.storage_quota_exceeded'), [
                'code' => 'STORAGE_QUOTA_EXCEEDED',
                'used_bytes' => $usedBytes,
                'quota_bytes' => self::ORG_QUOTA_BYTES,
            ], 422);
        }

        // ─── Content Hash Dedup ───
        $contentHash = hash_file('sha256', $file->getRealPath());

        $existing = FileAsset::query()
            ->where('organization_id', $organizationId)
            ->where('content_hash', $contentHash)
            ->first();

        if ($existing) {
            return $this->ok($this->assetPayload($existing), __('hellom.file_duplicate_reused'));
        }

        $folder = sprintf('landing-builder/%d', $organizationId);
        $storedPath = $file->store($folder, 'public');

        $asset = FileAsset::query()->create([
            'organization_id' => $organizationId,
            'app_slug' => 'landing_builder',
            'disk' => 'public',
            'path' => $storedPath,
            'mime_type' => $file->getMimeType(),
            'size_bytes' => (int) $file->getSize(),
            'original_name' => $file->getClientOriginalName(),
            'content_hash' => $contentHash,
            'is_public' => true,
            'metadata' => [
                'extension' => strtolower((string) $file->getClientOriginalExtension()),
            ],
        ]);

        return $this->ok($this->assetPayload($asset), __('hellom.file_uploaded'), 201);
    }

    private function resolveOrganizationId(Request $request): int
    {
        $user = $request->user();
        if (!$user instanceof User) {
            return 0;
        }

        return (int) ($user->current_organization_id ?? 0);
    }

    private function assetPayload(FileAsset $asset): array
    {
        $publicBase = '/' . trim((string) config('filesystems.disks.public.url', '/media'), '/');

        return [
            'id' => (int) $asset->id,
            'organization_id' => (int) $asset->organization_id,
            'app_slug' => (string) $asset->app_slug,
            'path' => (string) $asset->path,
            'url' => $publicBase . '/' . ltrim((string) $asset->path, '/'),
            'mime_type' => $asset->mime_type,
            'size_bytes' => (int) $asset->size_bytes,
            'original_name' => $asset->original_name,
            'is_public' => (bool) $asset->is_public,
            'metadata' => $asset->metadata,
            'created_at' => $asset->created_at,
        ];
    }
}
