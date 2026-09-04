<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table): void {
            $table->id();
            $table->string('order_number', 40)->unique();
            $table->foreignId('status_id')->nullable()->constrained('order_statuses')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('source', 32)->default('web')->index();
            $table->string('locale', 12)->nullable();
            $table->string('currency_code', 8)->default('EUR');
            $table->decimal('currency_rate', 12, 6)->default(1);

            $table->string('customer_name', 191);
            $table->string('customer_email', 191)->index();
            $table->string('customer_phone', 80)->nullable();

            $table->string('billing_first_name', 120)->nullable();
            $table->string('billing_last_name', 120)->nullable();
            $table->string('billing_company', 191)->nullable();
            $table->string('billing_oib', 60)->nullable();
            $table->string('billing_vat_id', 60)->nullable();
            $table->string('billing_address_line_1', 191)->nullable();
            $table->string('billing_address_line_2', 191)->nullable();
            $table->string('billing_postal_code', 32)->nullable();
            $table->string('billing_city', 120)->nullable();
            $table->string('billing_state', 120)->nullable();
            $table->string('billing_country_code', 2)->nullable();

            $table->string('shipping_first_name', 120)->nullable();
            $table->string('shipping_last_name', 120)->nullable();
            $table->string('shipping_company', 191)->nullable();
            $table->string('shipping_oib', 60)->nullable();
            $table->string('shipping_vat_id', 60)->nullable();
            $table->string('shipping_address_line_1', 191)->nullable();
            $table->string('shipping_address_line_2', 191)->nullable();
            $table->string('shipping_postal_code', 32)->nullable();
            $table->string('shipping_city', 120)->nullable();
            $table->string('shipping_state', 120)->nullable();
            $table->string('shipping_country_code', 2)->nullable();

            $table->string('payment_method_code', 60)->nullable();
            $table->string('payment_method_name', 191)->nullable();
            $table->string('shipping_method_code', 60)->nullable();
            $table->string('shipping_method_name', 191)->nullable();

            $table->unsignedInteger('item_qty')->default(0);
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('shipping_total', 12, 2)->default(0);
            $table->decimal('payment_fee_total', 12, 2)->default(0);
            $table->decimal('discount_total', 12, 2)->default(0);
            $table->decimal('tax_total', 12, 2)->default(0);
            $table->decimal('grand_total', 12, 2)->default(0)->index();

            $table->text('customer_note')->nullable();
            $table->text('admin_note')->nullable();
            $table->json('payload')->nullable();

            $table->timestamp('placed_at')->nullable()->index();
            $table->timestamp('paid_at')->nullable()->index();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['status_id', 'placed_at']);
        });

        Schema::create('order_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->foreignId('product_option_value_id')->nullable()->constrained('catalog_product_option_values')->nullOnDelete();
            $table->string('sku', 120)->nullable()->index();
            $table->string('code', 120)->nullable()->index();
            $table->string('name');
            $table->decimal('unit_price', 12, 2)->default(0);
            $table->decimal('discount_amount', 12, 2)->default(0);
            $table->decimal('tax_rate', 7, 4)->default(0);
            $table->decimal('tax_amount', 12, 2)->default(0);
            $table->unsignedInteger('quantity')->default(1);
            $table->decimal('line_total', 12, 2)->default(0);
            $table->unsignedInteger('sort_order')->default(0)->index();
            $table->json('payload')->nullable();
            $table->timestamps();

            $table->index(['order_id', 'sort_order']);
        });

        Schema::create('order_totals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->string('code', 60)->index();
            $table->string('title', 191);
            $table->decimal('value', 12, 2)->default(0);
            $table->unsignedInteger('sort_order')->default(0)->index();
            $table->json('payload')->nullable();
            $table->timestamps();

            $table->index(['order_id', 'sort_order']);
        });

        Schema::create('order_history', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->foreignId('from_status_id')->nullable()->constrained('order_statuses')->nullOnDelete();
            $table->foreignId('to_status_id')->nullable()->constrained('order_statuses')->nullOnDelete();
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('comment')->nullable();
            $table->json('payload')->nullable();
            $table->timestamps();

            $table->index(['order_id', 'id']);
            $table->index(['to_status_id', 'created_at']);
        });

        Schema::create('order_transactions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->string('provider', 60)->default('manual');
            $table->string('transaction_ref', 120)->nullable()->index();
            $table->string('status', 60)->default('pending')->index();
            $table->decimal('amount', 12, 2)->default(0);
            $table->string('currency_code', 8)->default('EUR');
            $table->timestamp('processed_at')->nullable()->index();
            $table->json('payload')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['order_id', 'processed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_transactions');
        Schema::dropIfExists('order_history');
        Schema::dropIfExists('order_totals');
        Schema::dropIfExists('order_items');
        Schema::dropIfExists('orders');
    }
};
