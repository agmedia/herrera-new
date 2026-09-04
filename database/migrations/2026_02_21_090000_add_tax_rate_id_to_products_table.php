<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->foreignId('tax_rate_id')
                ->nullable()
                ->after('manufacturer_id')
                ->constrained('tax_rates')
                ->nullOnDelete()
                ->index();
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('tax_rate_id');
        });
    }
};

