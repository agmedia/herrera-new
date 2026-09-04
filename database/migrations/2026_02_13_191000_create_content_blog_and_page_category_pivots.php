<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_blog_post_category', function (Blueprint $table): void {
            $table->foreignId('post_id')->constrained('content_blog_posts')->cascadeOnDelete();
            $table->foreignId('category_id')->constrained('categories')->cascadeOnDelete();
            $table->unsignedInteger('sort_order')->default(0)->index();
            $table->boolean('is_primary')->default(false)->index();
            $table->timestamps();

            $table->primary(['post_id', 'category_id']);
            $table->index(['category_id', 'sort_order']);
        });

        Schema::create('content_info_page_category', function (Blueprint $table): void {
            $table->foreignId('page_id')->constrained('content_info_pages')->cascadeOnDelete();
            $table->foreignId('category_id')->constrained('categories')->cascadeOnDelete();
            $table->unsignedInteger('sort_order')->default(0)->index();
            $table->boolean('is_primary')->default(false)->index();
            $table->timestamps();

            $table->primary(['page_id', 'category_id']);
            $table->index(['category_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_info_page_category');
        Schema::dropIfExists('content_blog_post_category');
    }
};
