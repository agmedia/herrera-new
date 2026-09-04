<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('catalog_attributes', function (Blueprint $table): void {
            $table->dropForeign(['attribute_group_id']);
            $table->foreign('attribute_group_id')
                ->references('id')
                ->on('catalog_attribute_groups')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('catalog_attributes', function (Blueprint $table): void {
            $table->dropForeign(['attribute_group_id']);
            $table->foreign('attribute_group_id')
                ->references('id')
                ->on('catalog_attribute_groups')
                ->nullOnDelete();
        });
    }
};
