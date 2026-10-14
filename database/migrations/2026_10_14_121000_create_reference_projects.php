<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_reference_projects', function (Blueprint $table): void {
            $table->id();
            $table->string('location')->nullable();
            $table->unsignedSmallInteger('completed_year')->nullable();
            $table->string('project_type', 120)->nullable();
            $table->boolean('is_active')->default(false);
            $table->timestamp('published_at')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['is_active', 'published_at', 'sort_order'], 'reference_projects_visibility');
        });

        Schema::create('content_reference_project_translations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('project_id')->constrained('content_reference_projects')->cascadeOnDelete();
            $table->string('locale', 12);
            $table->string('title');
            $table->string('slug', 191);
            $table->text('excerpt')->nullable();
            $table->text('description')->nullable();
            $table->timestamps();
            $table->unique(['project_id', 'locale'], 'reference_project_locale_unique');
            $table->unique(['locale', 'slug'], 'reference_project_slug_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_reference_project_translations');
        Schema::dropIfExists('content_reference_projects');
    }
};
