<?php

namespace App\Console\Commands;

use App\Services\Import\HerreraOpenCartImportService;
use App\Services\Import\HerreraStaffImportService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class HerreraImportStaff extends Command
{
    protected $signature = 'herrera:import-staff {--source-connection=herrera_source} {--prefix=oc_} {--legacy-config= : Verified legacy server configuration} {--include-missing-customers : Import only unmapped assigned customers before staff; requires --apply} {--apply : Apply the validated import; default is dry-run}';

    protected $description = 'Restore Herrera staff, original password compatibility and assigned-customer permissions';

    public function handle(HerreraStaffImportService $importer, HerreraOpenCartImportService $customers): int
    {
        try {
            if ($this->option('include-missing-customers') && ! $this->option('apply')) {
                throw new RuntimeException('The assigned-customer prerequisite requires --apply.');
            }
            $connection = (string) $this->option('source-connection');
            if ($path = $this->option('legacy-config')) {
                if (! app()->environment('staging') || realpath($path) !== '/home/herrera/public_html/upload/config.php') {
                    throw new RuntimeException('The legacy server configuration is restricted to the verified staging import.');
                }
                // This is the existing, trusted shop configuration; values never leave this process.
                ob_start();
                require $path;
                ob_end_clean();
                foreach (['DB_HOSTNAME', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD', 'DB_PREFIX'] as $constant) {
                    if (! defined($constant)) {
                        throw new RuntimeException('The legacy configuration is incomplete.');
                    }
                }
                $connection = 'herrera_staff_source';
                config(['database.connections.'.$connection => ['driver' => 'mysql', 'host' => DB_HOSTNAME,
                    'port' => defined('DB_PORT') ? DB_PORT : 3306, 'database' => DB_DATABASE,
                    'username' => DB_USERNAME, 'password' => DB_PASSWORD, 'charset' => 'utf8mb4',
                    'collation' => 'utf8mb4_unicode_ci', 'prefix' => '', 'strict' => true]]);
                if ($this->option('prefix') !== DB_PREFIX) {
                    throw new RuntimeException('The configured source prefix differs from the requested prefix.');
                }
            }
            $source = DB::connection($connection);
            // The source transaction is explicitly read-only, including when using production credentials.
            if ($source->getDriverName() === 'mysql') {
                $source->statement('SET TRANSACTION READ ONLY');
            }
            $source->beginTransaction();
            try {
                $report = $this->option('apply') && $this->option('include-missing-customers')
                    ? DB::transaction(fn (): array => $customers->importMissingAssignedCustomers($connection, (string) $this->option('prefix'))
                        + $importer->import($source, (string) $this->option('prefix'), true))
                    : $importer->import($source, (string) $this->option('prefix'), (bool) $this->option('apply'));
            } finally {
                $source->rollBack();
            }
            foreach ($report as $key => $value) {
                $this->line($key.': '.$value);
            }

            return self::SUCCESS;
        } catch (RuntimeException $e) {
            // Connection/query exceptions can include credentials or source SQL; do not print those.
            $this->error($e instanceof \Illuminate\Database\QueryException || $e instanceof \PDOException
                ? 'Staff import stopped: the database operation failed; no source data was changed.' : $e->getMessage());

            return self::FAILURE;
        }
    }
}
