<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_visits', fn (Blueprint $table) => $table->json('location')->nullable());
        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('sales_session_id')->nullable()->constrained('customer_sales_sessions')->nullOnDelete();
        });
        DB::table('customer_sales_sessions')->whereIn('order_id', DB::table('customer_sales_sessions')->select('order_id')
            ->whereNotNull('order_id')->groupBy('order_id')->havingRaw('COUNT(*) = 1'))->orderBy('id')->chunkById(500, function ($rows) {
                foreach ($rows as $row) {
                    DB::table('orders')->where('id', $row->order_id)->where('user_id', $row->customer_user_id)
                        ->whereNull('sales_session_id')->update(['sales_session_id' => $row->id]);
                }
            });
        Schema::create('customer_product_presentations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('sales_rep_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('performed_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->string('sku');
            $table->string('product_name');
            $table->uuid('batch');
            $table->text('note')->nullable();
            $table->json('location')->nullable();
            $table->timestamp('presented_at')->index();
            $table->unique(['customer_user_id', 'sales_rep_id', 'batch', 'product_id'], 'unique_presentation_batch_product');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_product_presentations');
        Schema::table('orders', fn (Blueprint $table) => $table->dropConstrainedForeignId('sales_session_id'));
        Schema::table('customer_visits', fn (Blueprint $table) => $table->dropColumn('location'));
    }
};
