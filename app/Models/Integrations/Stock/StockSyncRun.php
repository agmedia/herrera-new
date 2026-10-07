<?php

namespace App\Models\Integrations\Stock;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StockSyncRun extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['summary' => 'array', 'started_at' => 'datetime', 'completed_at' => 'datetime'];
    }

    public function items(): HasMany
    {
        return $this->hasMany(StockSyncItem::class, 'run_id');
    }
}
