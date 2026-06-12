<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('landing_page_settings', function (Blueprint $table) {
            $table->id();

            // Hero Section
            $table->string('hero_badge', 120)->nullable();
            $table->string('hero_title', 255)->nullable();
            $table->text('hero_subtitle')->nullable();
            $table->string('hero_cta_primary', 120)->nullable();
            $table->string('hero_cta_secondary', 120)->nullable();
            $table->string('hero_trial_text', 120)->nullable();

            // Problems Section
            $table->text('problems')->nullable();
            $table->text('problems_solution')->nullable();

            // Features Section
            $table->string('features_title', 255)->nullable();
            $table->text('features_subtitle')->nullable();
            $table->text('features')->nullable();

            // Pricing Section
            $table->string('pricing_title', 255)->nullable();
            $table->text('pricing_subtitle')->nullable();
            $table->text('pricing_plans')->nullable();

            // Comparison Section
            $table->string('comparison_title', 255)->nullable();
            $table->text('comparison_features')->nullable();

            // Final CTA Section
            $table->string('final_cta_title', 255)->nullable();
            $table->text('final_cta_subtitle')->nullable();
            $table->string('final_cta_button', 120)->nullable();
            $table->string('final_cta_footer', 255)->nullable();

            // Footer
            $table->string('footer_text', 255)->nullable();

            // Banner Image
            $table->string('banner_image_path', 255)->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('landing_page_settings');
    }
};
