<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_catalog_items', function (Blueprint $table): void {
            $table->id();
            $table->string('supplier', 20);
            $table->string('external_id', 160);
            $table->string('sku', 160);
            $table->string('barcode', 80)->nullable();
            $table->string('name');
            $table->string('category_key', 160)->default('uncategorized');
            $table->string('category_name')->default('Bez kategorije');
            $table->decimal('source_price', 16, 4)->nullable();
            $table->string('currency', 3)->nullable();
            $table->unsignedInteger('quantity')->default(0);
            $table->longText('description')->nullable();
            $table->json('images')->nullable();
            $table->json('specifications')->nullable();
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->boolean('is_current')->default(true);
            $table->string('status', 20)->default('new');
            $table->text('message')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();
            $table->unique(['supplier', 'external_id']);
            $table->index(['supplier', 'is_current', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_catalog_items');
    }
};
