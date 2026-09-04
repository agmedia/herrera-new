<?php

namespace App\Models\Catalog\Attribute;

use App\Models\Catalog\Product\Product;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Attribute extends Model
{
    public const TYPE_SELECT = 'select';

    public const TYPE_MULTI = 'multi';

    public const SOURCE_MANUAL = 'manual';

    public const SOURCE_MSAN_SPECIFICATION = 'msan_specification';

    public const SOURCE_TERMOL_DESCRIPTION = 'termol.hr description';

    public const SOURCE_KOZO_PRODUCTS = 'kozo_proizvodi';

    public const SOURCE_IMPORT = 'import';

    public const SOURCE_CATALOG_IMPORT = 'catalog_import';

    protected $table = 'catalog_attributes';

    protected $fillable = [
        'attribute_group_id',
        'code',
        'group_code',
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
            self::TYPE_MULTI,
        ];
    }

    public static function normalizeSource(mixed $source): string
    {
        if (is_scalar($source)) {
            $source = trim((string) $source);

            return match (strtolower($source)) {
                self::SOURCE_MANUAL => self::SOURCE_MANUAL,
                self::SOURCE_MSAN_SPECIFICATION => self::SOURCE_MSAN_SPECIFICATION,
                self::SOURCE_TERMOL_DESCRIPTION => self::SOURCE_TERMOL_DESCRIPTION,
                self::SOURCE_KOZO_PRODUCTS => self::SOURCE_KOZO_PRODUCTS,
                default => $source,
            };
        }

        if (! is_array($source)) {
            return '';
        }

        foreach (['system', 'provider', 'name', 'code'] as $key) {
            $candidate = is_scalar($source[$key] ?? null)
                ? self::normalizeSource($source[$key])
                : '';
            if ($candidate !== '') {
                return $candidate;
            }
        }

        return $source === [] ? '' : self::SOURCE_IMPORT;
    }

    public function sourceCode(): string
    {
        $source = self::normalizeSource(data_get($this->payload, 'source'));
        if ($source !== '') {
            return $source;
        }

        $importSources = data_get($this->payload, 'import_sources');

        return is_array($importSources) && $importSources !== []
                ? self::SOURCE_CATALOG_IMPORT
                : self::SOURCE_MANUAL;
    }

    public function isMsanManaged(): bool
    {
        return $this->sourceCode() === self::SOURCE_MSAN_SPECIFICATION;
    }

    protected static function booted(): void
    {
        static::saving(function (Attribute $attribute): void {
            $groupCode = trim((string) $attribute->group_code);
            $group = null;

            if ($attribute->attribute_group_id && ! $attribute->isDirty('group_code')) {
                $group = AttributeGroup::query()->find($attribute->attribute_group_id);
            }

            if (! $group && $groupCode !== '') {
                $group = AttributeGroup::query()->firstOrCreate(
                    ['code' => $groupCode],
                    [
                        'type' => in_array($attribute->type, self::availableTypes(), true)
                            ? $attribute->type
                            : self::TYPE_SELECT,
                        'sort_order' => (int) $attribute->sort_order,
                        'payload' => null,
                        'created_by' => $attribute->created_by,
                        'updated_by' => $attribute->updated_by,
                    ]
                );
            }

            if (! $group) {
                return;
            }

            if ($attribute->isMsanManaged()
                && self::normalizeSource(data_get($group->payload, 'source'))
                    !== self::SOURCE_MSAN_SPECIFICATION) {
                $groupPayload = is_array($group->payload) ? $group->payload : [];
                $groupPayload['source'] = self::SOURCE_MSAN_SPECIFICATION;
                $group->forceFill([
                    'payload' => $groupPayload,
                    'updated_by' => $attribute->updated_by,
                ])->save();
            }

            if ($attribute->exists
                && $attribute->isDirty('type')
                && in_array($attribute->type, self::availableTypes(), true)
                && $attribute->type !== $group->type) {
                $group->forceFill([
                    'type' => $attribute->type,
                    'updated_by' => $attribute->updated_by,
                ])->save();

                static::query()
                    ->where('attribute_group_id', $group->id)
                    ->whereKeyNot($attribute->getKey())
                    ->update(['type' => $attribute->type]);
            }

            $attribute->attribute_group_id = $group->id;
            $attribute->group_code = $group->code;
            $attribute->type = $group->type;
        });
    }

    public function translations(): HasMany
    {
        return $this->hasMany(AttributeTranslation::class, 'attribute_id');
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(AttributeGroup::class, 'attribute_group_id');
    }

    public function translation(string $locale): HasOne
    {
        return $this->hasOne(AttributeTranslation::class, 'attribute_id')
            ->where('locale', $locale);
    }

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'catalog_attribute_product', 'attribute_id', 'product_id')
            ->withPivot(['sort_order'])
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
}
