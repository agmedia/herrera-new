<?php

namespace App\Models\Sales\Order;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderTransaction extends Model
{
    protected $table = 'order_transactions';

    protected $fillable = [
        'order_id',
        'provider',
        'transaction_ref',
        'status',
        'amount',
        'currency_code',
        'processed_at',
        'payload',
        'created_by',
    ];

    protected $casts = [
        'order_id' => 'int',
        'amount' => 'decimal:2',
        'processed_at' => 'datetime',
        'payload' => 'array',
        'created_by' => 'int',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
