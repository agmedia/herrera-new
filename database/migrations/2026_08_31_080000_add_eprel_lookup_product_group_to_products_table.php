<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->string('eprel_lookup_product_group', 100)
                ->nullable()
                ->after('eprel_product_group')
                ->index();
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->dropIndex(['eprel_lookup_product_group']);
            $table->dropColumn('eprel_lookup_product_group');
        });
    }
};
