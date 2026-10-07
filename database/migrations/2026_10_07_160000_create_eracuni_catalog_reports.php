<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('eracuni_catalog_runs', function (Blueprint $table): void {
            $table->id();
            $table->string('kind', 20);
            $table->string('status', 20)->default('running');
            $table->unsignedBigInteger('actor_id')->nullable();
            foreach (['fetched_count', 'eligible_count', 'created_count', 'updated_count', 'unchanged_count', 'skipped_count'] as $field) {
                $table->unsignedInteger($field)->default(0);
            }
            $table->text('error_message')->nullable();
            $table->json('summary')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->index(['kind', 'status', 'id']);
        });
        Schema::create('eracuni_catalog_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('run_id')->constrained('eracuni_catalog_runs')->cascadeOnDelete();
            $table->unsignedBigInteger('product_id')->nullable()->index();
            $table->string('identifier', 120);
            $table->string('name');
            $table->string('status', 20);
            $table->json('source_payload')->nullable();
            $table->json('plan')->nullable();
            $table->string('message')->nullable();
            $table->timestamps();
            $table->index(['run_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('eracuni_catalog_items');
        Schema::dropIfExists('eracuni_catalog_runs');
    }
};
