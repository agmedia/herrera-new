<?php

namespace Tests\Feature\Import;

use App\Services\Import\HerreraCutoverSafety;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class HerreraCutoverSafetyTest extends TestCase
{
    #[DataProvider('unsafeConfigurations')]
    public function test_rehearsal_rejects_hosted_or_ambiguous_connections_before_copying(string $environment, string $target, string $source, string $host, string $date, string $prefix): void
    {
        $this->expectException(RuntimeException::class);
        (new HerreraCutoverSafety)->validate($environment,
            ['driver' => 'mysql', 'host' => $host, 'database' => $target],
            ['driver' => 'mysql', 'host' => $host, 'database' => $source], $prefix, $date);
    }

    public static function unsafeConfigurations(): array
    {
        return [
            'staging' => ['staging', 'herrera_new_migration', 'herrera_snapshot', '127.0.0.1', '2026-10-06', 'oc_'],
            'production' => ['production', 'herrera_new_migration', 'herrera_snapshot', '127.0.0.1', '2026-10-06', 'oc_'],
            'live destination' => ['local', 'herrera_shop', 'herrera_snapshot', '127.0.0.1', '2026-10-06', 'oc_'],
            'hosted staging destination' => ['local', 'herrera_redesign', 'herrera_snapshot', '127.0.0.1', '2026-10-06', 'oc_'],
            'same database' => ['local', 'herrera_new_migration', 'herrera_new_migration', '127.0.0.1', '2026-10-06', 'oc_'],
            'live source' => ['local', 'herrera_new_migration', 'herrera_shop', '127.0.0.1', '2026-10-06', 'oc_'],
            'remote host' => ['local', 'herrera_new_migration', 'herrera_snapshot', 'node251.myfcloudde.com', '2026-10-06', 'oc_'],
            'invalid prefix' => ['local', 'herrera_new_migration', 'herrera_snapshot', '127.0.0.1', '2026-10-06', 'oc_;'],
            'invalid date' => ['local', 'herrera_new_migration', 'herrera_snapshot', '127.0.0.1', '2026-02-31', 'oc_'],
        ];
    }

    public function test_only_generated_rehearsal_names_pass_the_audit_guard(): void
    {
        $this->assertTrue(HerreraCutoverSafety::rehearsalDatabase('herrera_rehearsal_target_20261014_101500_ab12'));
        $this->assertFalse(HerreraCutoverSafety::rehearsalDatabase('herrera_redesign'));
        $this->assertFalse(HerreraCutoverSafety::rehearsalDatabase('herrera_rehearsal_target_live'));
        $this->assertFalse(HerreraCutoverSafety::rehearsalDatabase('herrera_rehearsal_target_20261014_101500_ab12_extra'));
    }

    public function test_frozen_local_preview_and_source_are_accepted_for_preflight(): void
    {
        (new HerreraCutoverSafety)->validate('local',
            ['driver' => 'mysql', 'host' => '127.0.0.1', 'database' => 'herrera_new_migration'],
            ['driver' => 'mysql', 'host' => '127.0.0.1', 'database' => 'herrera_live_snapshot_20261006'], 'oc_', '2026-10-06');
        $this->addToAssertionCount(1);
    }

    public function test_completed_refresh_with_review_conflicts_can_continue_to_every_verification_gate(): void
    {
        [$report, $catalog] = $this->checkpoint();
        $report['refresh_import']['final_sync_conflicts'] = 1381;
        HerreraCutoverSafety::assertRefreshCheckpoint($report, 'old', 'fresh', $catalog);
        $this->addToAssertionCount(1);
    }

    #[DataProvider('invalidCheckpoints')]
    public function test_incomplete_or_mismatched_refresh_cannot_skip_importing(string $path, mixed $value): void
    {
        [$report, $catalog] = $this->checkpoint();
        if (str_starts_with($path, 'report.')) {
            data_set($report, substr($path, 7), $value);
        } else {
            data_set($catalog, $path, $value);
        }
        $this->expectException(RuntimeException::class);
        HerreraCutoverSafety::assertRefreshCheckpoint($report, 'old', 'fresh', $catalog);
    }

    public static function invalidCheckpoints(): array
    {
        return [
            'partial baseline' => ['report.baseline_import.final_sync', false],
            'different baseline' => ['report.baseline_import.source_snapshot', 'other'],
            'partial refresh' => ['report.refresh_import.final_sync', false],
            'different fresh source' => ['report.refresh_import.source_snapshot', 'other'],
            'different catalog identity' => ['report.refresh_import.draft_price_catalog_id', 7],
            'published catalog' => ['status', 'active'],
            'native catalog' => ['source_system', 'manual'],
            'different catalog source' => ['source_snapshot', 'other'],
            'incomplete prices' => ['metadata.import_complete', false],
            'ordinary import' => ['metadata.final_sync', false],
            'missing checksum' => ['source_checksum', ''],
        ];
    }

    private function checkpoint(): array
    {
        return [
            ['baseline_import' => ['final_sync' => true, 'source_snapshot' => 'old'],
                'refresh_import' => ['final_sync' => true, 'source_snapshot' => 'fresh', 'draft_price_catalog_id' => 3]],
            ['id' => 3, 'status' => 'draft', 'source_system' => 'herrera-opencart', 'source_snapshot' => 'fresh',
                'metadata' => ['import_complete' => true, 'final_sync' => true], 'source_checksum' => str_repeat('a', 64)],
        ];
    }
}
