<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('hellom_brand_settings')) {
            return;
        }

        Schema::table('hellom_brand_settings', function (Blueprint $table) {
            $after = 'business_name';

            $columns = [
                'app_name' => fn () => $table->string('app_name')->nullable()->after('id'),
                'favicon_path' => fn () => $table->string('favicon_path')->nullable()->after('logo_dark_path'),
                'secondary_color' => fn () => $table->string('secondary_color')->nullable()->after('primary_color'),
                'login_bg_image' => fn () => $table->string('login_bg_image')->nullable()->after('background_color'),
                'login_title' => fn () => $table->string('login_title')->nullable()->after($after),
                'login_subtitle' => fn () => $table->string('login_subtitle')->nullable()->after('login_title'),
                'register_title' => fn () => $table->string('register_title')->nullable()->after('login_subtitle'),
                'register_subtitle' => fn () => $table->string('register_subtitle')->nullable()->after('register_title'),
                'footer_text' => fn () => $table->string('footer_text')->nullable()->after('register_subtitle'),
                'support_email' => fn () => $table->string('support_email')->nullable()->after('footer_text'),
                'support_phone' => fn () => $table->string('support_phone')->nullable()->after('support_email'),
                'social_instagram' => fn () => $table->string('social_instagram')->nullable()->after('support_phone'),
                'social_facebook' => fn () => $table->string('social_facebook')->nullable()->after('social_instagram'),
                'social_tiktok' => fn () => $table->string('social_tiktok')->nullable()->after('social_facebook'),
                'meta_title' => fn () => $table->string('meta_title')->nullable()->after('social_tiktok'),
                'meta_description' => fn () => $table->text('meta_description')->nullable()->after('meta_title'),
            ];

            foreach ($columns as $column => $definition) {
                if (!Schema::hasColumn('hellom_brand_settings', $column)) {
                    $definition();
                }
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('hellom_brand_settings')) {
            return;
        }

        $columns = [
            'app_name',
            'favicon_path',
            'secondary_color',
            'login_bg_image',
            'login_title',
            'login_subtitle',
            'register_title',
            'register_subtitle',
            'footer_text',
            'support_email',
            'support_phone',
            'social_instagram',
            'social_facebook',
            'social_tiktok',
            'meta_title',
            'meta_description',
        ];

        Schema::table('hellom_brand_settings', function (Blueprint $table) use ($columns) {
            $existing = array_values(array_filter($columns, fn (string $column) => Schema::hasColumn('hellom_brand_settings', $column)));
            if ($existing !== []) {
                $table->dropColumn($existing);
            }
        });
    }
};
