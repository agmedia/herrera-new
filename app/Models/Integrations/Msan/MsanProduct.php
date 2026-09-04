<?php

namespace App\Models\Integrations\Msan;

use App\Models\Catalog\Product\Product;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MsanProduct extends Model
{
    public const MATCH_UNMATCHED = 'unmatched';

    public const MATCH_MATCHED = 'matched';

    public const MATCH_CONFLICT = 'conflict';

    public const MATCH_IGNORED = 'ignored';

    public const IMPORT_PENDING = 'pending';

    public const IMPORT_QUEUED = 'queued';

    public const IMPORT_IMPORTING = 'importing';

    public const IMPORT_IMPORTED = 'imported';

    public const IMPORT_FAILED = 'failed';

    public const IMPORT_SKIPPED = 'skipped';

    /** @var list<string> */
    public const IMPORT_READY_STATUSES = [
        self::IMPORT_PENDING,
        self::IMPORT_FAILED,
        self::IMPORT_SKIPPED,
    ];

    public const EPREL_PENDING = 'pending';

    public const EPREL_EXACT = 'exact';

    public const EPREL_NO_MATCH = 'no_match';

    public const EPREL_INVALID = 'invalid';

    protected $fillable = [
        'external_code',
        'name',
        'product_type',
        'brand',
        'model',
        'part_number',
        'warranty_months',
        'package_weight_kg',
        'package_length_cm',
        'package_width_cm',
        'package_height_cm',
        'technical_description',
        'marketing_description',
        'image_url',
        'barcodes',
        'currency_code',
        'list_price',
        'discount_percent',
        'partner_price',
        'recommended_retail_price',
        'availability_level',
        'on_promotion',
        'selected',
        'is_stale',
        'local_product_id',
        'match_status',
        'import_status',
        'eprel_match_status',
        'eprel_identifier_checksum',
        'eprel_checked_at',
        'catalog_checksum',
        'price_checksum',
        'availability_checksum',
        'specifications_checksum',
        'catalog_synced_at',
        'price_synced_at',
        'availability_synced_at',
        'specifications_synced_at',
        'last_seen_at',
        'last_imported_at',
        'last_error',
        'payload',
    ];

    protected function casts(): array
    {
        return [
            'warranty_months' => 'int',
            'package_weight_kg' => 'decimal:3',
            'package_length_cm' => 'decimal:3',
            'package_width_cm' => 'decimal:3',
            'package_height_cm' => 'decimal:3',
            'barcodes' => 'array',
            'list_price' => 'decimal:4',
            'discount_percent' => 'decimal:4',
            'partner_price' => 'decimal:4',
            'recommended_retail_price' => 'decimal:4',
            'availability_level' => 'int',
            'on_promotion' => 'bool',
            'selected' => 'bool',
            'is_stale' => 'bool',
            'local_product_id' => 'int',
            'eprel_checked_at' => 'datetime',
            'catalog_synced_at' => 'datetime',
            'price_synced_at' => 'datetime',
            'availability_synced_at' => 'datetime',
            'specifications_synced_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'last_imported_at' => 'datetime',
            'payload' => 'array',
        ];
    }

    public function localProduct(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'local_product_id');
    }

    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(
            MsanCategory::class,
            'msan_product_categories',
            'msan_product_id',
            'msan_category_id'
        )->withPivot('last_seen_at')->withTimestamps();
    }

    public function specifications(): HasMany
    {
        return $this->hasMany(MsanProductSpecification::class, 'msan_product_id')
            ->orderBy('item_order')
            ->orderBy('id');
    }
}
