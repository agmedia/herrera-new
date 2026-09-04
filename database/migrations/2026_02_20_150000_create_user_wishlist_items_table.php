<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_wishlist_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['user_id', 'product_id'], 'user_wishlist_items_user_product_unique');
            $table->index(['user_id', 'created_at'], 'user_wishlist_items_user_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_wishlist_items');
    }
};
