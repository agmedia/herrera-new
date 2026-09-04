<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('catalog_actions', function (Blueprint $table): void {
            $table->foreignId('customer_group_id')
                ->nullable()
                ->constrained('customer_groups')
                ->nullOnDelete();
        });

        DB::table('catalog_actions')
            ->where('audience_type', 'role')
            ->update([
                'audience_type' => 'all',
                'role_id' => null,
            ]);
    }

    public function down(): void
    {
        Schema::table('catalog_actions', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('customer_group_id');
        });
    }
};
