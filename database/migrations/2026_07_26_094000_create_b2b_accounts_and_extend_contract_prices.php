<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('b2b_accounts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->string('status', 24)->default('pending')->index();
            $table->string('company_name', 191);
            $table->string('oib', 60)->index();
            $table->string('vat_id', 60)->nullable();
            $table->string('phone', 80)->nullable();
            $table->string('address_line_1', 191)->nullable();
            $table->string('address_line_2', 191)->nullable();
            $table->string('postal_code', 32)->nullable();
            $table->string('city', 120)->nullable();
            $table->char('country_code', 2)->default('HR');
            $table->foreignId('requested_customer_group_id')
                ->nullable()
                ->constrained('customer_groups')
                ->nullOnDelete();
            $table->foreignId('customer_group_id')
                ->nullable()
                ->constrained('customer_groups')
                ->nullOnDelete();
            $table->string('erp_customer_id', 120)->nullable()->index();
            $table->string('erp_company_code', 80)->nullable();
            $table->string('contract_number', 120)->nullable();
            $table->date('contract_starts_at')->nullable();
            $table->date('contract_ends_at')->nullable();
            $table->unsignedSmallInteger('payment_terms_days')->nullable();
            $table->boolean('purchase_order_required')->default(false);
            $table->text('status_reason')->nullable();
            $table->timestamp('requested_at')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->json('payload')->nullable();
            $table->timestamps();

            $table->index(
                ['status', 'requested_at'],
                'b2b_accounts_status_requested_idx',
            );
            $table->index(
                ['customer_group_id', 'status'],
                'b2b_accounts_group_status_idx',
            );
        });

        Schema::table('catalog_b2b_price_rules', function (Blueprint $table): void {
            $table->foreignId('user_id')
                ->nullable()
                ->after('customer_group_id')
                ->constrained('users')
                ->nullOnDelete();
            $table->string('contract_number', 120)
                ->nullable()
                ->after('user_id');
            $table->index(
                ['user_id', 'is_active', 'minimum_quantity'],
                'catalog_b2b_rules_user_lookup_idx',
            );
        });

        Schema::table('catalog_b2b_price_rules', function (Blueprint $table): void {
            $table->foreignId('customer_group_id')->nullable()->change();
        });

        Schema::table('orders', function (Blueprint $table): void {
            $table->string('erp_order_id', 120)->nullable()->after('order_number')->index();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropColumn('erp_order_id');
        });

        DB::table('catalog_b2b_price_rules')
            ->whereNull('customer_group_id')
            ->delete();

        Schema::table('catalog_b2b_price_rules', function (Blueprint $table): void {
            $table->dropIndex('catalog_b2b_rules_user_lookup_idx');
            $table->dropForeign(['user_id']);
            $table->dropColumn(['user_id', 'contract_number']);
        });

        Schema::table('catalog_b2b_price_rules', function (Blueprint $table): void {
            $table->foreignId('customer_group_id')->nullable(false)->change();
        });

        Schema::dropIfExists('b2b_accounts');
    }
};
