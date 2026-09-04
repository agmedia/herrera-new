<?php

namespace App\Models\Content;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContentBlockItem extends Model
{
    protected $fillable = [
        'content_block_id',
        'item_type',
        'item_id',
        'sort_order',
    ];

    protected $casts = [
        'item_id' => 'int',
        'sort_order' => 'int',
    ];

    public function block(): BelongsTo
    {
        return $this->belongsTo(ContentBlock::class, 'content_block_id');
    }
}

