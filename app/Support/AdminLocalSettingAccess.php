<?php

namespace App\Support;

use App\Models\User;

class AdminLocalSettingAccess
{
    /** @return array<int, string> */
    public static function abilities(string $resource, bool $write = false): array
    {
        $granular = match ($resource) {
            'currencies' => 'settings.currencies.manage',
            'languages' => 'settings.languages.manage',
            'geo-zones', 'geo-zone-countries', 'regions' => $write ? null : 'settings.regions.view',
            default => null,
        };

        return array_values(array_filter(['settings.local.manage', $granular]));
    }

    public static function allows(?User $user, string $resource, bool $write = false): bool
    {
        if (! $user) {
            return false;
        }

        if ($user->isA('superadmin')) {
            return true;
        }

        foreach (self::abilities($resource, $write) as $ability) {
            if ($user->can($ability)) {
                return true;
            }
        }

        return false;
    }
}
