<?php

namespace App\Models\Catalog\Product;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductEnergyDeclaration extends Model
{
    public const ENERGY_CLASSES = ['A+++', 'A++', 'A+', 'A', 'B', 'C', 'D', 'E', 'F', 'G'];

    public const SOURCE_MANUAL = 'manual';

    public const SOURCE_MSAN = 'msan';

    public const SOURCE_EPREL = 'eprel';

    protected $fillable = [
        'product_id',
        'context_code',
        'label',
        'energy_class',
        'scale_min',
        'scale_max',
        'eprel_registration_number',
        'eprel_product_group',
        'energy_label_image',
        'energy_label_url',
        'product_information_sheet_url',
        'is_primary',
        'source',
        'payload',
        'synced_at',
    ];

    protected function casts(): array
    {
        return [
            'product_id' => 'int',
            'is_primary' => 'bool',
            'payload' => 'array',
            'synced_at' => 'datetime',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return array<int, string> */
    public static function energyClassOptions(): array
    {
        return self::ENERGY_CLASSES;
    }
}
