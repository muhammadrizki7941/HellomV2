<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            if (!Schema::hasColumn('organizations', 'pos_tenant_slug')) {
                $table->string('pos_tenant_slug', 120)->nullable()->after('status');
            }

            if (!Schema::hasColumn('organizations', 'pos_tenant_name')) {
                $table->string('pos_tenant_name')->nullable()->after('pos_tenant_slug');
            }

            if (!Schema::hasColumn('organizations', 'pos_provisioned_at')) {
                $table->timestamp('pos_provisioned_at')->nullable()->after('pos_tenant_name');
            }
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $dropColumns = [];

            if (Schema::hasColumn('organizations', 'pos_provisioned_at')) {
                $dropColumns[] = 'pos_provisioned_at';
            }

            if (Schema::hasColumn('organizations', 'pos_tenant_name')) {
                $dropColumns[] = 'pos_tenant_name';
            }

            if (Schema::hasColumn('organizations', 'pos_tenant_slug')) {
                $dropColumns[] = 'pos_tenant_slug';
            }

            if ($dropColumns !== []) {
                $table->dropColumn($dropColumns);
            }
        });
    }
};
