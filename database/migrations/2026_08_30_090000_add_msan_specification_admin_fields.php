<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('msan_specification_definitions', function (Blueprint $table): void {
            $table->string('display_measure', 100)->nullable()->after('display_item_name');
            $table->index('group_name', 'msan_spec_defs_group_idx');
            $table->index('item_name', 'msan_spec_defs_item_idx');
        });
    }

    public function down(): void
    {
        Schema::table('msan_specification_definitions', function (Blueprint $table): void {
            $table->dropIndex('msan_spec_defs_group_idx');
            $table->dropIndex('msan_spec_defs_item_idx');
            $table->dropColumn('display_measure');
        });
    }
};
