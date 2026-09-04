<?php

namespace App\Models\Integrations\Msan;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MsanImportRunItem extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_SUCCEEDED = 'succeeded';

    public const STATUS_FAILED = 'failed';

    public const STATUS_SKIPPED = 'skipped';

    protected $fillable = [
        'msan_sync_run_id',
        'msan_product_id',
        'status',
        'attempts',
        'error_message',
        'started_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'attempts' => 'int',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(MsanSyncRun::class, 'msan_sync_run_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(MsanProduct::class, 'msan_product_id');
    }
}
