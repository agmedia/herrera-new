<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('content_block_slots', function (Blueprint $table): void {
            $table->string('frontend_variant', 16)->nullable()->after('placement')->index();
        });

        DB::table('content_block_slots')
            ->whereNull('frontend_variant')
            ->update(['frontend_variant' => 'all']);
    }

    public function down(): void
    {
        Schema::table('content_block_slots', function (Blueprint $table): void {
            $table->dropIndex(['frontend_variant']);
            $table->dropColumn('frontend_variant');
        });
    }
};

