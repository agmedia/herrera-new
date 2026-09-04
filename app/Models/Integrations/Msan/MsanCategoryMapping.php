<?php

namespace App\Models\Integrations\Msan;

use App\Models\Catalog\Category\Category;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MsanCategoryMapping extends Model
{
    public const STATUS_UNMAPPED = 'unmapped';

    public const STATUS_MAPPED = 'mapped';

    public const STATUS_IGNORED = 'ignored';

    public const ENERGY_REQUIREMENT_INHERIT = 'inherit';

    public const ENERGY_REQUIREMENT_REQUIRED = 'required';

    public const ENERGY_REQUIREMENT_NOT_APPLICABLE = 'not_applicable';

    protected $fillable = [
        'msan_category_id',
        'local_category_id',
        'status',
        'eprel_product_group',
        'energy_requirement',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'msan_category_id' => 'int',
            'local_category_id' => 'int',
            'updated_by' => 'int',
        ];
    }

    /** @return array<string, string> */
    public static function energyRequirementOptions(): array
    {
        return [
            self::ENERGY_REQUIREMENT_INHERIT => 'Automatski prema dostupnim podacima',
            self::ENERGY_REQUIREMENT_REQUIRED => 'Energetska oznaka je obavezna',
            self::ENERGY_REQUIREMENT_NOT_APPLICABLE => 'Energetska oznaka nije primjenjiva',
        ];
    }

    public function msanCategory(): BelongsTo
    {
        return $this->belongsTo(MsanCategory::class, 'msan_category_id');
    }

    public function localCategory(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'local_category_id');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
