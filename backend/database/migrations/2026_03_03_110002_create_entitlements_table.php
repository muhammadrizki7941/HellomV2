<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('entitlements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('app_id')->constrained('apps')->cascadeOnDelete();
            $table->foreignId('plan_id')->nullable()->constrained('plans')->nullOnDelete();
            $table->string('status', 20)->default('locked');
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'app_id']);
            $table->index(['organization_id', 'status']);
        });

        $landingAppId = DB::table('apps')->where('slug', 'landing_builder')->value('id');
        $posAppId = DB::table('apps')->where('slug', 'pos')->value('id');
        $freePlanId = DB::table('plans')->where('slug', 'free')->value('id');
        $posPlanId = DB::table('plans')->where('slug', 'pos_starter')->value('id');

        if ($landingAppId && $posAppId) {
            $now = now();
            $organizations = DB::table('organizations')->select('id')->get();

            foreach ($organizations as $organization) {
                DB::table('entitlements')->updateOrInsert(
                    [
                        'organization_id' => $organization->id,
                        'app_id' => $landingAppId,
                    ],
                    [
                        'plan_id' => $freePlanId,
                        'status' => 'active',
                        'starts_at' => $now,
                        'ends_at' => null,
                        'updated_at' => $now,
                        'created_at' => $now,
                    ]
                );

                DB::table('entitlements')->updateOrInsert(
                    [
                        'organization_id' => $organization->id,
                        'app_id' => $posAppId,
                    ],
                    [
                        'plan_id' => $posPlanId,
                        'status' => 'locked',
                        'starts_at' => null,
                        'ends_at' => null,
                        'updated_at' => $now,
                        'created_at' => $now,
                    ]
                );
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('entitlements');
    }
};
