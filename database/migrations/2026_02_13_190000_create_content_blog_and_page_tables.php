<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_blog_posts', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 120)->unique();
            $table->boolean('is_active')->default(true)->index();
            $table->boolean('is_featured')->default(false)->index();
            $table->timestamp('published_at')->nullable()->index();
            $table->unsignedInteger('sort_order')->default(0)->index();
            $table->json('payload')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('content_blog_post_translations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('post_id')->constrained('content_blog_posts')->cascadeOnDelete();
            $table->string('locale', 12)->index();
            $table->string('title');
            $table->string('slug');
            $table->text('excerpt')->nullable();
            $table->longText('body_html')->nullable();
            $table->string('meta_title')->nullable();
            $table->text('meta_description')->nullable();
            $table->json('payload')->nullable();
            $table->timestamps();

            $table->unique(['post_id', 'locale'], 'content_blog_post_locale_unique');
            $table->unique(['locale', 'slug'], 'content_blog_post_locale_slug_unique');
        });

        Schema::create('content_info_pages', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 120)->unique();
            $table->string('layout', 80)->default('default')->index();
            $table->boolean('is_active')->default(true)->index();
            $table->boolean('show_in_footer')->default(false)->index();
            $table->timestamp('published_at')->nullable()->index();
            $table->unsignedInteger('sort_order')->default(0)->index();
            $table->json('payload')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('content_info_page_translations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('page_id')->constrained('content_info_pages')->cascadeOnDelete();
            $table->string('locale', 12)->index();
            $table->string('title');
            $table->string('slug');
            $table->text('excerpt')->nullable();
            $table->longText('body_html')->nullable();
            $table->string('meta_title')->nullable();
            $table->text('meta_description')->nullable();
            $table->json('payload')->nullable();
            $table->timestamps();

            $table->unique(['page_id', 'locale'], 'content_info_page_locale_unique');
            $table->unique(['locale', 'slug'], 'content_info_page_locale_slug_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_info_page_translations');
        Schema::dropIfExists('content_info_pages');
        Schema::dropIfExists('content_blog_post_translations');
        Schema::dropIfExists('content_blog_posts');
    }
};
