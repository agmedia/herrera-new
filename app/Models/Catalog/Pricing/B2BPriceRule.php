<?php

namespace App\Models\Catalog\Pricing;

use App\Models\User;
use App\Models\User\CustomerGroup;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

class B2BPriceRule extends Model
{
    public const TYPE_PERCENTAGE_DISCOUNT = 'percentage_discount';

    public const TYPE_FIXED_DISCOUNT = 'fixed_discount';

    public const TYPE_FIXED_PRICE = 'fixed_price';

    public const TARGET_ALL = 'all';

    public const TARGET_PRODUCT = 'product';

    public const TARGET_CATEGORY = 'category';

    public const TARGET_MANUFACTURER = 'manufacturer';

    protected $table = 'catalog_b2b_price_rules';

    protected $fillable = [
        'code',
        'name',
        'customer_group_id',
        'user_id',
        'contract_number',
        'calculation_type',
        'value',
        'target_type',
        'minimum_quantity',
        'currency_code',
        'starts_at',
        'ends_at',
        'priority',
        'is_active',
        'payload',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'value' => 'decimal:4',
            'minimum_quantity' => 'integer',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'priority' => 'integer',
            'is_active' => 'boolean',
            'payload' => 'array',
        ];
    }

    public static function calculationTypeOptions(): array
    {
        return [
            self::TYPE_PERCENTAGE_DISCOUNT => 'Postotni popust',
            self::TYPE_FIXED_DISCOUNT => 'Fiksni popust',
            self::TYPE_FIXED_PRICE => 'Fiksna cijena proizvoda',
        ];
    }

    public static function targetTypeOptions(): array
    {
        return [
            self::TARGET_ALL => 'Svi proizvodi',
            self::TARGET_MANUFACTURER => 'Brendovi',
            self::TARGET_CATEGORY => 'Kategorije',
            self::TARGET_PRODUCT => 'Proizvodi',
        ];
    }

    public function scopeActiveAt(Builder $query, Carbon|string|null $at = null): Builder
    {
        $at ??= now();

        return $query
            ->where('is_active', true)
            ->where(fn (Builder $query): Builder => $query
                ->whereNull('starts_at')
                ->orWhere('starts_at', '<=', $at))
            ->where(fn (Builder $query): Builder => $query
                ->whereNull('ends_at')
                ->orWhere('ends_at', '>=', $at));
    }

    public function customerGroup(): BelongsTo
    {
        return $this->belongsTo(CustomerGroup::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function targets(): HasMany
    {
        return $this->hasMany(B2BPriceRuleTarget::class, 'rule_id')
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
