<?php

namespace App\Services\Settings;

use App\Models\Settings\Local\Currency;
use App\Models\Settings\Local\GeoZone;
use App\Models\Settings\Local\GeoZoneCountry;
use App\Models\Settings\Local\Language;
use App\Models\Settings\Local\OrderStatus;
use App\Models\Settings\Local\PaymentMethod;
use App\Models\Settings\Local\ShippingMethod;
use App\Models\Settings\Local\TaxRate;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class LocalSettingsService
{
    /**
     * @return array<class-string>
     */
    public function models(): array
    {
        return [
            PaymentMethod::class,
            ShippingMethod::class,
            GeoZone::class,
            GeoZoneCountry::class,
            Currency::class,
            TaxRate::class,
            OrderStatus::class,
            Language::class,
        ];
    }

    /**
     * @param class-string $modelClass
     */
    public function all(string $modelClass): EloquentCollection
    {
        return Cache::remember(
            $this->cacheKey($modelClass, 'all'),
            now()->addHours(4),
            function () use ($modelClass): EloquentCollection {
                $query = $modelClass::query();
                if ($this->hasColumn($modelClass, 'sort_order')) {
                    $query->orderBy('sort_order');
                }
                return $query->orderByDesc('id')->get();
            }
        );
    }

    /**
     * @param class-string $modelClass
     */
    public function active(string $modelClass): EloquentCollection
    {
        return Cache::remember(
            $this->cacheKey($modelClass, 'active'),
            now()->addHours(4),
            function () use ($modelClass): EloquentCollection {
                $query = $modelClass::query();
                if ($this->hasColumn($modelClass, 'is_active')) {
                    $query->where('is_active', true);
                }
                if ($this->hasColumn($modelClass, 'sort_order')) {
                    $query->orderBy('sort_order');
                }
                return $query->orderByDesc('id')->get();
            }
        );
    }

    /**
     * @param class-string $modelClass
     */
    public function forget(string $modelClass): void
    {
        Cache::forget($this->cacheKey($modelClass, 'all'));
        Cache::forget($this->cacheKey($modelClass, 'active'));
    }

    public function flushAll(): void
    {
        foreach ($this->models() as $modelClass) {
            $this->forget($modelClass);
        }
    }

    /**
     * @param class-string $modelClass
     */
    private function cacheKey(string $modelClass, string $segment): string
    {
        $name = Str::snake(class_basename($modelClass));
        return "settings.local.{$name}.{$segment}";
    }

    /**
     * @param class-string $modelClass
     */
    private function hasColumn(string $modelClass, string $column): bool
    {
        return in_array($column, (new $modelClass())->getConnection()->getSchemaBuilder()->getColumnListing((new $modelClass())->getTable()), true);
    }
}
