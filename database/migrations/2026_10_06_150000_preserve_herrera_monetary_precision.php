<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->decimal('base_price', 20, 4)->default(0)->change();
        });
        Schema::table('catalog_product_option_values', function (Blueprint $table): void {
            $table->decimal('price_override', 20, 4)->nullable()->change();
        });
        Schema::table('orders', function (Blueprint $table): void {
            foreach (['subtotal', 'shipping_total', 'payment_fee_total', 'discount_total', 'tax_total', 'grand_total'] as $column) {
                $table->decimal($column, 20, 4)->default(0)->change();
            }
        });
        Schema::table('order_items', function (Blueprint $table): void {
            foreach (['unit_price', 'discount_amount', 'tax_amount', 'line_total'] as $column) {
                $table->decimal($column, 20, 4)->default(0)->change();
            }
        });
        Schema::table('order_totals', function (Blueprint $table): void {
            $table->decimal('value', 20, 4)->default(0)->change();
        });
    }

    public function down(): void
    {
        // Deliberately retain the wider columns: narrowing would destroy imported financial history.
    }
};
