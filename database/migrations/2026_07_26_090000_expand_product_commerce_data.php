<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('products', 'barcode')) {
            Schema::table('products', function (Blueprint $table): void {
                $table->string('barcode', 80)->nullable()->unique()->after('sku');
                $table->string('unit_of_measure', 24)->default('pcs')->index()->after('barcode');
                $table->unsignedInteger('minimum_order_quantity')->default(1)->after('unit_of_measure');
                $table->unsignedInteger('order_quantity_step')->default(1)->after('minimum_order_quantity');
                $table->decimal('weight_kg', 10, 3)->nullable()->after('stock_qty');
                $table->decimal('length_cm', 10, 2)->nullable()->after('weight_kg');
                $table->decimal('width_cm', 10, 2)->nullable()->after('length_cm');
                $table->decimal('height_cm', 10, 2)->nullable()->after('width_cm');
                $table->json('shipping_labels')->nullable()->after('height_cm');
            });
        }

        if (! Schema::hasTable('catalog_product_packages')) {
            Schema::create('catalog_product_packages', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
                $table->string('code', 120);
                $table->string('name', 120);
                $table->string('barcode', 80)->nullable()->unique();
                $table->string('package_type', 32)->default('box');
                $table->string('unit_of_measure', 24)->default('pcs');
                $table->decimal('quantity', 12, 3)->default(1);
                $table->decimal('weight_kg', 10, 3)->nullable();
                $table->decimal('length_cm', 10, 2)->nullable();
                $table->decimal('width_cm', 10, 2)->nullable();
                $table->decimal('height_cm', 10, 2)->nullable();
                $table->boolean('is_default')->default(false)->index();
                $table->boolean('is_active')->default(true)->index();
                $table->unsignedInteger('sort_order')->default(0);
                $table->json('payload')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->unique(['product_id', 'code']);
                $table->index(['product_id', 'is_active', 'sort_order']);
            });
        }

        if (! Schema::hasTable('catalog_product_group_prices')) {
            Schema::create('catalog_product_group_prices', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
                $table->foreignId('customer_group_id')->constrained('customer_groups')->cascadeOnDelete();
                $table->foreignId('product_package_id')->nullable()
                    ->constrained('catalog_product_packages')->nullOnDelete();
                $table->unsignedInteger('minimum_quantity')->default(1);
                $table->decimal('price', 12, 4);
                $table->char('currency_code', 3)->default('EUR');
                $table->timestamp('starts_at')->nullable();
                $table->timestamp('ends_at')->nullable();
                $table->boolean('is_active')->default(true);
                $table->json('payload')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->index(
                    ['product_id', 'customer_group_id', 'is_active', 'minimum_quantity'],
                    'catalog_product_group_prices_lookup_index',
                );
                $table->index(['starts_at', 'ends_at']);
            });
        }

        if (! Schema::hasTable('catalog_product_price_history')) {
            Schema::create('catalog_product_price_history', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
                $table->foreignId('product_option_value_id')->nullable()
                    ->constrained('catalog_product_option_values')->nullOnDelete();
                $table->foreignId('customer_group_id')->nullable()
                    ->constrained('customer_groups')->nullOnDelete();
                $table->foreignId('product_package_id')->nullable()
                    ->constrained('catalog_product_packages')->nullOnDelete();
                $table->string('price_type', 24)->index();
                $table->decimal('old_price', 12, 4)->nullable();
                $table->decimal('new_price', 12, 4)->nullable();
                $table->char('currency_code', 3)->default('EUR');
                $table->timestamp('effective_at')->index();
                $table->string('source', 40)->default('model');
                $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
                $table->json('payload')->nullable();
                $table->timestamps();

                $table->index(
                    ['product_id', 'price_type', 'effective_at'],
                    'catalog_product_price_history_lookup_index',
                );
                $table->index(
                    ['customer_group_id', 'product_package_id'],
                    'catalog_price_history_group_package_idx',
                );
            });
        }

        $historyIndexes = [
            'catalog_product_price_history_price_type_index' => ['price_type'],
            'catalog_product_price_history_effective_at_index' => ['effective_at'],
            'catalog_price_history_group_package_idx' => ['customer_group_id', 'product_package_id'],
        ];

        foreach ($historyIndexes as $name => $columns) {
            if (! Schema::hasIndex('catalog_product_price_history', $name)) {
                Schema::table('catalog_product_price_history', function (Blueprint $table) use ($columns, $name): void {
                    $table->index($columns, $name);
                });
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog_product_price_history');
        Schema::dropIfExists('catalog_product_group_prices');
        Schema::dropIfExists('catalog_product_packages');

        Schema::table('products', function (Blueprint $table): void {
            $table->dropUnique(['barcode']);
            $table->dropIndex(['unit_of_measure']);
            $table->dropColumn([
                'barcode',
                'unit_of_measure',
                'minimum_order_quantity',
                'order_quantity_step',
                'weight_kg',
                'length_cm',
                'width_cm',
                'height_cm',
                'shipping_labels',
            ]);
        });
    }
};
