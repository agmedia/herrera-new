<?php

namespace App\Observers\Sales;

use App\Models\Sales\ContractWithdrawalItem;
use App\Models\Sales\Order\Order;
use App\Models\Settings\Local\OrderStatus;
use Illuminate\Validation\ValidationException;

class OrderReturnCancellationObserver
{
    public function updating(Order $order): void
    {
        $cancelled = OrderStatus::query()->whereKey($order->status_id);
        \App\Support\OrderStatusClassification::applyCancelledQuery($cancelled);
        if ($order->isDirty('status_id') && $cancelled->exists()
            && ContractWithdrawalItem::query()->whereIn('order_item_id', $order->items()->select('id'))->where('received_quantity', '>', 0)->exists()) {
            throw ValidationException::withMessages(['form.status_id' => 'Narudžba s fizički zaprimljenim povratom ne može se stornirati. Povrat završite kroz evidenciju povrata.']);
        }
    }
}
