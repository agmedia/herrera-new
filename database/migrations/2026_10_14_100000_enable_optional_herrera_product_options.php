<?php

use App\Services\Settings\SystemSettingsService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('system_settings')) {
            return;
        }

        $settings = app(SystemSettingsService::class);
        $brand = (string) $settings->get('store_brand_name', config('app.name'));

        if (str_contains(strtolower($brand), 'herrera')) {
            // Expose the editor; products remain unchanged until options are assigned.
            $settings->put('catalog_use_options', true);
        }
    }

    public function down(): void
    {
        // Keep options explicitly assigned by an administrator available.
    }
};
