<?php

namespace App\Models\Catalog\Product;

use Illuminate\Database\Eloquent\Model;

class ProductClearance extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['coverage_from' => 'date', 'snapshot_date' => 'date', 'last_outgoing_at' => 'date', 'confirmed_company_scope' => 'boolean', 'category_added' => 'boolean', 'base_price_at_activation' => 'decimal:4', 'activated_at' => 'datetime', 'retired_at' => 'datetime', 'manually_reactivated_at' => 'datetime'];
    }
}
