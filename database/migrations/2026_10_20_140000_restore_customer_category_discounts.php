<?php

use App\Services\Import\HerreraCustomerCategoryDiscountImportService;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        app(HerreraCustomerCategoryDiscountImportService::class)->import();
    }

    public function down(): void
    {
        // Restored customer agreements may have been edited; a rollback must retain them.
    }
};
