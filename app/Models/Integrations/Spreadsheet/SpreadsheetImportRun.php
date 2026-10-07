<?php

namespace App\Models\Integrations\Spreadsheet;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SpreadsheetImportRun extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['options' => 'array', 'summary' => 'array', 'started_at' => 'datetime', 'completed_at' => 'datetime'];
    }

    public function items(): HasMany
    {
        return $this->hasMany(SpreadsheetImportItem::class, 'run_id');
    }
}
