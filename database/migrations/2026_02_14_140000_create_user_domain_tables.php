<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_groups', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 80)->unique();
            $table->string('name', 120);
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->boolean('is_default')->default(false)->index();
            $table->unsignedInteger('sort_order')->default(0)->index();
            $table->json('payload')->nullable();
            $table->timestamps();
        });

        Schema::create('customer_group_user', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_group_id')->constrained('customer_groups')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['customer_group_id', 'user_id'], 'customer_group_user_unique');
        });

        Schema::create('user_profiles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete()->unique();
            $table->string('first_name', 120)->nullable();
            $table->string('last_name', 120)->nullable();
            $table->string('phone', 80)->nullable();
            $table->string('company', 191)->nullable();
            $table->string('oib', 60)->nullable();
            $table->date('birthday')->nullable();
            $table->string('gender', 24)->nullable();
            $table->string('affiliate_name', 191)->nullable();
            $table->text('bio')->nullable();
            $table->boolean('newsletter_opt_in')->default(false)->index();
            $table->json('payload')->nullable();
            $table->timestamps();
        });

        Schema::create('user_addresses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('type', 24)->index();
            $table->string('first_name', 120)->nullable();
            $table->string('last_name', 120)->nullable();
            $table->string('company', 191)->nullable();
            $table->string('oib', 60)->nullable();
            $table->string('vat_id', 60)->nullable();
            $table->string('phone', 80)->nullable();
            $table->string('address_line_1', 191)->nullable();
            $table->string('address_line_2', 191)->nullable();
            $table->string('postal_code', 32)->nullable();
            $table->string('city', 120)->nullable();
            $table->string('state', 120)->nullable();
            $table->string('country_code', 2)->default('HR')->index();
            $table->boolean('is_default')->default(false)->index();
            $table->json('payload')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'type'], 'user_addresses_user_type_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_addresses');
        Schema::dropIfExists('user_profiles');
        Schema::dropIfExists('customer_group_user');
        Schema::dropIfExists('customer_groups');
    }
};

