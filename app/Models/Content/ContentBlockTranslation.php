<?php

namespace App\Models\Content;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContentBlockTranslation extends Model
{
    protected $fillable = [
        'content_block_id',
        'locale',
        'title',
        'subtitle',
        'body_html',
        'cta_label',
        'cta_url',
        'payload',
    ];

    protected $casts = [
        'payload' => 'array',
    ];

    public function block(): BelongsTo
    {
        return $this->belongsTo(ContentBlock::class, 'content_block_id');
    }
}

