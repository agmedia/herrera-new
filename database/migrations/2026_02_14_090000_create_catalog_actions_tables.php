<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('catalog_actions', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 120)->unique();
            $table->string('scope', 32)->default('product')->index();
            $table->string('type', 40)->default('percentage')->index();
            $table->decimal('discount_value', 12, 2)->nullable();
            $table->string('target_type', 40)->default('all')->index();
            $table->string('audience_type', 40)->default('all')->index();
            $table->foreignId('role_id')->nullable()->constrained('roles')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('coupon_code', 60)->nullable()->index();
            $table->decimal('min_subtotal', 12, 2)->nullable();
            $table->unsignedInteger('buy_qty')->nullable();
            $table->unsignedInteger('get_qty')->nullable();
            $table->foreignId('gift_product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->timestamp('starts_at')->nullable()->index();
            $table->timestamp('ends_at')->nullable()->index();
            $table->unsignedInteger('priority')->default(0)->index();
            $table->boolean('is_exclusive')->default(false)->index();
            $table->boolean('is_active')->default(true)->index();
            $table->unsignedInteger('usage_limit')->nullable();
            $table->unsignedInteger('usage_limit_per_user')->nullable();
            $table->unsignedInteger('used_count')->default(0);
            $table->json('payload')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('catalog_action_translations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('action_id')->constrained('catalog_actions')->cascadeOnDelete();
            $table->string('locale', 12)->index();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('badge')->nullable();
            $table->json('payload')->nullable();
            $table->timestamps();

            $table->unique(['action_id', 'locale'], 'catalog_action_locale_unique');
        });

        Schema::create('catalog_action_targets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('action_id')->constrained('catalog_actions')->cascadeOnDelete();
            $table->string('target_type', 40)->index();
            $table->unsignedBigInteger('target_id')->index();
            $table->unsignedInteger('sort_order')->default(0)->index();
            $table->timestamps();

            $table->unique(['action_id', 'target_type', 'target_id'], 'catalog_action_target_unique');
            $table->index(['target_type', 'target_id'], 'catalog_action_target_lookup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog_action_targets');
        Schema::dropIfExists('catalog_action_translations');
        Schema::dropIfExists('catalog_actions');
    }
};

