<?php

namespace App\Models\Integrations;

use Illuminate\Database\Eloquent\Model;

class SupplierDeliverySchedule extends Model
{
    protected $fillable = ['supplier', 'name', 'aliases', 'monthly_days', 'weekday', 'next_order_date', 'lead_days', 'is_active'];

    protected function casts(): array
    {
        return ['aliases' => 'array', 'monthly_days' => 'array', 'weekday' => 'integer', 'next_order_date' => 'immutable_date', 'lead_days' => 'integer', 'is_active' => 'boolean'];
    }
}
