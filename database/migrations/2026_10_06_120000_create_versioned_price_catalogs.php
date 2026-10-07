<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('catalog_price_catalogs', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 191);
            $table->string('status', 20)->default('draft')->index();
            $table->char('currency_code', 3)->default('EUR');
            $table->string('source_system', 80)->nullable();
            $table->string('source_snapshot', 191)->nullable();
            $table->string('source_checksum', 128)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('activated_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('activated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('catalog_price_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('price_catalog_id')->constrained('catalog_price_catalogs')->cascadeOnDelete();
            $table->string('source_key', 160);
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->string('kind', 20);
            $table->foreignId('customer_group_id')->nullable()->constrained('customer_groups')->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->unsignedInteger('minimum_quantity')->default(1);
            $table->decimal('price', 20, 4);
            $table->integer('priority')->default(0);
            $table->dateTime('starts_at')->nullable();
            $table->dateTime('ends_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->json('payload')->nullable();
            $table->timestamps();
            $table->unique(['price_catalog_id', 'source_key'], 'price_entries_source_unique');
            $table->index(['price_catalog_id', 'product_id', 'customer_group_id', 'kind', 'is_active'], 'price_entries_group_lookup');
            $table->index(['price_catalog_id', 'product_id', 'user_id', 'kind', 'is_active'], 'price_entries_customer_lookup');
        });

        // A single locked pointer makes switching a full catalog atomic, also on MySQL.
        Schema::create('catalog_price_catalog_state', function (Blueprint $table): void {
            $table->unsignedTinyInteger('id')->primary();
            $table->foreignId('price_catalog_id')->nullable()->constrained('catalog_price_catalogs')->restrictOnDelete();
        });
        DB::table('catalog_price_catalog_state')->insert(['id' => 1, 'price_catalog_id' => null]);

        Schema::create('catalog_price_catalog_audits', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('price_catalog_id')->constrained('catalog_price_catalogs')->restrictOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('event', 40);
            $table->json('payload')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog_price_catalog_audits');
        Schema::dropIfExists('catalog_price_catalog_state');
        Schema::dropIfExists('catalog_price_entries');
        Schema::dropIfExists('catalog_price_catalogs');
    }
};
