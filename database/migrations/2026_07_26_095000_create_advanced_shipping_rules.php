<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shipping_methods', function (Blueprint $table): void {
            $table->string('carrier', 40)->default('manual')->after('name')->index();
            $table->string('service_type', 40)->default('home_delivery')->after('carrier')->index();
            $table->string('pricing_type', 40)->default('flat')->after('service_type');
            $table->decimal('min_weight_kg', 10, 3)->nullable()->after('max_subtotal');
            $table->decimal('max_weight_kg', 10, 3)->nullable()->after('min_weight_kg');
            $table->decimal('max_length_cm', 10, 2)->nullable()->after('max_weight_kg');
            $table->decimal('max_width_cm', 10, 2)->nullable()->after('max_length_cm');
            $table->decimal('max_height_cm', 10, 2)->nullable()->after('max_width_cm');
            $table->boolean('allows_fragile')->default(true)->after('max_height_cm');
            $table->boolean('allows_oversized')->default(true)->after('allows_fragile');
            $table->boolean('allows_heavy')->default(true)->after('allows_oversized');
            $table->decimal('fragile_surcharge', 10, 2)->default(0)->after('allows_heavy');
            $table->decimal('oversized_surcharge', 10, 2)->default(0)->after('fragile_surcharge');
            $table->decimal('heavy_surcharge', 10, 2)->default(0)->after('oversized_surcharge');
            $table->string('missing_measurements_policy', 20)->default('allow')->after('heavy_surcharge');
        });

        Schema::create('shipping_method_rates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('shipping_method_id')->constrained('shipping_methods')->cascadeOnDelete();
            $table->decimal('min_weight_kg', 10, 3)->default(0);
            $table->decimal('max_weight_kg', 10, 3)->nullable();
            $table->decimal('price', 10, 2)->default(0);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['shipping_method_id', 'sort_order'], 'shipping_rates_method_sort_idx');
            $table->index(
                ['shipping_method_id', 'min_weight_kg', 'max_weight_kg'],
                'shipping_rates_method_weight_idx'
            );
        });

        DB::table('shipping_methods')
            ->whereIn('code', ['pickup', 'store_pickup', 'local_pickup'])
            ->update([
                'carrier' => 'pickup',
                'service_type' => 'pickup',
                'pricing_type' => 'free',
            ]);

        DB::table('shipping_methods')
            ->whereIn('code', ['boxnow', 'box_now'])
            ->update([
                'carrier' => 'boxnow',
                'service_type' => 'parcel_locker',
                'pricing_type' => 'flat',
                'max_weight_kg' => 20,
                'max_length_cm' => 60,
                'max_width_cm' => 45,
                'max_height_cm' => 36,
                'allows_fragile' => false,
                'allows_oversized' => false,
                'allows_heavy' => false,
                'missing_measurements_policy' => 'allow',
            ]);

        $croatiaZoneId = DB::table('geo_zones')->where('code', 'hr')->value('id')
            ?? DB::table('geo_zones')->where('name', 'like', '%Croatia%')->value('id')
            ?? DB::table('geo_zones')->where('name', 'like', '%Hrvats%')->value('id');

        $now = now();

        DB::table('shipping_methods')->insertOrIgnore([
            [
                'code' => 'gls_home',
                'name' => 'GLS dostava na adresu',
                'carrier' => 'gls',
                'service_type' => 'home_delivery',
                'pricing_type' => 'flat',
                'geo_zone_id' => $croatiaZoneId,
                'description' => 'GLS kurirska dostava na adresu kupca.',
                'price' => 5.99,
                'free_over' => null,
                'min_subtotal' => null,
                'max_subtotal' => null,
                'min_weight_kg' => null,
                'max_weight_kg' => 40,
                'max_length_cm' => null,
                'max_width_cm' => null,
                'max_height_cm' => null,
                'allows_fragile' => true,
                'allows_oversized' => false,
                'allows_heavy' => true,
                'fragile_surcharge' => 0,
                'oversized_surcharge' => 0,
                'heavy_surcharge' => 0,
                'missing_measurements_policy' => 'allow',
                'is_active' => false,
                'sort_order' => 60,
                'settings' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'code' => 'gls_dpm',
                'name' => 'GLS paketomat / ParcelShop',
                'carrier' => 'gls',
                'service_type' => 'parcel_locker',
                'pricing_type' => 'flat',
                'geo_zone_id' => $croatiaZoneId,
                'description' => 'Dostava u odabrani GLS paketomat ili ParcelShop.',
                'price' => 4.99,
                'free_over' => null,
                'min_subtotal' => null,
                'max_subtotal' => null,
                'min_weight_kg' => null,
                'max_weight_kg' => 40,
                'max_length_cm' => 50,
                'max_width_cm' => 50,
                'max_height_cm' => 50,
                'allows_fragile' => false,
                'allows_oversized' => false,
                'allows_heavy' => false,
                'fragile_surcharge' => 0,
                'oversized_surcharge' => 0,
                'heavy_surcharge' => 0,
                'missing_measurements_policy' => 'allow',
                'is_active' => false,
                'sort_order' => 70,
                'settings' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'code' => 'shipping_quote',
                'name' => 'Dostava prema ponudi',
                'carrier' => 'manual',
                'service_type' => 'quote',
                'pricing_type' => 'quote',
                'geo_zone_id' => null,
                'description' => 'Narudžba se šalje bez konačne cijene dostave; administrator naknadno šalje ponudu.',
                'price' => 0,
                'free_over' => null,
                'min_subtotal' => null,
                'max_subtotal' => null,
                'min_weight_kg' => null,
                'max_weight_kg' => null,
                'max_length_cm' => null,
                'max_width_cm' => null,
                'max_height_cm' => null,
                'allows_fragile' => true,
                'allows_oversized' => true,
                'allows_heavy' => true,
                'fragile_surcharge' => 0,
                'oversized_surcharge' => 0,
                'heavy_surcharge' => 0,
                'missing_measurements_policy' => 'allow',
                'is_active' => true,
                'sort_order' => 900,
                'settings' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('shipping_method_rates');

        Schema::table('shipping_methods', function (Blueprint $table): void {
            $table->dropIndex(['carrier']);
            $table->dropIndex(['service_type']);
            $table->dropColumn([
                'carrier',
                'service_type',
                'pricing_type',
                'min_weight_kg',
                'max_weight_kg',
                'max_length_cm',
                'max_width_cm',
                'max_height_cm',
                'allows_fragile',
                'allows_oversized',
                'allows_heavy',
                'fragile_surcharge',
                'oversized_surcharge',
                'heavy_surcharge',
                'missing_measurements_policy',
            ]);
        });
    }
};
