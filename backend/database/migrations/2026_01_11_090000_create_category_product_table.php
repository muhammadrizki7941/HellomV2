<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('category_product', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->constrained()->cascadeOnUpdate()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnUpdate()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['category_id', 'product_id']);
            $table->index(['product_id']);
        });

        // Backfill existing product.category_id into the pivot so current data keeps working.
        // Uses INSERT IGNORE semantics via insertOrIgnore.
        $rows = DB::table('products')
            ->select(['category_id', 'id as product_id'])
            ->whereNotNull('category_id')
            ->get()
            ->map(fn ($r) => [
                'category_id' => (int) $r->category_id,
                'product_id' => (int) $r->product_id,
                'created_at' => now(),
                'updated_at' => now(),
            ])
            ->all();

        if (!empty($rows)) {
            DB::table('category_product')->insertOrIgnore($rows);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('category_product');
    }
};
