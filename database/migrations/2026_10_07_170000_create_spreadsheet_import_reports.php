<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('spreadsheet_import_runs', function (Blueprint $table): void {
            $table->id();
            $table->string('status', 24)->default('preview')->index();
            $table->string('kind', 24)->default('preview');
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('options');
            $table->json('summary')->nullable();
            foreach (['total_count', 'updated_count', 'unchanged_count', 'invalid_count', 'unmatched_count', 'conflict_count'] as $column) {
                $table->unsignedInteger($column)->default(0);
            }
            $table->text('error_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });
        Schema::create('spreadsheet_import_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('run_id')->constrained('spreadsheet_import_runs')->cascadeOnDelete();
            $table->unsignedInteger('row_number');
            $table->string('identifier', 160)->nullable();
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->string('status', 24)->index();
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->string('identity_hash', 64)->nullable();
            $table->text('message')->nullable();
            $table->timestamps();
            $table->index(['run_id', 'row_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('spreadsheet_import_items');
        Schema::dropIfExists('spreadsheet_import_runs');
    }
};
