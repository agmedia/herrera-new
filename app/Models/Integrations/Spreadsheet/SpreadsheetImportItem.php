<?php

namespace App\Models\Integrations\Spreadsheet;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SpreadsheetImportItem extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['old_values' => 'array', 'new_values' => 'array'];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(SpreadsheetImportRun::class, 'run_id');
    }
}
