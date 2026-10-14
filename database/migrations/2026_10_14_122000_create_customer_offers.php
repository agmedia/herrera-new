<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_offers', function (Blueprint $table): void {
            $table->id();
            $table->string('number')->unique();
            $table->foreignId('customer_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title');
            $table->string('status')->default('draft')->index();
            $table->string('locale', 10)->default('hr');
            $table->string('currency_code', 3)->default('EUR');
            $table->date('valid_until');
            $table->text('intro')->nullable();
            $table->text('notes')->nullable();
            $table->json('customer_snapshot');
            $table->decimal('net_total', 16, 4)->default(0);
            $table->decimal('tax_total', 16, 4)->default(0);
            $table->decimal('gross_total', 16, 4)->default(0);
            $table->timestamp('priced_at');
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
        });
        Schema::create('customer_offer_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_offer_id')->constrained('customer_offers')->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->foreignId('product_option_value_id')->nullable()->constrained('catalog_product_option_values')->nullOnDelete();
            $table->string('name');
            $table->string('sku')->nullable();
            $table->string('code')->nullable();
            $table->string('option_label')->nullable();
            $table->text('description')->nullable();
            $table->text('image_url')->nullable();
            $table->unsignedInteger('quantity');
            $table->string('unit', 20)->default('kom');
            $table->decimal('unit_net', 16, 4);
            $table->decimal('unit_gross', 16, 4);
            $table->decimal('tax_rate', 10, 4)->default(0);
            $table->string('tax_type', 20)->default('percent');
            $table->decimal('line_net', 16, 4);
            $table->decimal('line_tax', 16, 4);
            $table->decimal('line_gross', 16, 4);
            $table->json('pricing_snapshot');
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });
        Schema::create('customer_offer_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_offer_id')->constrained('customer_offers')->cascadeOnDelete();
            $table->foreignId('sent_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('recipient');
            $table->string('status');
            $table->string('error_code')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_offer_deliveries');
        Schema::dropIfExists('customer_offer_items');
        Schema::dropIfExists('customer_offers');
    }
};
