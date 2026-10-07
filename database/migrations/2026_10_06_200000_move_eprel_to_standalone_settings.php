<?php

use App\Services\Settings\SystemSettingsService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('system_settings')) {
            return;
        }
        DB::transaction(function (): void {
            foreach ([
                'eprel_enabled' => ['msan_eprel_enabled', 'false'],
                'eprel_api_key_encrypted' => ['msan_eprel_api_key_encrypted', '""'],
                'eprel_connect_timeout' => ['msan_eprel_connect_timeout', '10'],
                'eprel_timeout' => ['msan_eprel_timeout', '30'],
            ] as $key => [$legacyKey, $default]) {
                $legacyValue = DB::table('system_settings')->where('key', $legacyKey)->value('value');
                // Kopiramo izvorni šifrirani zapis bez dešifriranja ili zamjene ključa.
                DB::table('system_settings')->insertOrIgnore([
                    'key' => $key,
                    'value' => $legacyValue ?? $default,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }, 3);
        Cache::forget('settings.system.map');
        app(SystemSettingsService::class)->clearRuntimeCache();
    }

    public function down(): void
    {
        // Povratak koda ne smije obrisati ni novi ni sačuvani izvorni API ključ.
    }
};
