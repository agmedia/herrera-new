<?php

namespace App\Models\Integrations\Msan;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MsanSpecificationSnapshot extends Model
{
    public const STATUS_CANDIDATE = 'candidate';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_REPLACED = 'replaced';

    protected $fillable = [
        'msan_sync_run_id',
        'status',
        'source',
        'source_bytes',
        'source_checksum',
        'row_count',
        'relevant_row_count',
        'product_count',
        'activated_at',
    ];

    protected function casts(): array
    {
        return [
            'msan_sync_run_id' => 'int',
            'source_bytes' => 'int',
            'row_count' => 'int',
            'relevant_row_count' => 'int',
            'product_count' => 'int',
            'activated_at' => 'datetime',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(MsanSyncRun::class, 'msan_sync_run_id');
    }

    public function specifications(): HasMany
    {
        return $this->hasMany(MsanProductSpecification::class, 'snapshot_id');
    }
}
