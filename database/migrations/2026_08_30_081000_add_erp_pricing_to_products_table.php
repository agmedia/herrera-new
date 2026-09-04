<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->decimal('erp_gross_list_price', 12, 4)->nullable()->after('base_price');
            $table->decimal('erp_cash_discount_percent', 7, 4)->nullable()->after('erp_gross_list_price');
            $table->decimal('erp_cash_selling_price', 12, 4)->nullable()->after('erp_cash_discount_percent');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->dropColumn([
                'erp_gross_list_price',
                'erp_cash_discount_percent',
                'erp_cash_selling_price',
            ]);
        });
    }
};
