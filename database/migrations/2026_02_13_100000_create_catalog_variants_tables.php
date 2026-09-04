<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('catalog_product_variants', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->string('code', 120)->unique();
            $table->string('sku', 120)->nullable()->unique();
            $table->string('name', 191)->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->decimal('override_price', 12, 2)->nullable()->index();
            $table->integer('stock_qty')->default(0);
            $table->unsignedInteger('sort_order')->default(0)->index();
            $table->string('combination_hash', 64)->default('')->index();
            $table->json('payload')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['product_id', 'combination_hash'], 'catalog_variant_product_combination_unique');
            $table->index(['product_id', 'sort_order'], 'catalog_variant_product_sort_index');
        });

        Schema::create('catalog_product_variant_option_values', function (Blueprint $table): void {
            $table->foreignId('variant_id')->constrained('catalog_product_variants')->cascadeOnDelete();
            $table->foreignId('option_id')->constrained('catalog_options')->cascadeOnDelete();
            $table->foreignId('option_value_id')->constrained('catalog_option_values')->cascadeOnDelete();
            $table->timestamps();

            $table->primary(['variant_id', 'option_id'], 'catalog_variant_option_primary');
            $table->unique(['variant_id', 'option_value_id'], 'catalog_variant_value_unique');
            $table->index(['option_value_id', 'variant_id'], 'catalog_variant_value_variant_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog_product_variant_option_values');
        Schema::dropIfExists('catalog_product_variants');
    }
};
