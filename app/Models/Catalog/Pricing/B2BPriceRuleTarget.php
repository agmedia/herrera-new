<?php

namespace App\Models\Catalog\Pricing;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class B2BPriceRuleTarget extends Model
{
    protected $table = 'catalog_b2b_price_rule_targets';

    protected $fillable = [
        'rule_id',
        'target_type',
        'target_id',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'target_id' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    public function rule(): BelongsTo
    {
        return $this->belongsTo(B2BPriceRule::class, 'rule_id');
    }
}
