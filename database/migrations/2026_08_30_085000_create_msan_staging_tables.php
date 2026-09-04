<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('msan_sync_runs', function (Blueprint $table): void {
            $table->id();
            $table->string('kind', 32)->index();
            $table->string('status', 24)->default('pending')->index();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedTinyInteger('progress')->default(0);
            $table->unsignedInteger('total_count')->default(0);
            $table->unsignedInteger('processed_count')->default(0);
            $table->unsignedInteger('succeeded_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->unsignedInteger('skipped_count')->default(0);
            $table->json('summary')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['kind', 'started_at'], 'msan_runs_kind_started_idx');
            $table->index(['status', 'created_at'], 'msan_runs_status_created_idx');
        });

        Schema::create('msan_endpoint_states', function (Blueprint $table): void {
            $table->id();
            $table->string('endpoint', 100)->unique();
            $table->timestamp('last_attempt_at')->nullable();
            $table->timestamp('last_success_at')->nullable()->index();
            $table->timestamp('next_allowed_at')->nullable()->index();
            $table->text('last_error')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('msan_categories', function (Blueprint $table): void {
            $table->id();
            $table->string('external_id', 191)->unique();
            $table->string('name');
            $table->string('parent_external_id', 191)->nullable()->index();
            $table->text('path')->nullable();
            $table->unsignedInteger('product_count')->default(0);
            $table->timestamp('last_seen_at')->nullable()->index();
            $table->boolean('is_stale')->default(false)->index();
            $table->json('payload')->nullable();
            $table->timestamps();

            $table->index(['is_stale', 'parent_external_id'], 'msan_cat_stale_parent_idx');
        });

        Schema::create('msan_category_mappings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('msan_category_id')->unique()
                ->constrained('msan_categories')->cascadeOnDelete();
            $table->foreignId('local_category_id')->nullable()
                ->constrained('categories')->nullOnDelete();
            $table->string('status', 16)->default('unmapped')->index();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['status', 'local_category_id'], 'msan_map_status_local_idx');
        });

        Schema::create('msan_products', function (Blueprint $table): void {
            $table->id();
            $table->string('external_code', 191)->unique();

            $table->string('name')->nullable();
            $table->string('product_type')->nullable()->index();
            $table->string('brand')->nullable()->index();
            $table->string('model', 120)->nullable();
            $table->string('part_number', 120)->nullable()->index();
            $table->unsignedSmallInteger('warranty_months')->nullable();
            $table->decimal('package_weight_kg', 10, 3)->nullable();
            $table->decimal('package_length_cm', 10, 3)->nullable();
            $table->decimal('package_width_cm', 10, 3)->nullable();
            $table->decimal('package_height_cm', 10, 3)->nullable();
            $table->longText('technical_description')->nullable();
            $table->longText('marketing_description')->nullable();
            $table->string('image_url', 2048)->nullable();
            $table->json('barcodes')->nullable();

            $table->string('currency_code', 3)->default('EUR');
            $table->decimal('list_price', 12, 4)->nullable();
            $table->decimal('discount_percent', 7, 4)->nullable();
            $table->decimal('partner_price', 12, 4)->nullable();
            $table->decimal('recommended_retail_price', 12, 4)->nullable();
            $table->unsignedTinyInteger('availability_level')->nullable()->index();
            $table->boolean('on_promotion')->default(false)->index();

            $table->boolean('selected')->default(false)->index();
            $table->boolean('is_stale')->default(false)->index();
            $table->foreignId('local_product_id')->nullable()->unique()
                ->constrained('products')->nullOnDelete();
            $table->string('match_status', 24)->default('unmatched')->index();
            $table->string('import_status', 24)->default('pending')->index();

            $table->char('catalog_checksum', 64)->nullable();
            $table->char('price_checksum', 64)->nullable();
            $table->char('availability_checksum', 64)->nullable();
            $table->timestamp('catalog_synced_at')->nullable();
            $table->timestamp('price_synced_at')->nullable();
            $table->timestamp('availability_synced_at')->nullable();
            $table->timestamp('last_seen_at')->nullable()->index();
            $table->timestamp('last_imported_at')->nullable();
            $table->text('last_error')->nullable();
            $table->json('payload')->nullable();
            $table->timestamps();

            $table->index(['selected', 'import_status'], 'msan_prod_selected_import_idx');
            $table->index(['match_status', 'selected'], 'msan_prod_match_selected_idx');
            $table->index(['is_stale', 'availability_level'], 'msan_prod_stale_avail_idx');
        });

        Schema::create('msan_product_categories', function (Blueprint $table): void {
            $table->foreignId('msan_product_id')->constrained('msan_products')->cascadeOnDelete();
            $table->foreignId('msan_category_id')->constrained('msan_categories')->cascadeOnDelete();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->primary(['msan_product_id', 'msan_category_id']);
            $table->index(['msan_category_id', 'msan_product_id'], 'msan_pc_category_product_idx');
            $table->index('last_seen_at', 'msan_pc_last_seen_idx');
        });

        Schema::create('msan_import_run_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('msan_sync_run_id')->constrained('msan_sync_runs')->cascadeOnDelete();
            $table->foreignId('msan_product_id')->constrained('msan_products')->cascadeOnDelete();
            $table->string('status', 16)->default('pending')->index();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->text('error_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['msan_sync_run_id', 'msan_product_id'], 'msan_import_run_product_unique');
            $table->index(['msan_sync_run_id', 'status'], 'msan_import_run_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('msan_import_run_items');
        Schema::dropIfExists('msan_product_categories');
        Schema::dropIfExists('msan_products');
        Schema::dropIfExists('msan_category_mappings');
        Schema::dropIfExists('msan_categories');
        Schema::dropIfExists('msan_endpoint_states');
        Schema::dropIfExists('msan_sync_runs');
    }
};
