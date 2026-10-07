<?php

namespace App\Models\Integrations\Eracuni;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EracuniCatalogRun extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['summary' => 'array', 'started_at' => 'datetime', 'completed_at' => 'datetime'];
    }

    public function items(): HasMany
    {
        return $this->hasMany(EracuniCatalogItem::class, 'run_id');
    }
}
