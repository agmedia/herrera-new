<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('catalog_attributes', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 120)->unique();
            $table->string('group_code', 120)->index();
            $table->string('type', 40)->default('select')->index();
            $table->boolean('is_active')->default(true)->index();
            $table->unsignedInteger('sort_order')->default(0)->index();
            $table->json('payload')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('catalog_attribute_translations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('attribute_id')->constrained('catalog_attributes')->cascadeOnDelete();
            $table->string('locale', 12)->index();
            $table->string('group_name');
            $table->string('name');
            $table->string('slug');
            $table->text('description')->nullable();
            $table->json('payload')->nullable();
            $table->timestamps();

            $table->unique(['attribute_id', 'locale'], 'catalog_attribute_locale_unique');
            $table->unique(['locale', 'slug'], 'catalog_attribute_slug_locale_unique');
        });

        Schema::create('catalog_attribute_product', function (Blueprint $table): void {
            $table->foreignId('attribute_id')->constrained('catalog_attributes')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->unsignedInteger('sort_order')->default(0)->index();
            $table->timestamps();

            $table->primary(['attribute_id', 'product_id']);
            $table->index(['product_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog_attribute_product');
        Schema::dropIfExists('catalog_attribute_translations');
        Schema::dropIfExists('catalog_attributes');
    }
};
