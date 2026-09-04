<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('catalog_options', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 120)->unique();
            $table->string('type', 40)->default('select')->index();
            $table->boolean('is_active')->default(true)->index();
            $table->unsignedInteger('sort_order')->default(0)->index();
            $table->json('payload')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('catalog_option_translations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('option_id')->constrained('catalog_options')->cascadeOnDelete();
            $table->string('locale', 12)->index();
            $table->string('name');
            $table->string('slug');
            $table->text('description')->nullable();
            $table->json('payload')->nullable();
            $table->timestamps();

            $table->unique(['option_id', 'locale'], 'catalog_option_locale_unique');
            $table->unique(['locale', 'slug'], 'catalog_option_locale_slug_unique');
        });

        Schema::create('catalog_option_values', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('option_id')->constrained('catalog_options')->cascadeOnDelete();
            $table->string('code', 120);
            $table->boolean('is_active')->default(true)->index();
            $table->unsignedInteger('sort_order')->default(0)->index();
            $table->json('payload')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['option_id', 'code'], 'catalog_option_value_code_unique');
        });

        Schema::create('catalog_option_value_translations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('option_value_id')->constrained('catalog_option_values')->cascadeOnDelete();
            $table->string('locale', 12)->index();
            $table->string('name');
            $table->string('slug');
            $table->json('payload')->nullable();
            $table->timestamps();

            $table->unique(['option_value_id', 'locale'], 'catalog_option_value_locale_unique');
            $table->unique(['locale', 'slug'], 'catalog_option_value_locale_slug_unique');
        });

        Schema::create('catalog_option_product', function (Blueprint $table): void {
            $table->foreignId('option_id')->constrained('catalog_options')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->boolean('is_required')->default(false)->index();
            $table->unsignedInteger('sort_order')->default(0)->index();
            $table->timestamps();

            $table->primary(['option_id', 'product_id']);
            $table->index(['product_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog_option_product');
        Schema::dropIfExists('catalog_option_value_translations');
        Schema::dropIfExists('catalog_option_values');
        Schema::dropIfExists('catalog_option_translations');
        Schema::dropIfExists('catalog_options');
    }
};
