<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('catalog_group_discount_rules', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('price_catalog_id')->constrained('catalog_price_catalogs')->cascadeOnDelete();
            $table->string('name', 191);
            $table->decimal('percent', 7, 4);
            $table->json('customer_group_ids');
            $table->json('manufacturer_ids');
            $table->json('category_ids');
            $table->boolean('include_descendants')->default(true);
            $table->json('excluded_product_ids');
            $table->dateTime('starts_at')->nullable();
            $table->dateTime('ends_at')->nullable();
            $table->integer('priority')->default(0);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('materialized_product_count')->default(0);
            $table->unsignedInteger('materialized_entry_count')->default(0);
            $table->dateTime('materialized_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['price_catalog_id', 'is_active', 'priority'], 'catalog_group_discounts_lookup');
        });
        Schema::table('catalog_price_entries', function (Blueprint $table): void {
            $table->foreignId('discount_rule_id')->nullable()->constrained('catalog_group_discount_rules')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('catalog_price_entries', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('discount_rule_id');
        });
        Schema::dropIfExists('catalog_group_discount_rules');
    }
};
