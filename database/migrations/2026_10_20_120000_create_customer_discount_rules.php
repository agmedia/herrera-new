<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_discount_rules', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('name', 191);
            $table->decimal('percent', 7, 4);
            $table->json('manufacturer_ids');
            $table->json('category_ids');
            $table->boolean('include_descendants')->default(true);
            $table->json('excluded_product_ids');
            $table->dateTime('starts_at')->nullable();
            $table->dateTime('ends_at')->nullable();
            $table->integer('priority')->default(0);
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['user_id', 'is_active', 'priority'], 'customer_discounts_lookup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_discount_rules');
    }
};
