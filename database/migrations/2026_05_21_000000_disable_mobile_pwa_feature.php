<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('system_settings')->where('key', 'catalog_use_mobile_pwa')->delete();
        DB::table('system_settings')->updateOrInsert(
            ['key' => 'catalog_use_mobile_view'],
            [
                'value' => 'false',
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        Cache::forget('settings.system.map');
    }

    public function down(): void
    {
        DB::table('system_settings')->where('key', 'catalog_use_mobile_view')->delete();
        DB::table('system_settings')->updateOrInsert(
            ['key' => 'catalog_use_mobile_pwa'],
            [
                'value' => 'true',
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        Cache::forget('settings.system.map');
    }
};
