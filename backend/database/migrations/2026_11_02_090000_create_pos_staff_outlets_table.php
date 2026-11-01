<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_staff_outlets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pos_staff_id')->constrained('pos_staff')->cascadeOnDelete();
            $table->foreignId('outlet_id')->constrained('outlets')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['pos_staff_id', 'outlet_id']);
            $table->index('outlet_id');
        });

        DB::table('pos_staff')
            ->whereNotNull('outlet_id')
            ->orderBy('id')
            ->chunkById(1000, function ($staffRows): void {
                $now = now();
                $assignments = $staffRows
                    ->map(fn ($staff) => [
                        'pos_staff_id' => $staff->id,
                        'outlet_id' => $staff->outlet_id,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ])
                    ->all();

                DB::table('pos_staff_outlets')->insertOrIgnore($assignments);
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_staff_outlets');
    }
};
