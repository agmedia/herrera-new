<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_catalog_feeds', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->char('token_hash', 64)->nullable()->unique();
            $table->boolean('is_enabled')->default(false);
            $table->boolean('include_purchased_products')->default(false);
            $table->timestamp('token_issued_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
        Schema::create('customer_catalog_feed_products', function (Blueprint $table): void {
            $table->foreignId('feed_id')->constrained('customer_catalog_feeds')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->primary(['feed_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_catalog_feed_products');
        Schema::dropIfExists('customer_catalog_feeds');
    }
};
