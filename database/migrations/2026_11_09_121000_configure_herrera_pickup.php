<?php

use App\Services\Settings\SystemSettingsService;
use App\Services\Shipping\HerreraPickupConfiguration;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        $brand = app(SystemSettingsService::class)->get('store_brand_name', config('app.name'));
        if (str_contains(strtolower((string) $brand), 'herrera')) {
            app(HerreraPickupConfiguration::class)->configure();
        }
    }

    public function down(): void
    {
        // Keep the method and its historical orders; administrators can disable it.
    }
};
