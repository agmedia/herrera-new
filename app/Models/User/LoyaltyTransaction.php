<?php

namespace App\Models\User;

use App\Models\Sales\Order\Order;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LoyaltyTransaction extends Model
{
    protected $fillable = [
        'user_id',
        'order_id',
        'event_key',
        'type',
        'points',
        'note',
        'payload',
        'created_by',
    ];

    protected $casts = [
        'user_id' => 'int',
        'order_id' => 'int',
        'points' => 'int',
        'payload' => 'array',
        'created_by' => 'int',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'user_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'created_by');
    }
}
