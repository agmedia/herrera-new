<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_catalogs', function (Blueprint $table): void {
            $table->id();
            $table->string('code')->unique();
            $table->string('brand', 120);
            $table->string('title');
            $table->text('description')->nullable();
            $table->unsignedSmallInteger('year')->nullable();
            $table->string('language', 80)->nullable();
            $table->string('source_type', 20)->default('external');
            $table->string('format', 20)->default('pdf');
            $table->text('external_url')->nullable();
            $table->text('source_url')->nullable();
            $table->string('document_path')->nullable();
            $table->string('document_name')->nullable();
            $table->unsignedBigInteger('document_size')->nullable();
            $table->string('cover_path')->nullable();
            $table->text('cover_url')->nullable();
            $table->boolean('is_active')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['is_active', 'sort_order']);
            $table->index('brand');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_catalogs');
    }
};
