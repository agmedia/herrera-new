<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('catalog_b2b_price_rules', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 120)->unique();
            $table->string('name', 191);
            $table->foreignId('customer_group_id')->constrained('customer_groups')->cascadeOnDelete();
            $table->string('calculation_type', 32)->index();
            $table->decimal('value', 12, 4);
            $table->string('target_type', 32)->default('all')->index();
            $table->unsignedInteger('minimum_quantity')->default(1);
            $table->char('currency_code', 3)->default('EUR');
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->unsignedInteger('priority')->default(0);
            $table->boolean('is_active')->default(true);
            $table->json('payload')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(
                ['customer_group_id', 'is_active', 'minimum_quantity'],
                'catalog_b2b_rules_group_lookup_idx',
            );
            $table->index(['starts_at', 'ends_at'], 'catalog_b2b_rules_schedule_idx');
            $table->index(['target_type', 'priority'], 'catalog_b2b_rules_target_priority_idx');
        });

        Schema::create('catalog_b2b_price_rule_targets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('rule_id')->constrained('catalog_b2b_price_rules')->cascadeOnDelete();
            $table->string('target_type', 32)->index();
            $table->unsignedBigInteger('target_id')->index();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(
                ['rule_id', 'target_type', 'target_id'],
                'catalog_b2b_rule_target_unique',
            );
            $table->index(
                ['target_type', 'target_id', 'rule_id'],
                'catalog_b2b_rule_target_lookup_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog_b2b_price_rule_targets');
        Schema::dropIfExists('catalog_b2b_price_rules');
    }
};
