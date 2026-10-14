<?php

namespace App\Models\User;

use App\Models\Sales\Order\Order;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerVisit extends Model
{
    protected $table = 'customer_visits';

    protected $fillable = ['customer_user_id', 'sales_rep_id', 'performed_by_id', 'visited_at', 'contact_type', 'outcome', 'order_id', 'quote_reference', 'note'];

    protected function casts(): array
    {
        return ['visited_at' => 'datetime'];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_user_id');
    }

    public function salesRep(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sales_rep_id');
    }

    public function performedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
