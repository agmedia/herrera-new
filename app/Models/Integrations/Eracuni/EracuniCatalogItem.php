<?php

namespace App\Models\Integrations\Eracuni;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EracuniCatalogItem extends Model
{
    protected $guarded = [];

    // Raw supplier records are private; the admin renders the reviewed plan.
    protected $hidden = ['source_payload'];

    protected function casts(): array
    {
        return ['source_payload' => 'array', 'plan' => 'array'];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(EracuniCatalogRun::class, 'run_id');
    }
}
