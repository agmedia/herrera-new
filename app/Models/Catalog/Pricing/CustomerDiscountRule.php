<?php

namespace App\Models\Catalog\Pricing;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Customer agreements survive publication of a new primary-group catalog. */
class CustomerDiscountRule extends Model
{
    use SoftDeletes;

    protected $table = 'customer_discount_rules';

    protected $fillable = ['user_id', 'name', 'percent', 'manufacturer_ids', 'category_ids', 'include_descendants', 'excluded_product_ids', 'starts_at', 'ends_at', 'priority', 'is_active', 'created_by', 'updated_by', 'source_system', 'source_key', 'payload'];

    protected $attributes = ['manufacturer_ids' => '[]', 'category_ids' => '[]', 'excluded_product_ids' => '[]', 'include_descendants' => true, 'priority' => 0, 'is_active' => true];

    protected function casts(): array
    {
        return ['user_id' => 'integer', 'percent' => 'decimal:4', 'manufacturer_ids' => 'array', 'category_ids' => 'array', 'include_descendants' => 'boolean', 'excluded_product_ids' => 'array', 'starts_at' => 'datetime', 'ends_at' => 'datetime', 'priority' => 'integer', 'is_active' => 'boolean', 'payload' => 'array'];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
