<?php

namespace App\Models\Catalog\Action;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CatalogActionTarget extends Model
{
    protected $fillable = [
        'action_id',
        'target_type',
        'target_id',
        'sort_order',
    ];

    protected $casts = [
        'target_id' => 'int',
        'sort_order' => 'int',
    ];

    public function action(): BelongsTo
    {
        return $this->belongsTo(CatalogAction::class, 'action_id');
    }
}

