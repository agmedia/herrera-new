<?php

namespace App\Models\Content\Page;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InfoPageTranslation extends Model
{
    protected $table = 'content_info_page_translations';

    protected $fillable = [
        'page_id',
        'locale',
        'title',
        'slug',
        'excerpt',
        'body_html',
        'meta_title',
        'meta_description',
        'payload',
    ];

    protected $casts = [
        'payload' => 'array',
    ];

    public function page(): BelongsTo
    {
        return $this->belongsTo(InfoPage::class, 'page_id');
    }
}
