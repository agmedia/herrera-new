<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('msan_categories', function (Blueprint $table): void {
            $table->index('name', 'msan_cat_name_idx');
        });

        Schema::table('msan_products', function (Blueprint $table): void {
            $table->index('name', 'msan_prod_name_idx');
            $table->index('model', 'msan_prod_model_idx');
        });
    }

    public function down(): void
    {
        Schema::table('msan_products', function (Blueprint $table): void {
            $table->dropIndex('msan_prod_name_idx');
            $table->dropIndex('msan_prod_model_idx');
        });

        Schema::table('msan_categories', function (Blueprint $table): void {
            $table->dropIndex('msan_cat_name_idx');
        });
    }
};
