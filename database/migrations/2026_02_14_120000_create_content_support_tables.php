<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_faqs', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 120)->unique();
            $table->string('group_code', 80)->default('general')->index();
            $table->boolean('is_active')->default(true)->index();
            $table->boolean('is_featured')->default(false)->index();
            $table->unsignedInteger('sort_order')->default(0)->index();
            $table->json('payload')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('content_faq_translations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('faq_id')->constrained('content_faqs')->cascadeOnDelete();
            $table->string('locale', 12)->index();
            $table->string('question');
            $table->string('slug');
            $table->longText('answer_html')->nullable();
            $table->string('meta_title')->nullable();
            $table->text('meta_description')->nullable();
            $table->json('payload')->nullable();
            $table->timestamps();

            $table->unique(['faq_id', 'locale'], 'content_faq_locale_unique');
            $table->unique(['locale', 'slug'], 'content_faq_locale_slug_unique');
        });

        Schema::create('content_comments', function (Blueprint $table): void {
            $table->id();
            $table->nullableMorphs('commentable');
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('content_comments')->nullOnDelete();
            $table->string('author_name', 120)->nullable()->index();
            $table->string('author_email', 190)->nullable()->index();
            $table->string('locale', 12)->nullable()->index();
            $table->text('body');
            $table->unsignedTinyInteger('rating')->nullable()->index();
            $table->string('status', 24)->default('pending')->index();
            $table->boolean('is_featured')->default(false)->index();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->json('payload')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'created_at']);
            $table->index(['commentable_type', 'commentable_id', 'status'], 'content_comments_commentable_status_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_comments');
        Schema::dropIfExists('content_faq_translations');
        Schema::dropIfExists('content_faqs');
    }
};

