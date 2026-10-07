<?php

namespace App\Models\Integrations\Stock;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockSyncItem extends Model
{
    protected $guarded = [];

    public function run(): BelongsTo
    {
        return $this->belongsTo(StockSyncRun::class, 'run_id');
    }
}
