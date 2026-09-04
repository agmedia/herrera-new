<?php

namespace App\Models\Catalog\Option;

use App\Models\Catalog\Product\Product;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Option extends Model
{
    public const TYPE_SELECT = 'select';
    public const TYPE_RADIO = 'radio';
    public const TYPE_CHECKBOX = 'checkbox';
    public const PAYLOAD_SHOW_ON_PRODUCT_PAGE = 'show_on_product_page';

    protected $table = 'catalog_options';

    protected $fillable = [
        'code',
        'type',
        'is_active',
        'sort_order',
        'payload',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'is_active' => 'bool',
        'sort_order' => 'int',
        'payload' => 'array',
    ];

    /**
     * @return array<int, string>
     */
    public static function availableTypes(): array
    {
        return [
            self::TYPE_SELECT,
            self::TYPE_RADIO,
            self::TYPE_CHECKBOX,
        ];
    }

    public function translations(): HasMany
    {
        return $this->hasMany(OptionTranslation::class, 'option_id');
    }

    public function translation(string $locale): HasOne
    {
        return $this->hasOne(OptionTranslation::class, 'option_id')
            ->where('locale', $locale);
    }

    public function values(): HasMany
    {
        return $this->hasMany(OptionValue::class, 'option_id')
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'catalog_option_product', 'option_id', 'product_id')
            ->withPivot(['sort_order', 'is_required'])
            ->withTimestamps();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'updated_by');
    }

    public function showsOnProductPage(): bool
    {
        $payload = (array) ($this->payload ?? []);

        if (! array_key_exists(self::PAYLOAD_SHOW_ON_PRODUCT_PAGE, $payload)) {
            return true;
        }

        return filter_var($payload[self::PAYLOAD_SHOW_ON_PRODUCT_PAGE], FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? true;
    }
}
