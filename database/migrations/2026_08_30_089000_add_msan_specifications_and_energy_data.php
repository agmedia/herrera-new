<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->json('technical_specifications')->nullable()->after('shipping_labels');
            $table->boolean('energy_label_required')->default(false)->after('technical_specifications')->index();
            $table->string('energy_efficiency_class', 16)->nullable()->after('energy_label_required');
            $table->string('energy_efficiency_scale', 32)->nullable()->after('energy_efficiency_class');
            $table->string('eprel_registration_number', 64)->nullable()->after('energy_efficiency_scale')->index();
            $table->string('eprel_product_group', 100)->nullable()->after('eprel_registration_number')->index();
            $table->string('eprel_energy_label_image', 255)->nullable()->after('eprel_product_group');
            $table->string('energy_label_url', 2048)->nullable()->after('eprel_energy_label_image');
            $table->string('product_information_sheet_url', 2048)->nullable()->after('energy_label_url');
            $table->timestamp('energy_data_synced_at')->nullable()->after('product_information_sheet_url')->index();
        });

        Schema::table('msan_products', function (Blueprint $table): void {
            $table->char('specifications_checksum', 64)->nullable()->after('availability_checksum');
            $table->timestamp('specifications_synced_at')->nullable()->after('availability_synced_at');
        });

        Schema::table('msan_category_mappings', function (Blueprint $table): void {
            $table->string('eprel_product_group', 100)->nullable()->after('status')->index();
            $table->string('energy_requirement', 20)->default('inherit')->after('eprel_product_group')->index();
        });

        Schema::create('msan_specification_definitions', function (Blueprint $table): void {
            $table->id();
            $table->char('source_key', 64)->unique();
            $table->string('group_name');
            $table->string('item_name');
            $table->string('measure', 100)->nullable();
            $table->string('display_group_name')->nullable();
            $table->string('display_item_name')->nullable();
            $table->boolean('source_for_filter')->default(false)->index();
            $table->boolean('import_enabled')->default(true)->index();
            $table->boolean('use_as_filter')->default(false)->index();
            $table->string('data_role', 32)->default('specification')->index();
            $table->json('sample_values')->nullable();
            $table->unsignedInteger('product_count')->default(0);
            $table->timestamp('last_seen_at')->nullable()->index();
            $table->boolean('is_stale')->default(false)->index();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['is_stale', 'import_enabled'], 'msan_spec_defs_stale_import_idx');
        });

        Schema::create('msan_specification_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('msan_sync_run_id')->unique()->constrained('msan_sync_runs')->cascadeOnDelete();
            $table->string('status', 20)->default('candidate')->index();
            $table->string('source', 20);
            $table->unsignedBigInteger('source_bytes')->default(0);
            $table->char('source_checksum', 64)->nullable();
            $table->unsignedInteger('row_count')->default(0);
            $table->unsignedInteger('relevant_row_count')->default(0);
            $table->unsignedInteger('product_count')->default(0);
            $table->timestamp('activated_at')->nullable()->index();
            $table->timestamps();
        });

        Schema::create('msan_product_specifications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('snapshot_id')->constrained('msan_specification_snapshots')->cascadeOnDelete();
            $table->foreignId('msan_product_id')->constrained('msan_products')->cascadeOnDelete();
            $table->foreignId('definition_id')->constrained('msan_specification_definitions')->cascadeOnDelete();
            $table->json('values');
            $table->unsignedSmallInteger('item_order')->default(0);
            $table->char('checksum', 64);
            $table->timestamp('last_seen_at')->nullable()->index();
            $table->timestamps();

            $table->unique(['snapshot_id', 'msan_product_id', 'definition_id'], 'msan_product_spec_unique');
            $table->index(['definition_id', 'msan_product_id'], 'msan_spec_product_idx');
        });

        Schema::create('catalog_product_specifications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->string('source', 32)->index();
            $table->char('source_key', 64);
            $table->string('group_name');
            $table->string('item_name');
            $table->json('values');
            $table->string('measure', 100)->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->json('payload')->nullable();
            $table->timestamps();

            $table->unique(['product_id', 'source', 'source_key'], 'catalog_product_spec_source_unique');
            $table->index(['product_id', 'sort_order'], 'catalog_product_spec_order_idx');
        });

        Schema::create('product_energy_declarations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->string('context_code', 120);
            $table->string('label')->nullable();
            $table->string('energy_class', 16)->nullable();
            $table->string('scale_min', 16)->nullable();
            $table->string('scale_max', 16)->nullable();
            $table->string('eprel_registration_number', 64)->nullable()->index();
            $table->string('eprel_product_group', 100)->nullable()->index();
            $table->string('energy_label_image', 255)->nullable();
            $table->string('energy_label_url', 2048)->nullable();
            $table->string('product_information_sheet_url', 2048)->nullable();
            $table->boolean('is_primary')->default(false)->index();
            $table->string('source', 32)->default('manual')->index();
            $table->json('payload')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->unique(['product_id', 'context_code'], 'product_energy_context_unique');
            $table->index(['product_id', 'is_primary'], 'product_energy_primary_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_energy_declarations');
        Schema::dropIfExists('catalog_product_specifications');
        Schema::dropIfExists('msan_product_specifications');
        Schema::dropIfExists('msan_specification_snapshots');
        Schema::dropIfExists('msan_specification_definitions');

        Schema::table('msan_category_mappings', function (Blueprint $table): void {
            $table->dropIndex(['eprel_product_group']);
            $table->dropIndex(['energy_requirement']);
            $table->dropColumn(['eprel_product_group', 'energy_requirement']);
        });

        Schema::table('msan_products', function (Blueprint $table): void {
            $table->dropColumn(['specifications_checksum', 'specifications_synced_at']);
        });

        Schema::table('products', function (Blueprint $table): void {
            $table->dropIndex(['energy_label_required']);
            $table->dropIndex(['eprel_registration_number']);
            $table->dropIndex(['eprel_product_group']);
            $table->dropIndex(['energy_data_synced_at']);
            $table->dropColumn([
                'technical_specifications',
                'energy_label_required',
                'energy_efficiency_class',
                'energy_efficiency_scale',
                'eprel_registration_number',
                'eprel_product_group',
                'eprel_energy_label_image',
                'energy_label_url',
                'product_information_sheet_url',
                'energy_data_synced_at',
            ]);
        });
    }
};
