<?php

namespace App\Models\Sales\Offer;

use Illuminate\Database\Eloquent\Model;

class CustomerOfferDelivery extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['completed_at' => 'datetime'];
    }
}
