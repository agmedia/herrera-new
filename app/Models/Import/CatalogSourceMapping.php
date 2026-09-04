<?php

namespace App\Models\Import;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CatalogSourceMapping extends Model
{
    public const ENTITY_CATEGORY = 'category';

    public const ENTITY_PRODUCT = 'product';

    public const ENTITY_ATTRIBUTE = 'attribute';

    protected $fillable = [
        'source',
        'entity_type',
        'source_id',
        'local_id',
        'lifecycle_status',
        'source_checksum',
        'last_seen_at',
        'tombstoned_at',
        'last_import_run_id',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'local_id' => 'int',
            'last_seen_at' => 'datetime',
            'tombstoned_at' => 'datetime',
            'last_import_run_id' => 'int',
            'metadata' => 'array',
        ];
    }

    public function lastImportRun(): BelongsTo
    {
        return $this->belongsTo(CatalogImportRun::class, 'last_import_run_id');
    }
}
