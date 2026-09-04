<?php

namespace App\Observers\Sales;

use App\Models\Sales\Order\Order;
use App\Models\Settings\Local\OrderStatus;
use App\Services\Loyalty\LoyaltyService;

class OrderLoyaltyObserver
{
    public function created(Order $order): void
    {
        $this->syncSettlement($order);
    }

    public function updated(Order $order): void
    {
        if (! $order->wasChanged('status_id')) {
            return;
        }

        $this->syncSettlement($order);
    }

    private function syncSettlement(Order $order): void
    {
        $status = OrderStatus::query()->find((int) $order->status_id);

        app(LoyaltyService::class)->syncOrderSettlement(
            $order,
            $status,
            auth()->id(),
        );
    }
}
