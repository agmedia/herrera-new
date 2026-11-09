<?php

use App\Services\Import\HerreraClientDefaults;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        app(HerreraClientDefaults::class)->apply();
    }

    public function down(): void
    {
        // Preserve stock policy, manual edits and the one-time application marker.
    }
};
