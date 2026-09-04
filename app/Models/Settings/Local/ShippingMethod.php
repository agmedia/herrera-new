<?php

namespace App\Models\Settings\Local;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ShippingMethod extends Model
{
    use HasFactory;

    protected $fillable = [
        'code',
        'name',
        'carrier',
        'service_type',
        'pricing_type',
        'geo_zone_id',
        'description',
        'price',
        'free_over',
        'min_subtotal',
        'max_subtotal',
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
        'is_active',
        'sort_order',
        'settings',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'free_over' => 'decimal:2',
            'min_subtotal' => 'decimal:2',
            'max_subtotal' => 'decimal:2',
            'min_weight_kg' => 'decimal:3',
            'max_weight_kg' => 'decimal:3',
            'max_length_cm' => 'decimal:2',
            'max_width_cm' => 'decimal:2',
            'max_height_cm' => 'decimal:2',
            'allows_fragile' => 'boolean',
            'allows_oversized' => 'boolean',
            'allows_heavy' => 'boolean',
            'fragile_surcharge' => 'decimal:2',
            'oversized_surcharge' => 'decimal:2',
            'heavy_surcharge' => 'decimal:2',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
            'settings' => 'array',
        ];
    }

    public function geoZone(): BelongsTo
    {
        return $this->belongsTo(GeoZone::class);
    }

    public function rates(): HasMany
    {
        return $this->hasMany(ShippingMethodRate::class)
            ->orderBy('sort_order')
            ->orderBy('min_weight_kg')
            ->orderBy('id');
    }

    public static function carrierOptions(): array
    {
        return [
            'manual' => 'Ostalo / ručno',
            'boxnow' => 'BOX NOW',
            'gls' => 'GLS',
            'mbe' => 'MBE Boxes',
            'pickup' => 'Osobno preuzimanje',
        ];
    }

    public static function serviceTypeOptions(): array
    {
        return [
            'home_delivery' => 'Dostava na adresu',
            'parcel_locker' => 'Paketomat / ParcelShop',
            'pickup' => 'Osobno preuzimanje',
            'quote' => 'Individualna ponuda dostave',
        ];
    }

    public static function pricingTypeOptions(): array
    {
        return [
            'flat' => 'Fiksna cijena',
            'weight_tiers' => 'Cjenik prema težini',
            'free' => 'Uvijek besplatno',
            'quote' => 'Cijena na upit',
        ];
    }
}
