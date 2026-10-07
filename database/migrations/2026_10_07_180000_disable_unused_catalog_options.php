<?php

use App\Services\Settings\SystemSettingsService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('system_settings')) {
            return;
        }

        foreach (['catalog_option_product', 'catalog_product_option_values'] as $table) {
            if (Schema::hasTable($table) && DB::table($table)->exists()) {
                return;
            }
        }

        app(SystemSettingsService::class)->put('catalog_use_options', false);
    }

    public function down(): void
    {
        // Re-enabling this optional module remains an explicit admin setting.
    }
};
