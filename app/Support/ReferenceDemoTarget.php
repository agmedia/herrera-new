<?php

namespace App\Support;

final class ReferenceDemoTarget
{
    public static function allows(array $target): bool
    {
        if (($target['driver'] ?? '') !== 'mysql' || ! empty($target['split_connections'])) {
            return false;
        }
        if (! empty($target['explicit_staging'])) {
            return ($target['environment'] ?? '') === 'staging'
                && ($target['database'] ?? '') === 'herrera_redesign'
                && ($target['app_url'] ?? '') === 'https://herrera.herrera.hr'
                && ($target['safe_mode'] ?? false) === true;
        }

        return ($target['environment'] ?? '') === 'local'
            && ($target['database'] ?? '') === 'herrera_new_migration'
            && in_array($target['host'] ?? '', ['127.0.0.1', 'localhost', '::1'], true);
    }
}
