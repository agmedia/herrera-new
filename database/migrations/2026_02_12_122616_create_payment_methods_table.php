<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('payment_methods', function (Blueprint $table) {
            $table->id();
            $table->string('code', 60)->unique();
            $table->string('name');
            $table->string('provider')->nullable();
            $table->foreignId('geo_zone_id')->nullable()->constrained('geo_zones')->nullOnDelete();
            $table->text('description')->nullable();
            $table->enum('fee_type', ['fixed', 'percent'])->default('fixed');
            $table->decimal('fee_value', 10, 2)->default(0);
            $table->decimal('min_subtotal', 10, 2)->nullable();
            $table->decimal('max_subtotal', 10, 2)->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->json('settings')->nullable();
            $table->timestamps();

            $table->index(['is_active', 'sort_order']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payment_methods');
    }
};
