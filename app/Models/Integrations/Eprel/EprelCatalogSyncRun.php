<?php

namespace App\Models\Integrations\Eprel;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EprelCatalogSyncRun extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['started_at' => 'datetime', 'completed_at' => 'datetime', 'planning_complete' => 'bool'];
    }

    public function items(): HasMany
    {
        return $this->hasMany(EprelCatalogSyncItem::class, 'run_id');
    }
}
