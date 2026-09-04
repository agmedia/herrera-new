<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('geo_zone_countries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('geo_zone_id')->constrained('geo_zones')->cascadeOnDelete();
            $table->string('country_code', 2);
            $table->string('region_code', 12)->nullable();
            $table->string('postal_code_from', 20)->nullable();
            $table->string('postal_code_to', 20)->nullable();
            $table->timestamps();

            $table->index(['geo_zone_id', 'country_code', 'region_code'], 'geo_zone_country_region_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('geo_zone_countries');
    }
};
