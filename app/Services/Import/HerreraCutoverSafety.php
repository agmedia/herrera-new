<?php

namespace App\Services\Import;

use RuntimeException;

/** The rehearsal never accepts either hosted shop as a destination. */
class HerreraCutoverSafety
{
    public static function isolatedConnectionConfig(array $config): array
    {
        self::assertNoSplitConnection($config);
        // ConnectionFactory preserves an existing name. Eloquent uses it for
        // INSERT/UPDATE model instances, so copying it can route saves back to
        // the original database even when the builder selected from a clone.
        unset($config['name'], $config['url']);

        return $config;
    }

    public static function assertNoSplitConnection(array $connection): void
    {
        // Laravel removes these keys from the instantiated write connection.
        // Check its original named configuration before a lazy read PDO opens.
        $name = explode('::', (string) ($connection['name'] ?? ''))[0];
        $candidates = [$connection];
        if ($name !== '') {
            foreach ((array) config('database.connections', []) as $key => $configured) {
                if (! is_array($configured)) {
                    continue;
                }
                $declaredName = explode('::', (string) ($configured['name'] ?? ''))[0];
                // A copied config can retain its old name even though it was
                // registered under a different alias. Inspect both identities.
                if ((string) $key === $name || $declaredName === $name) {
                    $candidates[] = $configured;
                }
            }
        }
        foreach ($candidates as $candidate) {
            if (! empty($candidate['read']) || ! empty($candidate['write'])) {
                throw new RuntimeException('Isolated rehearsals do not permit split read/write database connections.');
            }
        }
    }

    public static function rehearsalDatabase(string $database): bool
    {
        return (bool) preg_match('/^herrera_rehearsal_target_[0-9]{8}_[0-9]{6}_[a-f0-9]{4}$/D', $database);
    }

    public static function assertRefreshCheckpoint(array $report, string $baseline, string $fresh, ?array $catalog): void
    {
        if (($report['baseline_import']['final_sync'] ?? null) !== true
            || ($report['baseline_import']['source_snapshot'] ?? '') !== $baseline
            || ($report['refresh_import']['final_sync'] ?? null) !== true
            || ($report['refresh_import']['source_snapshot'] ?? '') !== $fresh
            || ! $catalog || ($catalog['status'] ?? '') !== 'draft'
            || ($catalog['source_system'] ?? '') !== 'herrera-opencart'
            || ($catalog['source_snapshot'] ?? '') !== $fresh
            || ($catalog['metadata']['import_complete'] ?? null) !== true
            || ($catalog['metadata']['final_sync'] ?? null) !== true
            || empty($catalog['source_checksum'])
            || (int) ($catalog['id'] ?? 0) !== (int) ($report['refresh_import']['draft_price_catalog_id'] ?? 0)) {
            throw new RuntimeException('Only a completed local refresh with its unchanged source draft can continue to verification.');
        }
    }

    public function validate(string $environment, array $target, array $source, string $prefix, string $sourceDate): void
    {
        if ($environment !== 'local') {
            throw new RuntimeException('Cutover rehearsals run only on isolated local database copies.');
        }
        foreach ([$target, $source] as $connection) {
            self::assertNoSplitConnection($connection);
            if (! in_array($connection['driver'] ?? '', ['mysql', 'mariadb'], true)
                || ! in_array($connection['host'] ?? '', ['127.0.0.1', 'localhost', '::1'], true)
                || ! preg_match('/^[a-zA-Z0-9_]+$/D', (string) ($connection['database'] ?? ''))) {
                throw new RuntimeException('Only named MySQL/MariaDB databases on localhost are permitted.');
            }
        }
        if (($target['database'] ?? '') !== 'herrera_new_migration'
            || $source['database'] === $target['database']
            || ! preg_match('/^herrera_live_snapshot_[a-zA-Z0-9_]+$/D', $source['database'])) {
            throw new RuntimeException('Use the local Herrera preview and a separate frozen OpenCart snapshot.');
        }
        if (! preg_match('/^[a-zA-Z0-9_]+$/D', $prefix)
            || ! preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $sourceDate, $date)
            || ! checkdate((int) ($date[2] ?? 0), (int) ($date[3] ?? 0), (int) ($date[1] ?? 0))) {
            throw new RuntimeException('Provide a valid table prefix and the actual snapshot date (YYYY-MM-DD).');
        }
    }
}
