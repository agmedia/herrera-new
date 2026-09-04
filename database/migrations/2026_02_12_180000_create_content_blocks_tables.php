<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('content_blocks', function (Blueprint $table): void {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->string('type')->index();
            $table->boolean('is_active')->default(true)->index();
            $table->json('payload')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('content_block_translations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('content_block_id')
                ->constrained('content_blocks')
                ->cascadeOnDelete();
            $table->string('locale', 12)->index();
            $table->string('title')->nullable();
            $table->text('subtitle')->nullable();
            $table->longText('body_html')->nullable();
            $table->string('cta_label')->nullable();
            $table->string('cta_url')->nullable();
            $table->json('payload')->nullable();
            $table->timestamps();

            $table->unique(['content_block_id', 'locale'], 'content_block_locale_unique');
        });

        Schema::create('content_block_slots', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('content_block_id')
                ->constrained('content_blocks')
                ->cascadeOnDelete();
            $table->string('placement')->index();
            $table->string('target_type')->nullable()->index();
            $table->string('target_ref')->nullable()->index();
            $table->unsignedInteger('sort_order')->default(0)->index();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamp('starts_at')->nullable()->index();
            $table->timestamp('ends_at')->nullable()->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['placement', 'target_type', 'target_ref'], 'content_slot_placement_target_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('content_block_slots');
        Schema::dropIfExists('content_block_translations');
        Schema::dropIfExists('content_blocks');
    }
};

