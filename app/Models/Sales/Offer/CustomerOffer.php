<?php

namespace App\Models\Sales\Offer;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CustomerOffer extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['customer_snapshot' => 'array', 'valid_until' => 'date', 'priced_at' => 'datetime', 'sent_at' => 'datetime',
            'net_total' => 'decimal:4', 'tax_total' => 'decimal:4', 'gross_total' => 'decimal:4'];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_user_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(CustomerOfferItem::class)->orderBy('sort_order')->orderBy('id');
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(CustomerOfferDelivery::class)->orderByDesc('id');
    }
}
