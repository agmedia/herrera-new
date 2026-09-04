<?php

namespace App\Models\User;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class B2BAccount extends Model
{
    protected $table = 'b2b_accounts';

    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_SUSPENDED = 'suspended';

    protected $fillable = [
        'user_id',
        'status',
        'company_name',
        'oib',
        'vat_id',
        'phone',
        'address_line_1',
        'address_line_2',
        'postal_code',
        'city',
        'country_code',
        'requested_customer_group_id',
        'customer_group_id',
        'erp_customer_id',
        'erp_company_code',
        'contract_number',
        'contract_starts_at',
        'contract_ends_at',
        'payment_terms_days',
        'purchase_order_required',
        'status_reason',
        'requested_at',
        'reviewed_at',
        'reviewed_by',
        'payload',
        'quick_order_draft',
    ];

    protected function casts(): array
    {
        return [
            'requested_customer_group_id' => 'integer',
            'customer_group_id' => 'integer',
            'contract_starts_at' => 'date',
            'contract_ends_at' => 'date',
            'payment_terms_days' => 'integer',
            'purchase_order_required' => 'boolean',
            'requested_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'payload' => 'array',
            'quick_order_draft' => 'array',
        ];
    }

    public static function statusOptions(): array
    {
        return [
            self::STATUS_PENDING => 'Na čekanju',
            self::STATUS_APPROVED => 'Odobreno',
            self::STATUS_REJECTED => 'Odbijeno',
            self::STATUS_SUSPENDED => 'Suspendirano',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function requestedCustomerGroup(): BelongsTo
    {
        return $this->belongsTo(CustomerGroup::class, 'requested_customer_group_id');
    }

    public function customerGroup(): BelongsTo
    {
        return $this->belongsTo(CustomerGroup::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function scopeApproved(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_APPROVED);
    }

    public function isApproved(): bool
    {
        return $this->status === self::STATUS_APPROVED;
    }

    public function contractIsActive(): bool
    {
        if (! $this->isApproved()) {
            return false;
        }

        $today = now()->startOfDay();

        return (! $this->contract_starts_at || $this->contract_starts_at->copy()->startOfDay()->lte($today))
            && (! $this->contract_ends_at || $this->contract_ends_at->copy()->endOfDay()->gte($today));
    }
}
