<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('catalog_manufacturers', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 120)->unique();
            $table->boolean('is_active')->default(true)->index();
            $table->boolean('is_featured')->default(false)->index();
            $table->unsignedInteger('sort_order')->default(0)->index();
            $table->json('payload')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('catalog_manufacturer_translations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('manufacturer_id')->constrained('catalog_manufacturers')->cascadeOnDelete();
            $table->string('locale', 12)->index();
            $table->string('name');
            $table->string('slug');
            $table->text('description')->nullable();
            $table->string('meta_title')->nullable();
            $table->text('meta_description')->nullable();
            $table->json('payload')->nullable();
            $table->timestamps();

            $table->unique(['manufacturer_id', 'locale'], 'catalog_manufacturer_locale_unique');
            $table->unique(['locale', 'slug'], 'catalog_manufacturer_slug_locale_unique');
        });

        Schema::table('products', function (Blueprint $table): void {
            $table->foreignId('manufacturer_id')
                ->nullable()
                ->constrained('catalog_manufacturers')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('manufacturer_id');
        });

        Schema::dropIfExists('catalog_manufacturer_translations');
        Schema::dropIfExists('catalog_manufacturers');
    }
};
