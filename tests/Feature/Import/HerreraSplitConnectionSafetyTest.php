<?php

namespace Tests\Feature\Import;

use App\Services\Import\HerreraCutoverSafety;
use App\Services\Import\HerreraHistoricalCrmImportService;
use App\Services\Import\HerreraLocalDatabaseCopy;
use App\Services\Import\HerreraOpenCartImportService;
use App\Services\Import\HerreraStaffImportService;
use Closure;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class HerreraSplitConnectionSafetyTest extends TestCase
{
    #[DataProvider('splitConfigurations')]
    public function test_every_local_import_entry_point_rejects_split_connections_before_opening_a_pdo(string $entry, string $side, string $split): void
    {
        $original = DB::getDefaultConnection();
        $base = ['driver' => 'mysql', 'host' => '127.0.0.1', 'port' => 3306,
            'username' => 'never_connect', 'password' => 'never_connect', 'prefix' => ''];
        $target = $base + ['database' => 'herrera_rehearsal_target_20261014_101500_ab12'];
        $source = $base + ['database' => 'herrera_live_snapshot_guard'];
        $overrides = $split === 'read' ? ['read' => ['host' => 'remote.invalid'], 'write' => []]
            : ['write' => ['host' => 'remote.invalid']];
        if ($side === 'source') {
            $source += $overrides;
        } else {
            $target += $overrides;
        }
        config(['database.connections.guard_target' => $target, 'database.connections.guard_source' => $source]);
        DB::setDefaultConnection('guard_target');
        $targetConnection = DB::connection('guard_target');
        $sourceConnection = DB::connection('guard_source');
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });
        try {
            if ($entry === 'audit') {
                $this->artisan('herrera:audit-pricing', ['--source-connection' => 'guard_source'])
                    ->expectsOutput('Isolated rehearsals do not permit split read/write database connections.')->assertFailed();
            } else {
                try {
                    match ($entry) {
                        'safety' => (new HerreraCutoverSafety)->validate('local', array_replace($targetConnection->getConfig(), ['database' => 'herrera_new_migration']), $sourceConnection->getConfig(), 'oc_', '2026-10-14'),
                        'copy' => (new HerreraLocalDatabaseCopy)->copy($targetConnection, 'herrera_new_migration', 'herrera_rehearsal_target_20261014_101500_ab12'),
                        'staff' => app(HerreraStaffImportService::class)->assertFinalSyncContext($sourceConnection),
                        'primary' => app(HerreraOpenCartImportService::class)->import('guard_source', finalSync: true),
                        'historical' => app(HerreraHistoricalCrmImportService::class)->import($sourceConnection, apply: true),
                    };
                    $this->fail('Split read/write configuration must be rejected.');
                } catch (RuntimeException $exception) {
                    $this->assertSame('Isolated rehearsals do not permit split read/write database connections.', $exception->getMessage());
                }
            }
            $this->assertSame([], $queries);
            $this->assertInstanceOf(Closure::class, $targetConnection->getRawPdo());
            $this->assertInstanceOf(Closure::class, $sourceConnection->getRawPdo());
            if ($split === 'read') {
                $connection = $side === 'source' ? $sourceConnection : $targetConnection;
                // Laravel's resolved write config loses the original split keys.
                $this->assertNull($connection->getConfig('read'));
                $this->assertInstanceOf(Closure::class, $connection->getRawReadPdo());
            }
        } finally {
            DB::setDefaultConnection($original);
            DB::purge('guard_target');
            DB::purge('guard_source');
        }
    }

    public static function splitConfigurations(): array
    {
        $cases = [];
        foreach (['safety', 'staff', 'primary', 'historical', 'audit'] as $entry) {
            foreach (['source', 'target'] as $side) {
                foreach (['read', 'write'] as $split) {
                    $cases[$entry.' '.$side.' '.$split] = [$entry, $side, $split];
                }
            }
        }
        foreach (['read', 'write'] as $split) {
            $cases['copy administrator '.$split] = ['copy', 'target', $split];
        }

        return $cases;
    }

    public function test_empty_split_overrides_do_not_reject_an_otherwise_ordinary_configuration(): void
    {
        HerreraCutoverSafety::assertNoSplitConnection(['read' => [], 'write' => null]);
        HerreraCutoverSafety::assertNoSplitConnection(['driver' => 'mysql', 'host' => '127.0.0.1']);
        $this->addToAssertionCount(2);
    }

    public function test_stale_original_name_in_a_lazy_alias_cannot_hide_its_remote_read_override(): void
    {
        $original = DB::getDefaultConnection();
        config(['database.connections.guard_stale_name' => [
            'driver' => 'mysql', 'host' => '127.0.0.1', 'port' => 3306, 'prefix' => '',
            'database' => 'herrera_rehearsal_target_20261014_101500_ab12',
            'username' => 'never_connect', 'password' => 'never_connect', 'name' => $original,
            'read' => ['host' => 'remote.invalid'], 'write' => [],
        ]]);
        $connection = DB::connection('guard_stale_name');
        $this->assertSame($original, $connection->getName());
        $this->assertNull($connection->getConfig('read'));
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });
        try {
            HerreraCutoverSafety::assertNoSplitConnection($connection->getConfig());
            $this->fail('A copied stale connection name must not hide split overrides.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Isolated rehearsals do not permit split read/write database connections.', $exception->getMessage());
            $this->assertSame([], $queries);
            $this->assertInstanceOf(Closure::class, $connection->getRawPdo());
            $this->assertInstanceOf(Closure::class, $connection->getRawReadPdo());
        } finally {
            DB::purge('guard_stale_name');
        }
    }
}
