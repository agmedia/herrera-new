<?php

namespace App\Services\User;

use Illuminate\Support\Facades\Validator;

class DeviceLocation
{
    /** Optional one-time reading supplied by the browser for this action. */
    public static function from(mixed $input): ?array
    {
        if (! is_array($input) || count(array_filter($input, fn ($v) => $v !== null && $v !== '')) === 0) {
            return null;
        }
        $coordinates = Validator::make($input, [
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'accuracy' => ['required', 'numeric', 'between:0,1000000'],
        ])->validate();

        return [
            'latitude' => round((float) $coordinates['latitude'], 6),
            'longitude' => round((float) $coordinates['longitude'], 6),
            'accuracy' => (int) ceil((float) $coordinates['accuracy']),
            'recorded_at' => now()->toIso8601String(),
        ];
    }
}
