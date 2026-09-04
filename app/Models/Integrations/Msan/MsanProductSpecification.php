<?php

namespace App\Models\Integrations\Msan;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MsanProductSpecification extends Model
{
    protected $fillable = [
        'msan_product_id',
        'snapshot_id',
        'definition_id',
        'values',
        'item_order',
        'checksum',
        'last_seen_at',
    ];

    protected function casts(): array
    {
        return [
            'msan_product_id' => 'int',
            'snapshot_id' => 'int',
            'definition_id' => 'int',
            'values' => 'array',
            'item_order' => 'int',
            'last_seen_at' => 'datetime',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(MsanProduct::class, 'msan_product_id');
    }

    public function snapshot(): BelongsTo
    {
        return $this->belongsTo(MsanSpecificationSnapshot::class, 'snapshot_id');
    }

    public function definition(): BelongsTo
    {
        return $this->belongsTo(MsanSpecificationDefinition::class, 'definition_id');
    }
}
