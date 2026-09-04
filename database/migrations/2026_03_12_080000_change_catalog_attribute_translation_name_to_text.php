<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('catalog_attribute_translations', function (Blueprint $table): void {
            $table->text('name')->change();
        });
    }

    public function down(): void
    {
        Schema::table('catalog_attribute_translations', function (Blueprint $table): void {
            $table->string('name')->change();
        });
    }
};
