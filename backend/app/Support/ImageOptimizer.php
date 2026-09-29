<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Stores an uploaded image as WebP (GD), scaled down to a maximum width. Re-encoding
 * also drops EXIF data (e.g. GPS location) and anything hidden in the original file.
 *
 * Next to "{name}.webp" it writes smaller copies "{name}-480w.webp" / "{name}-960w.webp"
 * (only when the image is wider), which shop pages offer through srcset (PF-04).
 */
final class ImageOptimizer
{
    /** Widths of the smaller copies used in srcset. */
    public const VARIANT_WIDTHS = [480, 960];

    public static function storeWebp(UploadedFile $file, string $folder, int $maxWidth = 1600, int $quality = 80): string
    {
        $data = file_get_contents($file->getRealPath());

        return self::storeWebpFromBinary($data === false ? '' : $data, $folder, $maxWidth, $quality);
    }

    public static function storeWebpFromBinary(string $data, string $folder, int $maxWidth = 1600, int $quality = 80): string
    {
        $image = $data !== '' ? @imagecreatefromstring($data) : false;
        if ($image === false) {
            throw new RuntimeException('Gambar tidak bisa dibaca. Pakai JPG, PNG, atau WebP.');
        }

        $image = self::scaled($image, $maxWidth);
        $path = trim($folder, '/') . '/' . Str::lower(Str::random(24)) . '.webp';
        Storage::disk('public')->put($path, self::encode($image, $quality));
        self::writeVariants($image, $path, $quality);
        imagedestroy($image);

        return $path;
    }

    /**
     * Create the missing smaller copies of an existing WebP on the public disk (backfill).
     * Returns the number of files written.
     */
    public static function ensureVariants(string $path, int $quality = 80): int
    {
        $disk = Storage::disk('public');
        if (!str_ends_with($path, '.webp') || self::isVariant($path) || !$disk->exists($path)) {
            return 0;
        }
        $image = @imagecreatefromstring((string) $disk->get($path));
        if ($image === false) {
            return 0;
        }
        $written = self::writeVariants($image, $path, $quality);
        imagedestroy($image);

        return $written;
    }

    /**
     * srcset for an image URL served from the public disk ("/media/…" or "/storage/…"),
     * or null when it has no smaller copies (external URL, old upload, small image).
     */
    public static function srcset(?string $url): ?string
    {
        $path = self::publicPath($url);
        if ($path === null) {
            return null;
        }
        $disk = Storage::disk('public');
        $entries = [];
        foreach (self::VARIANT_WIDTHS as $width) {
            $variant = self::variantPath($path, $width);
            if ($disk->exists($variant)) {
                $entries[] = self::variantPath((string) $url, $width) . " {$width}w";
            }
        }
        if ($entries === []) {
            return null;
        }
        $size = @getimagesize($disk->path($path));
        $entries[] = $url . ' ' . ((int) ($size[0] ?? 1600)) . 'w';

        return implode(', ', $entries);
    }

    /** Delete an image and its smaller copies from the public disk. */
    public static function delete(?string $path): void
    {
        if (!$path) {
            return;
        }
        $disk = Storage::disk('public');
        $disk->delete(array_merge([$path], array_map(fn (int $w) => self::variantPath($path, $w), self::VARIANT_WIDTHS)));
    }

    public static function isVariant(string $path): bool
    {
        return (bool) preg_match('/-\d+w\.webp$/', $path);
    }

    private static function variantPath(string $path, int $width): string
    {
        return (string) preg_replace('/\.webp$/', "-{$width}w.webp", $path);
    }

    private static function publicPath(?string $url): ?string
    {
        $url = (string) $url;
        $base = '/' . trim((string) config('filesystems.disks.public.url', '/media'), '/') . '/';
        foreach (array_unique([$base, '/media/', '/storage/']) as $prefix) {
            if (str_starts_with($url, $prefix) && str_ends_with($url, '.webp') && !str_contains($url, '..')) {
                return substr($url, strlen($prefix));
            }
        }

        return null;
    }

    private static function writeVariants(\GdImage $image, string $path, int $quality): int
    {
        $written = 0;
        foreach (self::VARIANT_WIDTHS as $width) {
            $variant = self::variantPath($path, $width);
            if (imagesx($image) <= $width || Storage::disk('public')->exists($variant)) {
                continue;
            }
            $small = imagescale($image, $width, (int) round(imagesy($image) * $width / imagesx($image)), IMG_BICUBIC);
            if ($small === false) {
                continue;
            }
            imagesavealpha($small, true);
            Storage::disk('public')->put($variant, self::encode($small, $quality));
            imagedestroy($small);
            $written++;
        }

        return $written;
    }

    private static function scaled(\GdImage $image, int $maxWidth): \GdImage
    {
        $width = imagesx($image);
        if ($width > $maxWidth) {
            $scaled = imagescale($image, $maxWidth, (int) round(imagesy($image) * $maxWidth / $width), IMG_BICUBIC);
            if ($scaled !== false) {
                imagedestroy($image);
                $image = $scaled;
            }
        }
        imagepalettetotruecolor($image);
        imagealphablending($image, true);
        imagesavealpha($image, true);

        return $image;
    }

    private static function encode(\GdImage $image, int $quality): string
    {
        ob_start();
        imagewebp($image, null, $quality);

        return (string) ob_get_clean();
    }
}
