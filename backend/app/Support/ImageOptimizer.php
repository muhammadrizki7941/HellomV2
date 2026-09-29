<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Stores an uploaded image as WebP (GD), scaled down to a maximum width. Re-encoding
 * also drops EXIF data (e.g. GPS location) and anything hidden in the original file.
 */
final class ImageOptimizer
{
    public static function storeWebp(UploadedFile $file, string $folder, int $maxWidth = 1600, int $quality = 80): string
    {
        $data = file_get_contents($file->getRealPath());
        $image = $data !== false ? @imagecreatefromstring($data) : false;
        if ($image === false) {
            throw new RuntimeException('Gambar tidak bisa dibaca. Pakai JPG, PNG, atau WebP.');
        }

        $width = imagesx($image);
        $height = imagesy($image);
        if ($width > $maxWidth) {
            $scaled = imagescale($image, $maxWidth, (int) round($height * $maxWidth / $width), IMG_BICUBIC);
            if ($scaled !== false) {
                imagedestroy($image);
                $image = $scaled;
            }
        }
        imagepalettetotruecolor($image);
        imagealphablending($image, true);
        imagesavealpha($image, true);

        ob_start();
        imagewebp($image, null, $quality);
        $webp = (string) ob_get_clean();
        imagedestroy($image);

        $path = trim($folder, '/') . '/' . Str::lower(Str::random(24)) . '.webp';
        Storage::disk('public')->put($path, $webp);

        return $path;
    }
}
