<?php

namespace App\Console\Commands;

use App\Data\Import\CatalogAdoptionPlan;
use App\Data\Import\CatalogImportBatch;
use App\Data\Import\CatalogImportPlan;
use App\Exceptions\Import\CatalogAdoptionConflictException;
use App\Exceptions\Import\CatalogImportConflictException;
use App\Models\Import\CatalogImportRun;
use App\Services\Import\CatalogImportService;
use App\Services\Import\CatalogSourceAdoptionService;
use Illuminate\Console\Command;
use InvalidArgumentException;
use JsonException;
use RuntimeException;
use Throwable;

class ImportNormalizedCatalog extends Command
{
    private const MAX_FILE_SIZE_BYTES = 52_428_800;

    protected $signature = 'catalog:import-normalized
        {file : Path to a normalized catalog JSON file}
        {--source= : Expected source identifier; must match the file when both are present}
        {--adopt : Plan or apply mappings to existing unmanaged catalog records}
        {--apply : Persist the selected adoption or import operation}
        {--json : Emit a machine-readable JSON result}
        {--details : List every planned operation in human-readable output}';

    protected $description = 'Safely plan, adopt, or import a normalized external catalog';

    public function handle(
        CatalogImportService $importService,
        CatalogSourceAdoptionService $adoptionService,
    ): int {
        try {
            $batch = $this->loadBatch((string) $this->argument('file'));

            return (bool) $this->option('adopt')
                ? $this->handleAdoption($batch, $adoptionService)
                : $this->handleImport($batch, $importService);
        } catch (CatalogAdoptionConflictException $exception) {
            return $this->renderPlanFailure('adoption', $exception->plan, $exception->getMessage());
        } catch (CatalogImportConflictException $exception) {
            return $this->renderPlanFailure('import', $exception->plan, $exception->getMessage());
        } catch (InvalidArgumentException|JsonException|RuntimeException $exception) {
            return $this->renderError($exception->getMessage());
        } catch (Throwable $exception) {
            report($exception);

            return $this->renderError('Catalog operation failed: '.$exception->getMessage());
        }
    }

    private function handleAdoption(
        CatalogImportBatch $batch,
        CatalogSourceAdoptionService $adoptionService,
    ): int {
        $plan = $adoptionService->plan($batch);

        if ($plan->hasConflicts()) {
            return $this->renderPlanFailure(
                'adoption',
                $plan,
                'Adoption plan contains conflicts. Nothing was written.',
            );
        }

        $applied = (bool) $this->option('apply');
        if ($applied) {
            $plan = $adoptionService->apply($batch);
        }

        return $this->renderSuccess(
            mode: 'adoption',
            applied: $applied,
            plan: $plan,
        );
    }

    private function handleImport(
        CatalogImportBatch $batch,
        CatalogImportService $importService,
    ): int {
        $plan = $importService->plan($batch);

        if ($plan->hasConflicts()) {
            return $this->renderPlanFailure(
                'import',
                $plan,
                'Import plan contains conflicts. Nothing was written.',
            );
        }

        $applied = (bool) $this->option('apply');
        $run = $applied ? $importService->apply($batch) : null;

        return $this->renderSuccess(
            mode: 'import',
            applied: $applied,
            plan: $plan,
            run: $run,
        );
    }

    private function renderSuccess(
        string $mode,
        bool $applied,
        CatalogAdoptionPlan|CatalogImportPlan $plan,
        ?CatalogImportRun $run = null,
    ): int {
        $result = $this->resultPayload(
            ok: true,
            mode: $mode,
            applied: $applied,
            plan: $plan,
            run: $run,
        );

        if ((bool) $this->option('json')) {
            $this->writeJson($result);

            return self::SUCCESS;
        }

        $operation = strtoupper($mode);
        $execution = $applied ? 'APPLY' : 'DRY RUN';
        $this->info("{$operation} {$execution} completed.");
        $this->line('Source: '.$plan->source);
        $this->line('Batch checksum: '.$plan->batchChecksum);

        if ($run !== null) {
            $this->line('Import run: #'.$run->getKey().' ('.$run->status.')');
        }

        $this->renderSummary($plan);

        if ((bool) $this->option('details')) {
            $this->renderOperations($plan->operations);
        }

        if (! $applied) {
            $this->warn('Dry run only. No database records were written.');
        } elseif ($mode === 'adoption') {
            $this->warn('Only source mappings were written. Catalog records were not imported or changed.');
        }

        return self::SUCCESS;
    }

    private function renderPlanFailure(
        string $mode,
        CatalogAdoptionPlan|CatalogImportPlan $plan,
        string $message,
    ): int {
        $result = $this->resultPayload(
            ok: false,
            mode: $mode,
            applied: false,
            plan: $plan,
            error: $message,
        );

        if ((bool) $this->option('json')) {
            $this->writeJson($result);

            return self::FAILURE;
        }

        $this->error($message);
        $this->line('Source: '.$plan->source);
        $this->line('Batch checksum: '.$plan->batchChecksum);
        $this->renderSummary($plan);
        $this->renderOperations($plan->conflicts());

        return self::FAILURE;
    }

    private function renderError(string $message): int
    {
        if ((bool) $this->option('json')) {
            $this->writeJson([
                'ok' => false,
                'error' => $message,
            ]);

            return self::FAILURE;
        }

        $this->error($message);

        return self::FAILURE;
    }

    /**
     * @return array<string, mixed>
     */
    private function resultPayload(
        bool $ok,
        string $mode,
        bool $applied,
        CatalogAdoptionPlan|CatalogImportPlan $plan,
        ?CatalogImportRun $run = null,
        ?string $error = null,
    ): array {
        return [
            'ok' => $ok,
            'mode' => $mode,
            'execution' => $applied ? 'apply' : 'dry-run',
            'applied' => $applied,
            'error' => $error,
            'source' => $plan->source,
            'batch_checksum' => $plan->batchChecksum,
            'summary' => $plan->summary(),
            'operations' => array_map(
                static fn (object $operation): array => $operation->toArray(),
                $plan->operations,
            ),
            'import_run' => $run === null ? null : [
                'id' => $run->getKey(),
                'status' => $run->status,
            ],
        ];
    }

    private function renderSummary(CatalogAdoptionPlan|CatalogImportPlan $plan): void
    {
        $rows = [];
        foreach ($plan->summary() as $label => $count) {
            $rows[] = [$label, $count];
        }

        $this->table(['Result', 'Count'], $rows);
    }

    /** @param list<object> $operations */
    private function renderOperations(array $operations): void
    {
        if ($operations === []) {
            return;
        }

        $rows = array_map(static function (object $operation): array {
            $data = $operation->toArray();

            return [
                (string) ($data['entity_type'] ?? ''),
                (string) ($data['source_id'] ?? ''),
                (string) ($data['action'] ?? ''),
                isset($data['local_id']) ? (string) $data['local_id'] : '-',
                implode('; ', is_array($data['messages'] ?? null) ? $data['messages'] : []),
            ];
        }, $operations);

        $this->table(['Entity', 'Source ID', 'Action', 'Local ID', 'Messages'], $rows);
    }

    /** @param array<string, mixed> $payload */
    private function writeJson(array $payload): void
    {
        $this->output->writeln(json_encode(
            $payload,
            JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ));
    }

    private function loadBatch(string $file): CatalogImportBatch
    {
        $path = realpath($file);
        if ($path === false || ! is_file($path)) {
            throw new RuntimeException("Normalized catalog file not found: {$file}");
        }

        if (! is_readable($path)) {
            throw new RuntimeException("Normalized catalog file is not readable: {$file}");
        }

        $size = filesize($path);
        if ($size === false || $size > self::MAX_FILE_SIZE_BYTES) {
            throw new RuntimeException('Normalized catalog file exceeds the 50 MiB safety limit.');
        }

        $json = file_get_contents($path);
        if ($json === false) {
            throw new RuntimeException("Unable to read normalized catalog file: {$file}");
        }

        $json = preg_replace('/^\xEF\xBB\xBF/', '', $json) ?? $json;
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        if (! is_array($data) || array_is_list($data)) {
            throw new InvalidArgumentException('Normalized catalog JSON must be a top-level object.');
        }

        $this->assertTopLevelKeys($data);

        $fileSource = $this->optionalString($data['source'] ?? null, 'source');
        $optionSource = $this->optionalString($this->option('source'), '--source');
        if ($fileSource !== null && $optionSource !== null && strtolower($fileSource) !== strtolower($optionSource)) {
            throw new InvalidArgumentException(
                "Source mismatch: file declares [{$fileSource}], while --source declares [{$optionSource}].",
            );
        }

        $source = $optionSource ?? $fileSource;
        if ($source === null) {
            throw new InvalidArgumentException('A source is required in the JSON file or through --source.');
        }

        $categories = $this->recordList($data, 'categories');
        $attributes = $this->recordList($data, 'attributes');
        $products = $this->recordList($data, 'products');

        if ($categories === [] && $attributes === [] && $products === []) {
            throw new InvalidArgumentException('Normalized catalog JSON must contain at least one record.');
        }

        return CatalogImportBatch::fromArrays(
            source: $source,
            categories: $categories,
            products: $products,
            attributes: $attributes,
        );
    }

    /** @param array<string, mixed> $data */
    private function assertTopLevelKeys(array $data): void
    {
        $allowed = ['schema_version', 'source', 'categories', 'attributes', 'products'];
        $unknown = array_values(array_diff(array_keys($data), $allowed));

        if ($unknown !== []) {
            throw new InvalidArgumentException(
                'Unknown top-level catalog JSON field(s): '.implode(', ', $unknown).'.',
            );
        }

        if (array_key_exists('schema_version', $data) && $data['schema_version'] !== 1) {
            throw new InvalidArgumentException('Unsupported normalized catalog schema_version; expected 1.');
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<array<string, mixed>>
     */
    private function recordList(array $data, string $key): array
    {
        $records = $data[$key] ?? [];
        if (! is_array($records) || ! array_is_list($records)) {
            throw new InvalidArgumentException("Normalized catalog field [{$key}] must be a JSON array.");
        }

        foreach ($records as $index => $record) {
            if (! is_array($record) || array_is_list($record)) {
                throw new InvalidArgumentException(
                    "Normalized catalog field [{$key}.{$index}] must be a JSON object.",
                );
            }
        }

        return $records;
    }

    private function optionalString(mixed $value, string $label): ?string
    {
        if ($value === null) {
            return null;
        }

        if (! is_string($value)) {
            throw new InvalidArgumentException("Normalized catalog {$label} must be a string.");
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
