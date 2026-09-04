<?php

namespace App\Models\Integrations\Msan;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MsanSpecificationDefinition extends Model
{
    public const ROLE_SPECIFICATION = 'specification';

    public const ROLE_ENERGY_CLASS = 'energy_class';

    public const ROLE_ENERGY_SCALE = 'energy_scale';

    public const ROLE_EPREL_NUMBER = 'eprel_number';

    public const ROLE_ENERGY_LABEL_URL = 'energy_label_url';

    public const ROLE_PRODUCT_INFORMATION_SHEET_URL = 'product_information_sheet_url';

    protected $fillable = [
        'source_key',
        'group_name',
        'item_name',
        'measure',
        'display_group_name',
        'display_item_name',
        'display_measure',
        'source_for_filter',
        'import_enabled',
        'use_as_filter',
        'data_role',
        'sample_values',
        'product_count',
        'last_seen_at',
        'is_stale',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'source_for_filter' => 'bool',
            'import_enabled' => 'bool',
            'use_as_filter' => 'bool',
            'sample_values' => 'array',
            'product_count' => 'int',
            'last_seen_at' => 'datetime',
            'is_stale' => 'bool',
            'updated_by' => 'int',
        ];
    }

    /** @return array<string, string> */
    public static function roleOptions(): array
    {
        return [
            self::ROLE_SPECIFICATION => 'Tehnička specifikacija',
            self::ROLE_ENERGY_CLASS => 'Energetski razred',
            self::ROLE_ENERGY_SCALE => 'Raspon energetskih razreda',
            self::ROLE_EPREL_NUMBER => 'EPREL registracijski broj',
            self::ROLE_ENERGY_LABEL_URL => 'URL energetske oznake',
            self::ROLE_PRODUCT_INFORMATION_SHEET_URL => 'URL informacijskog lista',
        ];
    }

    public function productSpecifications(): HasMany
    {
        return $this->hasMany(MsanProductSpecification::class, 'definition_id');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
