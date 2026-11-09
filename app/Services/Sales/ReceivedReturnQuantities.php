<?php

namespace App\Services\Sales;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class ReceivedReturnQuantities
{
    /** Only physical receipts reduce sold quantities; request/approval status does not. */
    public function query(): Builder
    {
        return DB::table('contract_withdrawal_items')
            ->select('order_item_id')
            ->selectRaw('SUM(received_quantity) AS returned_quantity')
            ->where('received_quantity', '>', 0)
            ->groupBy('order_item_id');
    }
}
