<?php

namespace App\Models\Sales;

use App\Models\Sales\Order\Order;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ContractWithdrawal extends Model
{
    public const STATUS_RECEIVED = 'received';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_PARTIALLY_RECEIVED = 'partially_received';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_DECLINED = 'declined';

    protected $fillable = [
        'reference',
        'submission_key',
        'user_id',
        'order_id',
        'order_number',
        'full_name',
        'email',
        'phone',
        'address_line',
        'postal_code',
        'city',
        'country_code',
        'contract_date',
        'received_date',
        'items',
        'note',
        'declaration',
        'request_snapshot',
        'snapshot_hash',
        'status',
        'internal_note',
        'locale',
        'submitted_at',
        'consumer_notified_at',
        'admin_notified_at',
        'notification_error',
        'handled_by',
        'handled_at',
        'completed_at',
        'ip_address',
        'user_agent',
        'request_type',
        'approved_by',
        'approved_at',
        'goods_received_at',
    ];

    protected $hidden = [
        'submission_key',
        'ip_address',
        'user_agent',
    ];

    protected $casts = [
        'user_id' => 'int',
        'order_id' => 'int',
        'contract_date' => 'date',
        'received_date' => 'date',
        'request_snapshot' => 'array',
        'submitted_at' => 'datetime',
        'consumer_notified_at' => 'datetime',
        'admin_notified_at' => 'datetime',
        'handled_by' => 'int',
        'handled_at' => 'datetime',
        'completed_at' => 'datetime',
        'approved_by' => 'int',
        'approved_at' => 'datetime',
        'goods_received_at' => 'datetime',
    ];

    /**
     * @return array<string, string>
     */
    public static function statuses(): array
    {
        return [
            self::STATUS_RECEIVED => 'Zahtjev zaprimljen',
            self::STATUS_PROCESSING => 'U obradi',
            self::STATUS_APPROVED => 'Odobreno – čeka se roba',
            self::STATUS_PARTIALLY_RECEIVED => 'Roba djelomično zaprimljena',
            self::STATUS_COMPLETED => 'Dovršeno',
            self::STATUS_DECLINED => 'Odbijeno',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function handler(): BelongsTo
    {
        return $this->belongsTo(User::class, 'handled_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function lineItems(): HasMany
    {
        return $this->hasMany(ContractWithdrawalItem::class, 'withdrawal_id');
    }

    public function typeLabel(): string
    {
        return $this->request_type === 'claim' ? 'Reklamacija (odobrenje)' : 'Povrat ispravne robe';
    }
}
