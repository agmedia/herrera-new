<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('eprel_catalog_sync_runs', function (Blueprint $table): void {
            $table->id();
            $table->string('status', 20)->default('pending')->index();
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->unsignedBigInteger('category_id')->nullable();
            $table->unsignedInteger('max_products')->default(10);
            $table->unsignedBigInteger('last_product_id')->default(0);
            $table->boolean('planning_complete')->default(false);
            foreach (['total_count', 'processed_count', 'matched_count', 'not_found_count', 'skipped_count', 'failed_count'] as $field) {
                $table->unsignedInteger($field)->default(0);
            }
            $table->text('error_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });
        Schema::create('eprel_catalog_sync_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('run_id')->constrained('eprel_catalog_sync_runs')->cascadeOnDelete();
            $table->unsignedBigInteger('product_id')->index();
            $table->string('status', 24)->default('pending');
            $table->string('identity', 80);
            $table->json('criteria');
            $table->string('matched_registration', 20)->nullable();
            $table->text('message')->nullable();
            $table->timestamp('checked_at')->nullable();
            $table->timestamps();
            $table->unique(['run_id', 'product_id']);
            $table->index(['run_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('eprel_catalog_sync_items');
        Schema::dropIfExists('eprel_catalog_sync_runs');
    }
};
