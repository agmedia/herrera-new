<?php

namespace App\Models\Sales\Order;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderTotal extends Model
{
    protected $fillable = [
        'order_id',
        'code',
        'title',
        'value',
        'sort_order',
        'payload',
    ];

    protected $casts = [
        'order_id' => 'int',
        'value' => 'decimal:2',
        'sort_order' => 'int',
        'payload' => 'array',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id');
    }
}
