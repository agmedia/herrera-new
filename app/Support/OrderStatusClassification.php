<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder;

class OrderStatusClassification
{
    public const CANCELLED_NAMES = ['cancelled', 'canceled', 'otkazano', 'otkazana', 'stornirano', 'stornirana', 'storno'];

    public static function applyCancelledQuery(Builder|EloquentBuilder $query, string $prefix = ''): void
    {
        $query->where(function ($query) use ($prefix): void {
            $query->whereRaw('COALESCE('.$prefix.'is_cancelled, 0) = 1')
                ->orWhereIn(\Illuminate\Support\Facades\DB::raw('LOWER(TRIM(COALESCE('.$prefix."code, '')))"), self::CANCELLED_NAMES)
                ->orWhereIn(\Illuminate\Support\Facades\DB::raw('LOWER(TRIM(COALESCE('.$prefix."name, '')))"), self::CANCELLED_NAMES);
        });
    }
}
