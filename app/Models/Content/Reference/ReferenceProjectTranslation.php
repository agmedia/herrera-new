<?php

namespace App\Models\Content\Reference;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReferenceProjectTranslation extends Model
{
    protected $table = 'content_reference_project_translations';

    protected $fillable = ['locale', 'title', 'slug', 'excerpt', 'description'];

    public function project(): BelongsTo
    {
        return $this->belongsTo(ReferenceProject::class, 'project_id');
    }
}
