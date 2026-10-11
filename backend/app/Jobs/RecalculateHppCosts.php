<?php

namespace App\Jobs;

use App\Models\Hpp\HppCostSnapshot;
use App\Models\Hpp\HppProduct;
use App\Services\Hpp\CostEngine;
use App\Services\Hpp\ProductCosting;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Recalculate every product's HPP after a price change (purchase, ingredient edit, overhead…) in
 * the background, so the cashier never waits. Stores the cached cost on the product and today's
 * snapshot (trend charts, historical reports). Screens compute live; this feeds lists & history.
 */
class RecalculateHppCosts implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public function __construct(public readonly int $organizationId)
    {
        $this->afterCommit();
    }

    public function handle(): void
    {
        $costing = new ProductCosting(CostEngine::for($this->organizationId));
        foreach ($costing->engine()->products() as $product) {
            /** @var HppProduct $product */
            $summary = $costing->summary($product);
            $product->forceFill([
                'cost_material' => $summary['cost']['material'], 'cost_packaging' => $summary['cost']['packaging'],
                'cost_full' => $summary['cost']['full'], 'cost_updated_at' => now(),
            ])->saveQuietly();
            HppCostSnapshot::query()->updateOrCreate(
                ['product_id' => $product->id, 'snapshot_date' => now()->toDateString()],
                ['cost_material' => $summary['cost']['material'], 'cost_packaging' => $summary['cost']['packaging'],
                    'cost_full' => $summary['cost']['full'], 'selling_price' => $summary['selling_price'], 'margin_pct' => $summary['margin_pct']]
            );
        }
    }
}
