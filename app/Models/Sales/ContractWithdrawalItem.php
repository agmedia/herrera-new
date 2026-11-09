<?php

namespace App\Models\Sales;

use App\Models\Sales\Order\OrderItem;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ContractWithdrawalItem extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'withdrawal_id' => 'int', 'order_item_id' => 'int', 'product_id' => 'int',
        'product_option_value_id' => 'int', 'quantity' => 'int',
        'received_quantity' => 'int', 'restocked_quantity' => 'int',
    ];

    public function withdrawal(): BelongsTo
    {
        return $this->belongsTo(ContractWithdrawal::class, 'withdrawal_id');
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }

    public function receipts(): HasMany
    {
        return $this->hasMany(ContractWithdrawalReceipt::class, 'withdrawal_item_id');
    }
}
