<?php

namespace App\Console\Commands;

use App\Models\LandingBlock;
use App\Models\LandingProduct;
use App\Services\Hellom\LandingSaleService;
use App\Support\DriveLink;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Fase 3: turn products written straight into landing blocks (price as text, delivery link
 * in the block) into landing_products, and link each block to its product (content.productId).
 * Report only by default; --force writes. Old orders keep working either way.
 *
 * Mapping: Drive link → drive, other https link → link, no link → service (seller contacts
 * the buyer, as before). Blocks priced under Rp10.000 are skipped (not sellable online).
 */
class LandingProductsFromBlocksCommand extends Command
{
    protected $signature = 'landing:products-from-blocks {--organization= : only this organization id} {--force : create products and link the blocks}';

    protected $description = 'Convert product/PDF blocks into Hellom Page products (report by default)';

    public function handle(LandingSaleService $sales): int
    {
        $blocks = LandingBlock::query()
            ->whereIn('block_type', ['product', 'pdf'])
            ->when($this->option('organization'), fn ($q, $id) => $q->where('organization_id', (int) $id))
            ->orderBy('id')
            ->get()
            ->filter(fn (LandingBlock $b) => $b->isPaidProduct() && empty(($b->content ?? [])['productId']));

        $rows = [];
        foreach ($blocks as $block) {
            $content = is_array($block->content) ? $block->content : [];
            $price = $sales->parsePrice($content['price'] ?? 0);
            $link = trim((string) ($content['fileUrl'] ?? $content['downloadUrl'] ?? $content['driveUrl'] ?? ''));
            $type = $link === '' ? LandingProduct::TYPE_SERVICE : (DriveLink::parse($link) ? LandingProduct::TYPE_DRIVE : (DriveLink::isSafeHttpsUrl($link) ? LandingProduct::TYPE_LINK : LandingProduct::TYPE_SERVICE));
            $name = Str::limit(trim((string) ($content['name'] ?? $content['title'] ?? 'Produk')), 200, '') ?: 'Produk';
            $action = $price < 10000 ? 'skip (harga < Rp10.000)' : ($this->option('force') ? 'created' : 'would create');

            if ($this->option('force') && $price >= 10000) {
                DB::transaction(function () use ($block, $content, $price, $link, $type, $name): void {
                    $product = LandingProduct::query()->create([
                        'organization_id' => $block->organization_id,
                        'type' => $type,
                        'name' => $name,
                        'description' => isset($content['description']) ? e((string) $content['description']) : null,
                        'price' => $price,
                        'delivery_url' => in_array($type, [LandingProduct::TYPE_DRIVE, LandingProduct::TYPE_LINK], true) ? $link : null,
                        'is_active' => true,
                    ]);
                    $content['productId'] = $product->public_id;
                    $block->forceFill(['content' => $content])->save();
                });
            }
            $rows[] = [$block->organization_id, $block->id, $block->block_type, $name, $price, $type, $action];
        }

        $rows === [] ? $this->info('No unlinked product blocks.') : $this->table(['organization', 'block', 'block type', 'name', 'price', 'product type', 'action'], $rows);
        if (!$this->option('force') && $rows !== []) {
            $this->comment('Report only. Run with --force to create the products.');
        }

        return self::SUCCESS;
    }
}
