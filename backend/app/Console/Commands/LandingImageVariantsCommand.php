<?php

namespace App\Console\Commands;

use App\Support\ImageOptimizer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Hellom Page images uploaded before srcset (PF-04) have no smaller copies. Report by default;
 * --force writes the missing 480w/960w WebP copies next to each image (originals untouched).
 * Afterwards run `php artisan optimize:clear` so cached shop pages pick them up.
 */
class LandingImageVariantsCommand extends Command
{
    protected $signature = 'landing:image-variants {--force : write the missing copies}';

    protected $description = 'Create 480w/960w WebP copies of Hellom Page images for srcset';

    private const FOLDERS = ['landing-builder', 'landing-products'];

    public function handle(): int
    {
        $disk = Storage::disk('public');
        $images = collect(self::FOLDERS)
            ->flatMap(fn (string $folder) => $disk->exists($folder) ? $disk->allFiles($folder) : [])
            ->filter(fn (string $path) => str_ends_with($path, '.webp') && !ImageOptimizer::isVariant($path))
            ->values();

        $missing = $images->filter(fn (string $path) => collect(ImageOptimizer::VARIANT_WIDTHS)
            ->contains(fn (int $w) => !$disk->exists((string) preg_replace('/\.webp$/', "-{$w}w.webp", $path))));
        $this->info("WebP images: {$images->count()}, without all smaller copies: {$missing->count()} (images narrower than a copy never get it).");

        if (!$this->option('force')) {
            $this->line('Report only. Run with --force to write the copies.');

            return self::SUCCESS;
        }

        $written = 0;
        $bar = $this->output->createProgressBar($missing->count());
        foreach ($missing as $path) {
            $written += ImageOptimizer::ensureVariants($path);
            $bar->advance();
        }
        $bar->finish();
        $this->newLine();
        $this->info("Copies written: {$written}. Run `php artisan optimize:clear` to refresh cached shop pages.");

        return self::SUCCESS;
    }
}
