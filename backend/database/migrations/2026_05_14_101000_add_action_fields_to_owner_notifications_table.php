<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('owner_notifications')) {
            return;
        }

        Schema::table('owner_notifications', function (Blueprint $table) {
            if (!Schema::hasColumn('owner_notifications', 'action_type')) {
                $table->string('action_type', 50)->nullable()->after('is_read');
            }

            if (!Schema::hasColumn('owner_notifications', 'action_url')) {
                $table->string('action_url', 255)->nullable()->after('action_type');
            }

            if (!Schema::hasColumn('owner_notifications', 'action_status')) {
                $table->enum('action_status', ['pending', 'done', 'ignored'])->nullable()->after('action_url');
            }

            if (!Schema::hasColumn('owner_notifications', 'action_done_at')) {
                $table->timestamp('action_done_at')->nullable()->after('action_status');
            }

            if (!Schema::hasColumn('owner_notifications', 'reference_id')) {
                $table->unsignedBigInteger('reference_id')->nullable()->after('action_done_at');
            }

            if (!Schema::hasColumn('owner_notifications', 'reference_type')) {
                $table->string('reference_type', 50)->nullable()->after('reference_id');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('owner_notifications')) {
            return;
        }

        Schema::table('owner_notifications', function (Blueprint $table) {
            $columns = [
                'action_type',
                'action_url',
                'action_status',
                'action_done_at',
                'reference_id',
                'reference_type',
            ];

            $existing = array_values(array_filter($columns, fn (string $column): bool => Schema::hasColumn('owner_notifications', $column)));
            if ($existing !== []) {
                $table->dropColumn($existing);
            }
        });
    }
};
