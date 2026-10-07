<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('catalog_legacy_group_discount_references', function (Blueprint $table): void {
            $table->id();
            $table->string('source_system', 80);
            $table->string('source_snapshot', 191);
            $table->string('source_catalog_checksum', 128);
            $table->string('source_key', 100);
            $table->string('source_type', 30);
            $table->unsignedBigInteger('source_sale_id')->nullable();
            $table->unsignedBigInteger('source_template_id')->nullable();
            $table->char('definition_checksum', 64);
            $table->string('name', 191);
            $table->decimal('percent', 7, 4)->nullable();
            $table->json('customer_group_ids');
            $table->json('manufacturer_ids');
            $table->json('category_ids');
            $table->json('excluded_product_ids');
            $table->boolean('include_descendants')->default(true);
            $table->dateTime('starts_at')->nullable();
            $table->dateTime('ends_at')->nullable();
            $table->integer('priority')->default(0);
            $table->boolean('is_supported')->default(false);
            $table->json('warnings');
            $table->json('source_flags');
            $table->json('definition');
            $table->timestamps();
            $table->unique(['source_snapshot', 'source_catalog_checksum', 'source_key'], 'legacy_discount_reference_source_unique');
            $table->index(['source_snapshot', 'source_catalog_checksum'], 'legacy_discount_reference_snapshot');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog_legacy_group_discount_references');
    }
};
