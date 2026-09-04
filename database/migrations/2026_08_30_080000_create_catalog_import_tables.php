<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('catalog_import_runs', function (Blueprint $table): void {
            $table->id();
            $table->string('source', 100)->index();
            $table->string('status', 24)->default('running')->index();
            $table->char('batch_checksum', 64);
            $table->timestamp('started_at');
            $table->timestamp('completed_at')->nullable();
            $table->json('summary')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->index(['source', 'started_at']);
        });

        Schema::create('catalog_source_mappings', function (Blueprint $table): void {
            $table->id();
            $table->string('source', 100);
            $table->string('entity_type', 32);
            $table->string('source_id', 191);
            $table->unsignedBigInteger('local_id')->nullable();
            $table->char('lifecycle_status', 1)->default('w');
            $table->char('source_checksum', 64)->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('tombstoned_at')->nullable();
            $table->foreignId('last_import_run_id')->nullable()
                ->constrained('catalog_import_runs')->nullOnDelete();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(
                ['source', 'entity_type', 'source_id'],
                'catalog_source_mapping_source_unique'
            );
            $table->unique(
                ['entity_type', 'local_id'],
                'catalog_source_mapping_local_unique'
            );
            $table->index(
                ['source', 'entity_type', 'local_id'],
                'catalog_source_mapping_local_lookup'
            );
            $table->index(['source', 'tombstoned_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog_source_mappings');
        Schema::dropIfExists('catalog_import_runs');
    }
};
