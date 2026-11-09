<?php

namespace App\Models\User;

use App\Models\Catalog\Product\Product;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class CustomerCatalogFeed extends Model
{
    protected $fillable = ['user_id', 'token_hash', 'is_enabled', 'include_purchased_products', 'token_issued_at', 'revoked_at', 'created_by', 'updated_by'];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return ['is_enabled' => 'boolean', 'include_purchased_products' => 'boolean', 'token_issued_at' => 'datetime', 'revoked_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'customer_catalog_feed_products', 'feed_id', 'product_id');
    }
}
