<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contract_withdrawals', function (Blueprint $table): void {
            $table->string('request_type', 16)->default('return');
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('goods_received_at')->nullable();
        });

        Schema::create('contract_withdrawal_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('withdrawal_id')->constrained('contract_withdrawals')->restrictOnDelete();
            $table->foreignId('order_item_id')->constrained('order_items')->restrictOnDelete();
            // Keep the original identities even if the catalog entry is removed.
            $table->unsignedBigInteger('product_id')->nullable();
            $table->unsignedBigInteger('product_option_value_id')->nullable();
            $table->string('name');
            $table->string('sku')->nullable();
            $table->string('code')->nullable();
            $table->unsignedInteger('quantity');
            $table->unsignedInteger('received_quantity')->default(0);
            $table->unsignedInteger('restocked_quantity')->default(0);
            $table->timestamps();
            $table->unique(['withdrawal_id', 'order_item_id'], 'withdrawal_order_item_unique');
        });

        Schema::create('contract_withdrawal_receipts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('withdrawal_item_id')->constrained('contract_withdrawal_items')->restrictOnDelete();
            $table->unsignedInteger('quantity');
            $table->unsignedInteger('restocked_quantity')->default(0);
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('received_at');
            $table->text('note')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contract_withdrawal_receipts');
        Schema::dropIfExists('contract_withdrawal_items');
        Schema::table('contract_withdrawals', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('approved_by');
            $table->dropColumn(['request_type', 'approved_at', 'goods_received_at']);
        });
    }
};
