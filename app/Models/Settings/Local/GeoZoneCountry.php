<?php

namespace App\Models\Settings\Local;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GeoZoneCountry extends Model
{
    use HasFactory;

    protected $fillable = [
        'geo_zone_id',
        'country_code',
        'region_code',
        'postal_code_from',
        'postal_code_to',
    ];

    public function geoZone(): BelongsTo
    {
        return $this->belongsTo(GeoZone::class);
    }
}
