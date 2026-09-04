<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('categories', function (Blueprint $table): void {
            $table->timestamp('starts_at')->nullable()->after('sort_order')->index();
            $table->timestamp('ends_at')->nullable()->after('starts_at')->index();
        });
    }

    public function down(): void
    {
        Schema::table('categories', function (Blueprint $table): void {
            $table->dropColumn(['starts_at', 'ends_at']);
        });
    }
};
