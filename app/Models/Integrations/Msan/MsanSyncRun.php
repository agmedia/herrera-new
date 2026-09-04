<?php

namespace App\Models\Integrations\Msan;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MsanSyncRun extends Model
{
    public const KIND_CATALOG = 'catalog';

    public const KIND_PRICES = 'prices';

    public const KIND_AVAILABILITY = 'availability';

    public const KIND_CATEGORIES = 'categories';

    public const KIND_FULL = 'full';

    public const KIND_IMPORT = 'import';

    public const KIND_SPECIFICATIONS = 'specifications';

    public const KIND_EPREL = 'eprel';

    public const STATUS_PENDING = 'pending';

    public const STATUS_RUNNING = 'running';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'kind',
        'status',
        'requested_by',
        'progress',
        'total_count',
        'processed_count',
        'succeeded_count',
        'failed_count',
        'skipped_count',
        'summary',
        'error_message',
        'started_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'requested_by' => 'int',
            'progress' => 'int',
            'total_count' => 'int',
            'processed_count' => 'int',
            'succeeded_count' => 'int',
            'failed_count' => 'int',
            'skipped_count' => 'int',
            'summary' => 'array',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }
}
