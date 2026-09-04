<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->index(['is_active', 'id'], 'products_active_id_idx');
            $table->index(['is_active', 'base_price', 'id'], 'products_active_price_idx');
            $table->index(['is_active', 'stock_qty', 'id'], 'products_active_stock_idx');
            $table->index(['is_active', 'manufacturer_id', 'id'], 'products_active_manufacturer_idx');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->dropIndex('products_active_id_idx');
            $table->dropIndex('products_active_price_idx');
            $table->dropIndex('products_active_stock_idx');
            $table->dropIndex('products_active_manufacturer_idx');
        });
    }
};

