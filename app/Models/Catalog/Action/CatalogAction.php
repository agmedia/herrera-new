<?php

namespace App\Models\Catalog\Action;

use App\Models\Catalog\Product\Product;
use App\Models\User\CustomerGroup;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

class CatalogAction extends Model
{
    public const SCOPE_PRODUCT = 'product';
    public const SCOPE_CART = 'cart';

    public const TYPE_PERCENTAGE = 'percentage';
    public const TYPE_FIXED = 'fixed_amount';
    public const TYPE_BUY_X_GET_Y = 'buy_x_get_y';
    public const TYPE_GIFT_ON_AMOUNT = 'gift_on_amount';

    public const TARGET_ALL = 'all';
    public const TARGET_PRODUCT = 'product';
    public const TARGET_CATEGORY = 'category';
    public const TARGET_MANUFACTURER = 'manufacturer';

    public const AUDIENCE_ALL = 'all';
    public const AUDIENCE_USER_GROUP = 'user_group';
    public const AUDIENCE_ROLE = 'role';
    public const AUDIENCE_USER = 'user';

    protected $fillable = [
        'code',
        'scope',
        'type',
        'discount_value',
        'target_type',
        'audience_type',
        'customer_group_id',
        'role_id',
        'user_id',
        'coupon_code',
        'min_subtotal',
        'buy_qty',
        'get_qty',
        'gift_product_id',
        'starts_at',
        'ends_at',
        'priority',
        'is_exclusive',
        'is_active',
        'usage_limit',
        'usage_limit_per_user',
        'used_count',
        'payload',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'discount_value' => 'decimal:2',
        'min_subtotal' => 'decimal:2',
        'buy_qty' => 'int',
        'get_qty' => 'int',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'priority' => 'int',
        'is_exclusive' => 'bool',
        'is_active' => 'bool',
        'usage_limit' => 'int',
        'usage_limit_per_user' => 'int',
        'used_count' => 'int',
        'payload' => 'array',
    ];

    /**
     * @return array<int, string>
     */
    public static function availableScopes(): array
    {
        return [
            self::SCOPE_PRODUCT,
            self::SCOPE_CART,
        ];
    }

    /**
     * @return array<int, string>
     */
    public static function availableTypes(): array
    {
        return [
            self::TYPE_PERCENTAGE,
            self::TYPE_FIXED,
            self::TYPE_BUY_X_GET_Y,
            self::TYPE_GIFT_ON_AMOUNT,
        ];
    }

    /**
     * @return array<int, string>
     */
    public static function availableTargetTypes(): array
    {
        return [
            self::TARGET_ALL,
            self::TARGET_PRODUCT,
            self::TARGET_CATEGORY,
            self::TARGET_MANUFACTURER,
        ];
    }

    /**
     * @return array<int, string>
     */
    public static function availableAudienceTypes(): array
    {
        return [
            self::AUDIENCE_ALL,
            self::AUDIENCE_USER_GROUP,
            self::AUDIENCE_USER,
        ];
    }

    public function translations(): HasMany
    {
        return $this->hasMany(CatalogActionTranslation::class, 'action_id');
    }

    public function translation(string $locale): HasOne
    {
        return $this->hasOne(CatalogActionTranslation::class, 'action_id')->where('locale', $locale);
    }

    public function targets(): HasMany
    {
        return $this->hasMany(CatalogActionTarget::class, 'action_id')->orderBy('sort_order')->orderBy('id');
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(\Silber\Bouncer\Database\Role::class, 'role_id');
    }

    public function audienceCustomerGroup(): BelongsTo
    {
        return $this->belongsTo(CustomerGroup::class, 'customer_group_id');
    }

    public function audienceUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function giftProduct(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'gift_product_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function scopeActive(Builder $query, Carbon|string|null $at = null): Builder
    {
        $at = $at ? Carbon::parse($at) : now();

        return $query
            ->where('is_active', true)
            ->where(function (Builder $q) use ($at): void {
                $q->whereNull('starts_at')
                    ->orWhere('starts_at', '<=', $at);
            })
            ->where(function (Builder $q) use ($at): void {
                $q->whereNull('ends_at')
                    ->orWhere('ends_at', '>=', $at);
            });
    }

    public function scopeAvailableForAudience(Builder $query, ?User $user): Builder
    {
        return $query->where(function (Builder $audienceQuery) use ($user): void {
            $audienceQuery->where('audience_type', self::AUDIENCE_ALL);

            if ($user) {
                $audienceQuery->orWhere(function (Builder $q) use ($user): void {
                    $q->where('audience_type', self::AUDIENCE_USER)
                        ->where('user_id', $user->id);
                });

                $groupIds = $user->customerGroups()->pluck('customer_groups.id')->map(fn ($id) => (int) $id)->all();
                if ($groupIds !== []) {
                    $audienceQuery->orWhere(function (Builder $q) use ($groupIds): void {
                        $q->where('audience_type', self::AUDIENCE_USER_GROUP)
                            ->whereIn('customer_group_id', $groupIds);
                    });
                }

                // Legacy fallback for actions created before user-group audience migration.
                $roleIds = $user->roles()->pluck('roles.id')->map(fn ($id) => (int) $id)->all();
                if ($roleIds !== []) {
                    $audienceQuery->orWhere(function (Builder $q) use ($roleIds): void {
                        $q->where('audience_type', self::AUDIENCE_ROLE)
                            ->whereIn('role_id', $roleIds);
                    });
                }
            }
        });
    }
}
