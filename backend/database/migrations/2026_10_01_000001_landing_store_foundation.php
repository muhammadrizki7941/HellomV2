<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 3 — selling on Hellom Page (docs/AUDIT_LANDING_BUILDER.md). Additive only.
 *
 *  - landing_products     sellable products (Drive / file / link / physical / service), BIGINT prices;
 *                         delivery links are stored encrypted and never leave the server before payment
 *  - landing_coupons      discount codes per seller
 *  - landing_refunds      refunds to buyers, paid out by Hellom (like withdrawals, money leaves the seller balance)
 *  - landing_reports      "Laporkan" from public pages
 *  - landing_page_orders  product, quantity, totals, shipping, custom fields, access limits, inventory reservation
 *  - organizations        landing suspension by super admin
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('landing_products', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->restrictOnDelete();
            $table->string('public_id', 24)->unique();            // used in public URLs (no sequential ids)
            $table->string('type', 20);                            // drive|file|link|physical|service
            $table->string('name', 200);
            $table->text('description')->nullable();               // sanitised HTML
            $table->string('image_path', 255)->nullable();         // public disk, WebP
            $table->bigInteger('price');
            $table->bigInteger('compare_at_price')->nullable();    // harga coret
            $table->integer('stock')->nullable();                  // null = unlimited; reserved at checkout
            $table->unsignedInteger('sold_count')->default(0);
            $table->boolean('is_active')->default(true);
            $table->boolean('require_phone')->default(false);
            $table->json('checkout_fields')->nullable();           // extra buyer fields
            $table->string('delivery_mode', 20)->default('link');  // link | google_grant (reserved: Drive API sharing, not built yet)
            $table->text('delivery_url')->nullable();              // encrypted: Drive / access link
            $table->text('delivery_note')->nullable();             // encrypted: shown on the access page after payment
            $table->string('file_path', 255)->nullable();          // private disk
            $table->string('file_name', 200)->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            $table->string('file_mime', 120)->nullable();
            $table->unsignedInteger('access_max_opens')->nullable();   // null = unlimited
            $table->unsignedInteger('access_days')->nullable();        // null = forever
            $table->unsignedInteger('download_limit')->nullable();     // file products
            $table->string('shipping_mode', 10)->nullable();       // physical: free|flat|manual
            $table->bigInteger('shipping_fee')->default(0);
            $table->unsignedInteger('weight_grams')->nullable();   // for a courier integration later
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamp('admin_disabled_at')->nullable();
            $table->string('admin_disabled_reason', 255)->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['organization_id', 'is_active']);
        });

        Schema::create('landing_coupons', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->restrictOnDelete();
            $table->string('code', 40);
            $table->string('type', 10);                            // percent|fixed
            $table->bigInteger('value');                           // percent 1–100 or rupiah
            $table->bigInteger('max_discount')->nullable();        // cap for percent coupons
            $table->bigInteger('min_purchase')->default(0);
            $table->unsignedInteger('max_uses')->nullable();
            $table->unsignedInteger('used_count')->default(0);     // reserved at checkout, released on expiry/failure
            $table->json('product_ids')->nullable();               // null = all products
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['organization_id', 'code']);
        });

        Schema::table('landing_page_orders', function (Blueprint $table): void {
            $table->foreignId('product_id')->nullable()->after('block_id')->constrained('landing_products')->restrictOnDelete();
            $table->unsignedInteger('quantity')->default(1)->after('product_name');
            $table->bigInteger('subtotal_amount')->nullable()->after('quantity');
            $table->bigInteger('discount_amount')->default(0)->after('subtotal_amount');
            $table->bigInteger('shipping_amount')->default(0)->after('discount_amount');
            $table->foreignId('coupon_id')->nullable()->after('shipping_amount')->constrained('landing_coupons')->nullOnDelete();
            $table->string('coupon_code', 40)->nullable()->after('coupon_id');
            $table->json('shipping_address')->nullable()->after('buyer_phone');
            $table->json('custom_fields')->nullable()->after('shipping_address');
            $table->unsignedInteger('access_max_opens')->nullable();
            $table->unsignedInteger('access_days')->nullable();
            $table->unsignedInteger('access_open_count')->default(0);
            $table->timestamp('access_last_opened_at')->nullable();
            $table->unsignedInteger('download_limit')->nullable();
            $table->unsignedInteger('download_count')->default(0);
            $table->string('shipping_courier', 60)->nullable();
            $table->string('tracking_number', 80)->nullable();
            $table->timestamp('shipped_at')->nullable();
            $table->timestamp('inventory_reserved_at')->nullable();
            $table->timestamp('inventory_released_at')->nullable();
            $table->timestamp('emails_sent_at')->nullable();
            $table->unsignedInteger('email_resend_count')->default(0);
            $table->index(['organization_id', 'created_at'], 'lpo_org_created_idx');
            $table->index(['organization_id', 'buyer_email'], 'lpo_org_buyer_email_idx');
        });

        Schema::create('landing_refunds', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->restrictOnDelete();
            $table->foreignId('order_id')->constrained('landing_page_orders')->restrictOnDelete();
            $table->foreignId('requested_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reference', 40)->unique();
            $table->string('status', 20)->default('requested');    // requested|paid|failed
            $table->bigInteger('amount');                           // taken from the seller balance, paid to the buyer
            $table->string('reason', 500);
            $table->string('destination_type', 10)->default('bank');
            $table->string('bank_code', 30);
            $table->string('bank_name', 80)->nullable();
            $table->string('account_number', 50);
            $table->string('account_name', 120);
            $table->string('proof_path', 255)->nullable();
            $table->string('failure_reason', 255)->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'created_at']);
            $table->index(['organization_id', 'status']);
        });

        Schema::create('landing_reports', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->nullable()->constrained('organizations')->nullOnDelete();
            $table->unsignedBigInteger('landing_page_id')->nullable();
            $table->foreignId('product_id')->nullable()->constrained('landing_products')->nullOnDelete();
            $table->string('reason', 40);                           // scam|prohibited|copyright|adult|other
            $table->text('description')->nullable();
            $table->string('reporter_email', 150)->nullable();
            $table->string('reporter_ip', 64)->nullable();
            $table->string('page_url', 500)->nullable();
            $table->string('status', 20)->default('open');         // open|reviewing|resolved|dismissed
            $table->string('resolution_note', 500)->nullable();
            $table->foreignId('handled_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('handled_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'created_at']);
        });

        Schema::table('organizations', function (Blueprint $table): void {
            $table->timestamp('landing_suspended_at')->nullable();
            $table->string('landing_suspended_reason', 255)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table): void {
            $table->dropColumn(['landing_suspended_at', 'landing_suspended_reason']);
        });
        Schema::dropIfExists('landing_reports');
        Schema::dropIfExists('landing_refunds');
        Schema::table('landing_page_orders', function (Blueprint $table): void {
            $table->dropIndex('lpo_org_created_idx');
            $table->dropIndex('lpo_org_buyer_email_idx');
            $table->dropConstrainedForeignId('coupon_id');
            $table->dropConstrainedForeignId('product_id');
            $table->dropColumn([
                'quantity', 'subtotal_amount', 'discount_amount', 'shipping_amount', 'coupon_code', 'shipping_address', 'custom_fields',
                'access_max_opens', 'access_days', 'access_open_count', 'access_last_opened_at', 'download_limit', 'download_count',
                'shipping_courier', 'tracking_number', 'shipped_at', 'inventory_reserved_at', 'inventory_released_at',
                'emails_sent_at', 'email_resend_count',
            ]);
        });
        Schema::dropIfExists('landing_coupons');
        Schema::dropIfExists('landing_products');
    }
};
