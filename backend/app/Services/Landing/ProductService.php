<?php

namespace App\Services\Landing;

use App\Models\LandingProduct;
use App\Models\Organization;
use App\Support\DriveLink;
use App\Support\FrontendUrl;
use App\Support\ImageOptimizer;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Seller product management. Delivery secrets (Drive/link URL, note, file) are validated
 * here and stored encrypted / on the private disk.
 */
final class ProductService
{
    public const MAX_FILE_KB = 10240; // 10 MB (frontend checks the same)

    /** Downloadable file types for "upload file" products. */
    public const FILE_EXTENSIONS = [
        'pdf', 'epub', 'mobi', 'txt', 'doc', 'docx', 'xls', 'xlsx', 'csv', 'ppt', 'pptx', 'key',
        'zip', 'rar', '7z', 'mp3', 'wav', 'm4a', 'mp4', 'mov', 'jpg', 'jpeg', 'png', 'webp', 'gif',
        'psd', 'ai', 'eps', 'fig', 'sketch', 'xd', 'ttf', 'otf', 'woff', 'woff2', 'xmp', 'lrtemplate', 'cube', 'dng',
    ];

    public const FIELD_TYPES = ['text', 'textarea', 'number', 'select'];

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'type' => ['required', 'in:' . implode(',', LandingProduct::TYPES)],
            'name' => ['required', 'string', 'min:3', 'max:200'],
            'description' => ['nullable', 'string', 'max:20000'],
            'price' => ['required', 'integer', 'min:10000', 'max:100000000'],
            'compare_at_price' => ['nullable', 'integer', 'min:0', 'max:100000000'],
            'stock' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'is_active' => ['sometimes', 'boolean'],
            'require_phone' => ['sometimes', 'boolean'],
            'delivery_url' => ['nullable', 'string', 'max:2000'],
            'delivery_note' => ['nullable', 'string', 'max:2000'],
            'access_max_opens' => ['nullable', 'integer', 'min:1', 'max:10000'],
            'access_days' => ['nullable', 'integer', 'min:1', 'max:3650'],
            'download_limit' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'shipping_mode' => ['nullable', 'in:free,flat,manual'],
            'shipping_fee' => ['nullable', 'integer', 'min:0', 'max:10000000'],
            'weight_grams' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'checkout_fields' => ['nullable', 'array', 'max:10'],
            'checkout_fields.*.label' => ['required', 'string', 'max:80'],
            'checkout_fields.*.type' => ['required', 'in:' . implode(',', self::FIELD_TYPES)],
            'checkout_fields.*.required' => ['sometimes', 'boolean'],
            'checkout_fields.*.options' => ['nullable', 'array', 'max:20'],
            'checkout_fields.*.options.*' => ['string', 'max:80'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:100000'],
        ];
    }

    /** Create or update from validated input; type-specific checks raise 422. */
    public function save(Organization $organization, array $data, ?LandingProduct $product = null): LandingProduct
    {
        $product ??= new LandingProduct(['organization_id' => $organization->id]);
        $type = (string) $data['type'];
        $errors = [];

        $deliveryUrl = trim((string) ($data['delivery_url'] ?? ''));
        if ($type === LandingProduct::TYPE_DRIVE) {
            if ($deliveryUrl === '') {
                $errors['delivery_url'] = 'Tempel link Google Drive produk kamu.';
            } elseif (DriveLink::parse($deliveryUrl) === null) {
                $errors['delivery_url'] = 'Link harus link Google Drive/Docs (drive.google.com/file/d/…, …/folders/…, atau docs.google.com/…).';
            }
        } elseif ($type === LandingProduct::TYPE_LINK) {
            if ($deliveryUrl === '' || !DriveLink::isSafeHttpsUrl($deliveryUrl)) {
                $errors['delivery_url'] = 'Isi link akses yang diawali https:// (kelas online, grup Telegram/WhatsApp, Notion, dll).';
            }
        } else {
            $deliveryUrl = '';
        }
        if (isset($data['compare_at_price']) && $data['compare_at_price'] !== null && (int) $data['compare_at_price'] <= (int) $data['price']) {
            $errors['compare_at_price'] = 'Harga coret harus lebih tinggi dari harga jual.';
        }
        if ($type === LandingProduct::TYPE_PHYSICAL && ($data['shipping_mode'] ?? 'free') === 'flat' && (int) ($data['shipping_fee'] ?? 0) <= 0) {
            $errors['shipping_fee'] = 'Isi ongkir tetap.';
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $product->fill([
            'type' => $type,
            'name' => trim((string) $data['name']),
            'description' => $data['description'] ?? null,
            'price' => (int) $data['price'],
            'compare_at_price' => isset($data['compare_at_price']) ? (int) $data['compare_at_price'] ?: null : null,
            'stock' => array_key_exists('stock', $data) && $data['stock'] !== null ? (int) $data['stock'] : null,
            'is_active' => (bool) ($data['is_active'] ?? $product->is_active ?? true),
            'require_phone' => (bool) ($data['require_phone'] ?? false),
            'delivery_url' => $deliveryUrl !== '' ? $deliveryUrl : null,
            'delivery_note' => in_array($type, LandingProduct::DIGITAL_TYPES, true) || $type === LandingProduct::TYPE_SERVICE
                ? (trim((string) ($data['delivery_note'] ?? '')) ?: null) : null,
            'access_max_opens' => in_array($type, [LandingProduct::TYPE_DRIVE, LandingProduct::TYPE_LINK], true) ? ($data['access_max_opens'] ?? null) : null,
            'access_days' => in_array($type, LandingProduct::DIGITAL_TYPES, true) ? ($data['access_days'] ?? null) : null,
            'download_limit' => $type === LandingProduct::TYPE_FILE ? ($data['download_limit'] ?? null) : null,
            'shipping_mode' => $type === LandingProduct::TYPE_PHYSICAL ? ($data['shipping_mode'] ?? 'free') : null,
            'shipping_fee' => $type === LandingProduct::TYPE_PHYSICAL && ($data['shipping_mode'] ?? 'free') === 'flat' ? (int) ($data['shipping_fee'] ?? 0) : 0,
            'weight_grams' => $type === LandingProduct::TYPE_PHYSICAL ? ($data['weight_grams'] ?? null) : null,
            'checkout_fields' => $this->normalizeFields($data['checkout_fields'] ?? []),
            'sort_order' => (int) ($data['sort_order'] ?? $product->sort_order ?? 0),
        ]);
        $product->organization_id = $organization->id;
        if ($type !== LandingProduct::TYPE_FILE && $product->file_path) {
            $this->deleteFile($product);
        }
        $product->save();

        return $product;
    }

    public function storeImage(LandingProduct $product, UploadedFile $file): LandingProduct
    {
        $old = $product->image_path;
        $product->image_path = ImageOptimizer::storeWebp($file, 'landing-products/' . $product->organization_id);
        $product->save();
        ImageOptimizer::delete($old);

        return $product;
    }

    public function storeFile(LandingProduct $product, UploadedFile $file): LandingProduct
    {
        $extension = strtolower((string) $file->getClientOriginalExtension());
        if (!in_array($extension, self::FILE_EXTENSIONS, true)) {
            throw ValidationException::withMessages(['file' => 'Jenis file .' . $extension . ' belum didukung.']);
        }
        $old = $product->file_path;
        // Private disk, random name; only reachable through a signed download after payment.
        $path = $file->storeAs('landing-products/' . $product->organization_id, Str::lower(Str::random(32)) . '.' . $extension, 'local');
        if ($path === false) {
            throw ValidationException::withMessages(['file' => 'File gagal disimpan. Coba lagi.']);
        }
        $product->forceFill([
            'file_path' => $path,
            'file_name' => Str::limit($file->getClientOriginalName(), 190, ''),
            'file_size' => (int) $file->getSize(),
            'file_mime' => Str::limit((string) $file->getClientMimeType(), 120, ''),
        ])->save();
        if ($old) {
            Storage::disk('local')->delete($old);
        }

        return $product;
    }

    public function deleteFile(LandingProduct $product): void
    {
        if ($product->file_path) {
            Storage::disk('local')->delete($product->file_path);
        }
        $product->forceFill(['file_path' => null, 'file_name' => null, 'file_size' => null, 'file_mime' => null])->save();
    }

    /**
     * Everything the seller sees (including the delivery link they entered).
     *
     * @return array<string, mixed>
     */
    public function sellerPayload(LandingProduct $product): array
    {
        return $product->publicPayload() + [
            'db_id' => $product->id,
            'is_active' => (bool) $product->is_active,
            'stock' => $product->stock,
            'sold_count' => (int) $product->sold_count,
            'delivery_url' => $product->delivery_url,
            'delivery_note' => $product->delivery_note,
            'drive_link' => $product->type === LandingProduct::TYPE_DRIVE && $product->delivery_url ? DriveLink::parse((string) $product->delivery_url) : null,
            'file_name' => $product->file_name,
            'file_size' => $product->file_size ? (int) $product->file_size : null,
            'access_max_opens' => $product->access_max_opens,
            'access_days' => $product->access_days,
            'download_limit' => $product->download_limit,
            'shipping_mode' => $product->shipping_mode,
            'shipping_fee' => (int) $product->shipping_fee,
            'weight_grams' => $product->weight_grams,
            'raw_checkout_fields' => is_array($product->checkout_fields) ? $product->checkout_fields : [],
            'deliverable' => $product->isDeliverable(),
            'admin_disabled' => $product->admin_disabled_at !== null,
            'admin_disabled_reason' => $product->admin_disabled_reason,
            'checkout_url' => FrontendUrl::to('/beli/' . $product->public_id),
            'created_at' => optional($product->created_at)->toIso8601String(),
            'updated_at' => optional($product->updated_at)->toIso8601String(),
        ];
    }

    /** @return list<array{id:string,label:string,type:string,required:bool,options:list<string>}> */
    private function normalizeFields(mixed $fields): array
    {
        if (!is_array($fields)) {
            return [];
        }
        $out = [];
        $used = [];
        foreach (array_slice(array_values($fields), 0, 10) as $field) {
            $label = trim((string) ($field['label'] ?? ''));
            if ($label === '') {
                continue;
            }
            $id = Str::slug($label, '_') ?: 'field';
            $base = $id;
            for ($i = 2; in_array($id, $used, true); $i++) {
                $id = $base . '_' . $i;
            }
            $used[] = $id;
            $type = in_array($field['type'] ?? 'text', self::FIELD_TYPES, true) ? (string) $field['type'] : 'text';
            $options = $type === 'select'
                ? array_values(array_filter(array_map(fn ($o) => trim((string) $o), (array) ($field['options'] ?? [])), fn ($o) => $o !== ''))
                : [];
            $out[] = ['id' => $id, 'label' => $label, 'type' => $type, 'required' => (bool) ($field['required'] ?? false), 'options' => $options];
        }

        return $out;
    }
}
