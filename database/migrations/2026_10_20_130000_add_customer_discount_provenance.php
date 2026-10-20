<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_discount_rules', function (Blueprint $table): void {
            $table->string('source_system', 64)->nullable();
            $table->string('source_key', 191)->nullable()->unique();
            $table->json('payload')->nullable();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('customer_discount_rules', function (Blueprint $table): void {
            $table->dropUnique(['source_key']);
            $table->dropColumn(['source_system', 'source_key', 'payload', 'deleted_at']);
        });
    }
};
