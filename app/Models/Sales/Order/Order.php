<?php

namespace App\Models\Sales\Order;

use App\Models\Settings\Local\OrderStatus;
use App\Models\User;
use App\Models\User\LoyaltyTransaction;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Order extends Model
{
    protected $fillable = [
        'order_number',
        'erp_order_id',
        'status_id',
        'user_id',
        'source',
        'locale',
        'currency_code',
        'currency_rate',
        'customer_name',
        'customer_email',
        'customer_phone',
        'billing_first_name',
        'billing_last_name',
        'billing_company',
        'billing_oib',
        'billing_vat_id',
        'billing_address_line_1',
        'billing_address_line_2',
        'billing_postal_code',
        'billing_city',
        'billing_state',
        'billing_country_code',
        'shipping_first_name',
        'shipping_last_name',
        'shipping_company',
        'shipping_oib',
        'shipping_vat_id',
        'shipping_address_line_1',
        'shipping_address_line_2',
        'shipping_postal_code',
        'shipping_city',
        'shipping_state',
        'shipping_country_code',
        'payment_method_code',
        'payment_method_name',
        'shipping_method_code',
        'shipping_method_name',
        'item_qty',
        'subtotal',
        'shipping_total',
        'payment_fee_total',
        'discount_total',
        'tax_total',
        'grand_total',
        'customer_note',
        'admin_note',
        'payload',
        'placed_at',
        'paid_at',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'status_id' => 'int',
        'user_id' => 'int',
        'currency_rate' => 'decimal:6',
        'item_qty' => 'int',
        'subtotal' => 'decimal:2',
        'shipping_total' => 'decimal:2',
        'payment_fee_total' => 'decimal:2',
        'discount_total' => 'decimal:2',
        'tax_total' => 'decimal:2',
        'grand_total' => 'decimal:2',
        'payload' => 'array',
        'placed_at' => 'datetime',
        'paid_at' => 'datetime',
    ];

    public function status(): BelongsTo
    {
        return $this->belongsTo(OrderStatus::class, 'status_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class, 'order_id')
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    public function totals(): HasMany
    {
        return $this->hasMany(OrderTotal::class, 'order_id')
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    public function history(): HasMany
    {
        return $this->hasMany(OrderHistory::class, 'order_id')
            ->orderByDesc('id');
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(OrderTransaction::class, 'order_id')
            ->orderByDesc('processed_at')
            ->orderByDesc('id');
    }

    public function loyaltyTransactions(): HasMany
    {
        return $this->hasMany(LoyaltyTransaction::class, 'order_id')
            ->orderByDesc('id');
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
