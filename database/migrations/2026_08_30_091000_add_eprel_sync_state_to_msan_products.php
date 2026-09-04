<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('msan_products', function (Blueprint $table): void {
            $table->string('eprel_match_status', 20)->default('pending')->after('import_status');
            $table->char('eprel_identifier_checksum', 64)->nullable()->after('eprel_match_status');
            $table->timestamp('eprel_checked_at')->nullable()->after('eprel_identifier_checksum');

            $table->index(
                ['eprel_match_status', 'eprel_checked_at'],
                'msan_products_eprel_status_checked_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::table('msan_products', function (Blueprint $table): void {
            $table->dropIndex('msan_products_eprel_status_checked_idx');
            $table->dropColumn([
                'eprel_match_status',
                'eprel_identifier_checksum',
                'eprel_checked_at',
            ]);
        });
    }
};
