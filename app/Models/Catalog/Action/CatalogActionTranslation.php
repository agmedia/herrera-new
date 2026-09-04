<?php

namespace App\Models\Catalog\Action;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CatalogActionTranslation extends Model
{
    protected $fillable = [
        'action_id',
        'locale',
        'title',
        'description',
        'badge',
        'payload',
    ];

    protected $casts = [
        'payload' => 'array',
    ];

    public function action(): BelongsTo
    {
        return $this->belongsTo(CatalogAction::class, 'action_id');
    }
}

