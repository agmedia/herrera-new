<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_clearances', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->unique()->constrained('products')->cascadeOnDelete();
            $table->string('source', 50);
            $table->string('source_fingerprint', 64);
            $table->date('coverage_from');
            $table->date('snapshot_date');
            $table->date('last_outgoing_at')->nullable();
            $table->boolean('confirmed_company_scope')->default(false);
            $table->string('status', 30)->default('observed')->index();
            $table->decimal('base_price_at_activation', 16, 4)->nullable();
            $table->foreignId('category_id')->nullable()->constrained('categories')->nullOnDelete();
            $table->boolean('category_added')->default(false);
            $table->foreignId('customer_group_id')->nullable()->constrained('customer_groups')->nullOnDelete();
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('retired_at')->nullable();
            $table->timestamp('manually_reactivated_at')->nullable();
            $table->foreignId('imported_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_clearances');
    }
};
