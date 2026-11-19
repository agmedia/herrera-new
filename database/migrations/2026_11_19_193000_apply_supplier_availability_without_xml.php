<?php

use App\Services\Integrations\Stock\FixedSupplierAvailabilityService;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        app(FixedSupplierAvailabilityService::class)->applyKnownSuppliersWithoutXml();
    }

    public function down(): void
    {
        // Supplier quantities may have been edited or consumed since deployment.
    }
};
