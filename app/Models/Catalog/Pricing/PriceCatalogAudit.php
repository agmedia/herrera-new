<?php

namespace App\Models\Catalog\Pricing;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PriceCatalogAudit extends Model
{
    protected $table = 'catalog_price_catalog_audits';

    public $timestamps = false;

    protected $fillable = ['price_catalog_id', 'actor_id', 'event', 'payload', 'created_at'];

    protected function casts(): array
    {
        return ['payload' => 'array', 'created_at' => 'datetime'];
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
