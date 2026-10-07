<?php

use App\Services\Front\StorefrontProductSearch;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        if (! Schema::hasIndex('product_translations', StorefrontProductSearch::NAME_FULLTEXT_INDEX)) {
            Schema::table('product_translations', function (Blueprint $table): void {
                $table->fullText('name', StorefrontProductSearch::NAME_FULLTEXT_INDEX);
            });
        }
        if (! Schema::hasIndex('product_translations', StorefrontProductSearch::NAME_PREFIX_INDEX)) {
            Schema::table('product_translations', function (Blueprint $table): void {
                $table->index(['locale', 'name', 'product_id'], StorefrontProductSearch::NAME_PREFIX_INDEX);
            });
        }
        if (! Schema::hasIndex('catalog_product_option_values', StorefrontProductSearch::VARIANT_SKU_INDEX)) {
            Schema::table('catalog_product_option_values', function (Blueprint $table): void {
                $table->index(['sku', 'is_active', 'product_id'], StorefrontProductSearch::VARIANT_SKU_INDEX);
            });
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        foreach ([
            'product_translations' => [StorefrontProductSearch::NAME_FULLTEXT_INDEX, StorefrontProductSearch::NAME_PREFIX_INDEX],
            'catalog_product_option_values' => [StorefrontProductSearch::VARIANT_SKU_INDEX],
        ] as $tableName => $indexes) {
            foreach ($indexes as $index) {
                if (Schema::hasIndex($tableName, $index)) {
                    Schema::table($tableName, fn (Blueprint $table) => $table->dropIndex($index));
                }
            }
        }
    }
};
