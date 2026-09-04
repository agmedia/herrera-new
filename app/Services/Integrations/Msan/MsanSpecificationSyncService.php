<?php

namespace App\Services\Integrations\Msan;

use App\Models\Integrations\Msan\MsanProduct;
use App\Models\Integrations\Msan\MsanSpecificationDefinition;
use App\Models\Integrations\Msan\MsanSpecificationSnapshot;
use App\Models\Integrations\Msan\MsanSyncRun;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class MsanSpecificationSyncService
{
    private const BATCH_SIZE = 400;

    private const MAX_DEFINITIONS = 50000;

    private const MAX_SPECIFICATIONS_PER_PRODUCT = 2000;

    private const MAX_VALUE_BYTES_PER_PRODUCT = 4 * 1024 * 1024;

    private const MAX_PARSE_BUFFER_BYTES = 4 * 1024 * 1024;

    public function __construct(
        private readonly MsanClient $client,
        private readonly MsanXmlStreamReader $xml,
        private readonly MsanSpecificationValuesParser $valuesParser,
        private readonly MsanSettingsService $settings,
        private readonly MsanSpecificationPublisher $publisher,
    ) {}

    public function sync(MsanSyncRun $run): MsanSyncRun
    {
        $run->forceFill([
            'status' => MsanSyncRun::STATUS_RUNNING,
            'started_at' => $run->started_at ?: now(),
            'completed_at' => null,
            'error_message' => null,
            'progress' => 1,
        ])->save();

        $this->cleanupAbandonedCandidates($run);
        $this->cleanupTemporaryDirectoriesForRun((int) $run->id);

        /** @var MsanSpecificationSnapshot|null $existingSnapshot */
        $existingSnapshot = MsanSpecificationSnapshot::query()
            ->where('msan_sync_run_id', $run->id)
            ->first();
        if ($existingSnapshot?->status === MsanSpecificationSnapshot::STATUS_ACTIVE) {
            return $this->resumePublishedSnapshot($run, $existingSnapshot);
        }
        if ($existingSnapshot?->status === MsanSpecificationSnapshot::STATUS_CANDIDATE) {
            // A worker may have been terminated without reaching the catch block.
            // Candidate rows are isolated from the live snapshot and safe to rebuild.
            $existingSnapshot->delete();
        } elseif ($existingSnapshot !== null) {
            throw new RuntimeException('Ovo izvršavanje specifikacija već ima dovršen snapshot koji više nije aktivan.');
        }

        if (! $this->targetProductsQuery()->exists()) {
            throw new RuntimeException('Odaberite ili najprije uvezite barem jedan M SAN artikl.');
        }

        $source = $this->settings->specificationsSource();
        $dataset = $source === MsanSettingsService::SPECIFICATIONS_SOURCE_ICECAT
            ? 'specifications_icecat'
            : 'specifications';
        $directory = 'integrations/msan/specifications/'.$run->id.'-'.bin2hex(random_bytes(5));
        $path = Storage::disk('local')->path($directory.'/specifications.xml');
        Storage::disk('local')->makeDirectory($directory);
        $snapshot = MsanSpecificationSnapshot::query()->create([
            'msan_sync_run_id' => $run->id,
            'status' => MsanSpecificationSnapshot::STATUS_CANDIDATE,
            'source' => $source,
        ]);

        try {
            $this->client->downloadDataset($dataset, $path);
            $run->forceFill(['progress' => 35])->save();

            $stats = $this->parseCandidate($snapshot, $path);
            $run->forceFill(['progress' => 75])->save();

            $this->validateCandidate($snapshot, $stats);
            // Shared definition metadata is promoted only after the isolated
            // candidate has passed all coverage and publishability checks.
            $this->refreshDefinitionMetadata($path);
            $previousSnapshot = MsanSpecificationSnapshot::query()
                ->where('status', MsanSpecificationSnapshot::STATUS_ACTIVE)
                ->latest('id')
                ->first();
            $this->activateSnapshot($snapshot, $stats);
            $publish = $this->publisher->publishSnapshot($snapshot->refresh());
            $this->pruneReplacedSnapshots((int) $snapshot->id);

            $this->completeRun($run, $snapshot, $stats, $publish);
        } catch (Throwable $exception) {
            $currentSnapshot = $snapshot->exists ? $snapshot->fresh() : null;
            if ($currentSnapshot?->status === MsanSpecificationSnapshot::STATUS_ACTIVE) {
                $this->restorePreviousSnapshot($currentSnapshot, $previousSnapshot ?? null);
            } elseif ($currentSnapshot?->status === MsanSpecificationSnapshot::STATUS_CANDIDATE) {
                $this->deleteCandidateSnapshot($currentSnapshot);
                $activeSnapshot = MsanSpecificationSnapshot::query()
                    ->where('status', MsanSpecificationSnapshot::STATUS_ACTIVE)
                    ->latest('id')
                    ->first();
                $this->restoreDefinitionStateForSnapshot($activeSnapshot);
                $this->restoreProjection($activeSnapshot);
            }
            $summary = is_array($run->summary) ? $run->summary : [];
            $summary['last_attempt_failed_at'] = now()->toIso8601String();
            $run->forceFill([
                'status' => MsanSyncRun::STATUS_RUNNING,
                'error_message' => $this->sanitizeError($exception->getMessage()),
                'summary' => $summary,
                'completed_at' => null,
            ])->save();

            throw $exception;
        } finally {
            Storage::disk('local')->deleteDirectory($directory);
        }

        return $run->refresh();
    }

    public function recoverFailedRun(int $runId): void
    {
        /** @var MsanSpecificationSnapshot|null $snapshot */
        $snapshot = MsanSpecificationSnapshot::query()
            ->where('msan_sync_run_id', $runId)
            ->first();

        if ($snapshot?->status === MsanSpecificationSnapshot::STATUS_ACTIVE) {
            $previousSnapshot = MsanSpecificationSnapshot::query()
                ->where('status', MsanSpecificationSnapshot::STATUS_REPLACED)
                ->whereKeyNot($snapshot->id)
                ->latest('id')
                ->first();
            $this->restorePreviousSnapshot($snapshot, $previousSnapshot);
        } elseif ($snapshot?->status === MsanSpecificationSnapshot::STATUS_CANDIDATE) {
            $this->deleteCandidateSnapshot($snapshot);
            $activeSnapshot = MsanSpecificationSnapshot::query()
                ->where('status', MsanSpecificationSnapshot::STATUS_ACTIVE)
                ->latest('id')
                ->first();
            $this->restoreDefinitionStateForSnapshot($activeSnapshot);
            $this->restoreProjection($activeSnapshot);
        } else {
            $this->restoreDefinitionStateForSnapshot(
                MsanSpecificationSnapshot::query()
                    ->where('status', MsanSpecificationSnapshot::STATUS_ACTIVE)
                    ->latest('id')
                    ->first(),
            );
        }

        $this->cleanupTemporaryDirectoriesForRun($runId);
    }

    /**
     * @return array<string, int>
     */
    private function targetProductsQuery(): Builder
    {
        return MsanProduct::query()
            ->where('is_stale', false)
            ->when($this->settings->specificationsSelectedOnly(), fn ($query) => $query
                ->where(fn ($selected) => $selected
                    ->where('selected', true)
                    ->orWhereNotNull('local_product_id')));
    }

    /**
     * @return array{rows:int,relevant_rows:int,products:int,definitions:int,source_bytes:int,source_checksum:string}
     */
    private function parseCandidate(
        MsanSpecificationSnapshot $snapshot,
        string $path,
    ): array {
        $seenAt = now();
        $definitionRows = [];
        $specificationRows = [];
        $definitionKeys = [];
        $rows = 0;
        $relevantRows = 0;
        $bufferBytes = 0;

        foreach ($this->xml->rows($path) as $row) {
            $code = trim((string) ($row['ProductCode'] ?? ''));
            $group = $this->text($row['SpecificationGroup'] ?? null, 255);
            $item = $this->text($row['SpecificationItemName'] ?? null, 255);
            if ($code === '' || mb_strlen($code) > 191 || $group === null || $item === null) {
                continue;
            }

            $rows++;
            $measure = $this->text($row['SpecificationItemMeasure'] ?? null, 100);
            $values = $this->valuesParser->parse($row['SpecificationItemValues'] ?? '');
            if ($values === []) {
                continue;
            }

            $sourceKey = $this->sourceKey($group, $item, $measure);
            if (! isset($definitionKeys[$sourceKey])) {
                if (count($definitionKeys) >= self::MAX_DEFINITIONS) {
                    throw new RuntimeException('M SAN snapshot sadrži previše različitih definicija specifikacija.');
                }
                $definitionKeys[$sourceKey] = true;
            }
            $encodedValues = json_encode(
                $values,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            );
            $definitionRows[$sourceKey] = [
                'source_key' => $sourceKey,
                'group_name' => $group,
                'item_name' => $item,
                'measure' => $measure,
                'source_for_filter' => $this->boolean($row['SpecificationItemForFilter'] ?? false),
                'data_role' => $this->detectedRole($group, $item),
                'sample_values' => json_encode(
                    array_slice($values, 0, 5),
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
                ),
                'last_seen_at' => $seenAt,
                'is_stale' => false,
                'created_at' => $seenAt,
                'updated_at' => $seenAt,
            ];

            $specificationRows[] = [
                'snapshot_id' => (int) $snapshot->id,
                'external_code' => $code,
                'source_key' => $sourceKey,
                'values_json' => $encodedValues,
                'item_order' => max(0, min(65535, (int) ($row['SpecificationItemNo'] ?? 0))),
                'last_seen_at' => $seenAt,
            ];
            $bufferBytes += strlen($encodedValues);

            if (count($definitionRows) >= self::BATCH_SIZE
                || count($specificationRows) >= self::BATCH_SIZE
                || $bufferBytes >= self::MAX_PARSE_BUFFER_BYTES) {
                $relevantRows += $this->flushRows($definitionRows, $specificationRows);
                $definitionRows = [];
                $specificationRows = [];
                $bufferBytes = 0;
            }
        }

        $relevantRows += $this->flushRows($definitionRows, $specificationRows);

        return [
            'rows' => $rows,
            'relevant_rows' => $relevantRows,
            'products' => (int) DB::table('msan_product_specifications')
                ->where('snapshot_id', $snapshot->id)
                ->distinct()
                ->count('msan_product_id'),
            'definitions' => count($definitionKeys),
            'source_bytes' => max(0, (int) filesize($path)),
            'source_checksum' => (string) hash_file('sha256', $path),
        ];
    }

    /**
     * @param  array<string, array<string, mixed>>  $definitions
     * @param  list<array<string, mixed>>  $specifications
     */
    private function flushRows(array $definitions, array $specifications): int
    {
        if ($definitions !== []) {
            // Existing live metadata must not be mutated by an unvalidated
            // candidate. New keys are inserted so candidate rows can resolve
            // their foreign keys; candidate-only keys are removed on failure.
            DB::table('msan_specification_definitions')->insertOrIgnore(
                array_values($definitions),
            );
        }
        if ($specifications === []) {
            return 0;
        }

        $productIds = $this->targetProductsQuery()
            ->whereIn('external_code', collect($specifications)->pluck('external_code')->unique()->all())
            ->pluck('id', 'external_code');

        $definitionIds = DB::table('msan_specification_definitions')
            ->whereIn('source_key', collect($specifications)->pluck('source_key')->unique()->all())
            ->pluck('id', 'source_key');
        $rows = [];
        $now = now();
        foreach ($specifications as $specification) {
            $productId = $productIds->get($specification['external_code']);
            $definitionId = $definitionIds->get($specification['source_key']);
            if (! $productId || ! $definitionId) {
                continue;
            }
            $values = (string) $specification['values_json'];
            $key = $specification['snapshot_id'].':'.$productId.':'.$definitionId;
            $rows[$key] = [
                'snapshot_id' => $specification['snapshot_id'],
                'msan_product_id' => (int) $productId,
                'definition_id' => (int) $definitionId,
                'values' => $values,
                'item_order' => $specification['item_order'],
                'checksum' => hash('sha256', $values),
                'last_seen_at' => $specification['last_seen_at'],
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if ($rows !== []) {
            DB::table('msan_product_specifications')->upsert(
                array_values($rows),
                ['snapshot_id', 'msan_product_id', 'definition_id'],
                ['values', 'item_order', 'checksum', 'last_seen_at', 'updated_at'],
            );
        }

        return count($rows);
    }

    /** @param array{rows:int,relevant_rows:int,products:int,definitions:int,source_bytes:int,source_checksum:string} $stats */
    private function validateCandidate(MsanSpecificationSnapshot $snapshot, array $stats): void
    {
        if ($stats['rows'] === 0 || $stats['definitions'] === 0 || $stats['relevant_rows'] === 0 || $stats['products'] === 0) {
            throw new RuntimeException('M SAN specifikacije su prazne ili ne sadrže odabrane/uvezene artikle.');
        }
        $valuesColumn = DB::connection()->getQueryGrammar()->wrap('values');
        $valueBytesExpression = DB::getDriverName() === 'sqlite'
            ? 'LENGTH(CAST('.$valuesColumn.' AS BLOB))'
            : 'OCTET_LENGTH('.$valuesColumn.')';
        $oversizedProductExists = DB::table('msan_product_specifications')
            ->where('snapshot_id', $snapshot->id)
            ->select('msan_product_id')
            ->groupBy('msan_product_id')
            ->havingRaw('COUNT(*) > ?', [self::MAX_SPECIFICATIONS_PER_PRODUCT])
            ->orHavingRaw('SUM('.$valueBytesExpression.') > ?', [self::MAX_VALUE_BYTES_PER_PRODUCT])
            ->exists();
        if ($oversizedProductExists) {
            throw new RuntimeException('M SAN snapshot sadrži previše specifikacija za pojedini artikl.');
        }

        $previousCount = (int) MsanSpecificationSnapshot::query()
            ->where('status', MsanSpecificationSnapshot::STATUS_ACTIVE)
            ->value('product_count');
        if ($previousCount > 0 && $stats['products'] < max(1, (int) floor($previousCount * 0.5))) {
            throw new RuntimeException('Novi M SAN snapshot specifikacija pokriva premalo artikala; prethodni snapshot je sačuvan.');
        }
    }

    /** @param array{rows:int,relevant_rows:int,products:int,definitions:int,source_bytes:int,source_checksum:string} $stats */
    private function activateSnapshot(MsanSpecificationSnapshot $snapshot, array $stats): void
    {
        DB::transaction(function () use ($snapshot, $stats): void {
            MsanSpecificationSnapshot::query()
                ->where('status', MsanSpecificationSnapshot::STATUS_ACTIVE)
                ->update(['status' => MsanSpecificationSnapshot::STATUS_REPLACED]);
            $snapshot->forceFill([
                'status' => MsanSpecificationSnapshot::STATUS_ACTIVE,
                'source_bytes' => $stats['source_bytes'],
                'source_checksum' => $stats['source_checksum'] !== '' ? $stats['source_checksum'] : null,
                'row_count' => $stats['rows'],
                'relevant_row_count' => $stats['relevant_rows'],
                'product_count' => $stats['products'],
                'activated_at' => now(),
            ])->save();

            MsanSpecificationDefinition::query()
                ->where(fn ($query) => $query
                    ->whereNull('last_seen_at')
                    ->orWhere('last_seen_at', '<', $snapshot->created_at))
                ->update(['is_stale' => true]);

            $snapshotId = (int) $snapshot->id;
            DB::table('msan_specification_definitions')->update([
                'product_count' => DB::raw(
                    '(SELECT COUNT(*) FROM msan_product_specifications '
                    .'WHERE snapshot_id = '.$snapshotId.' '
                    .'AND definition_id = msan_specification_definitions.id)'
                ),
            ]);
        }, 3);
    }

    private function resumePublishedSnapshot(
        MsanSyncRun $run,
        MsanSpecificationSnapshot $snapshot,
    ): MsanSyncRun {
        $publish = $this->publisher->publishSnapshot($snapshot);
        $stats = [
            'rows' => (int) $snapshot->row_count,
            'relevant_rows' => (int) $snapshot->relevant_row_count,
            'products' => (int) $snapshot->product_count,
            'definitions' => (int) MsanSpecificationDefinition::query()->where('is_stale', false)->count(),
            'source_bytes' => (int) $snapshot->source_bytes,
            'source_checksum' => (string) ($snapshot->source_checksum ?? ''),
        ];
        $this->completeRun($run, $snapshot, $stats, $publish);

        return $run->refresh();
    }

    /**
     * @param  array{rows:int,relevant_rows:int,products:int,definitions:int,source_bytes:int,source_checksum:string}  $stats
     * @param  array{products:int,specifications:int,energy_declarations:int,filter_attributes:int}  $publish
     */
    private function completeRun(
        MsanSyncRun $run,
        MsanSpecificationSnapshot $snapshot,
        array $stats,
        array $publish,
    ): void {
        $run->forceFill([
            'status' => MsanSyncRun::STATUS_COMPLETED,
            'progress' => 100,
            'total_count' => $stats['rows'],
            'processed_count' => $stats['relevant_rows'],
            'succeeded_count' => $stats['relevant_rows'],
            'skipped_count' => max(0, $stats['rows'] - $stats['relevant_rows']),
            'summary' => [
                'source' => $snapshot->source,
                'source_bytes' => $stats['source_bytes'],
                'rows' => $stats['rows'],
                'relevant_rows' => $stats['relevant_rows'],
                'products_with_specifications' => $stats['products'],
                'definitions' => $stats['definitions'],
                'published_products' => $publish['products'],
                'published_specifications' => $publish['specifications'],
                'energy_declarations' => $publish['energy_declarations'],
                'filter_attributes' => $publish['filter_attributes'],
            ],
            'error_message' => null,
            'completed_at' => now(),
        ])->save();
    }

    private function pruneReplacedSnapshots(int $activeId): void
    {
        MsanSpecificationSnapshot::query()
            ->where('status', MsanSpecificationSnapshot::STATUS_REPLACED)
            ->whereKeyNot($activeId)
            ->orderByDesc('id')
            ->skip(1)
            ->take(5)
            ->get()
            ->each->delete();
    }

    private function restorePreviousSnapshot(
        MsanSpecificationSnapshot $snapshot,
        ?MsanSpecificationSnapshot $previousSnapshot,
    ): void {
        DB::transaction(function () use ($snapshot, $previousSnapshot): void {
            $snapshot->forceFill(['status' => MsanSpecificationSnapshot::STATUS_REPLACED])->save();
            if ($previousSnapshot?->exists) {
                MsanSpecificationSnapshot::query()
                    ->whereKey($previousSnapshot->id)
                    ->update(['status' => MsanSpecificationSnapshot::STATUS_ACTIVE]);
                $previousSnapshot->forceFill(['status' => MsanSpecificationSnapshot::STATUS_ACTIVE])->syncOriginal();
            }

            $this->restoreDefinitionStateForSnapshot($previousSnapshot);
        }, 3);

        try {
            $this->restoreProjection($previousSnapshot?->exists ? $previousSnapshot->refresh() : null);
        } finally {
            $this->deleteCandidateSnapshot($snapshot);
        }
    }

    private function cleanupAbandonedCandidates(MsanSyncRun $run): void
    {
        $candidates = MsanSpecificationSnapshot::query()
            ->where('status', MsanSpecificationSnapshot::STATUS_CANDIDATE)
            ->where(function ($query) use ($run): void {
                $query->where('msan_sync_run_id', $run->id)
                    ->orWhereHas('run', fn ($runQuery) => $runQuery->whereIn('status', [
                        MsanSyncRun::STATUS_COMPLETED,
                        MsanSyncRun::STATUS_FAILED,
                        MsanSyncRun::STATUS_CANCELLED,
                    ]));
            });
        $hadCandidates = (clone $candidates)->exists();
        $candidates->orderBy('id')
            ->eachById(fn (MsanSpecificationSnapshot $candidate) => $this->deleteCandidateSnapshot($candidate), 25);

        $activeSnapshot = MsanSpecificationSnapshot::query()
            ->where('status', MsanSpecificationSnapshot::STATUS_ACTIVE)
            ->latest('id')
            ->first();
        $this->restoreDefinitionStateForSnapshot($activeSnapshot);
        if ($hadCandidates) {
            $this->restoreProjection($activeSnapshot);
        }
    }

    private function restoreProjection(?MsanSpecificationSnapshot $snapshot): void
    {
        try {
            if ($snapshot?->exists) {
                $this->publisher->publishSnapshot($snapshot);
            } else {
                $this->publisher->clearPublishedProjection();
            }
        } catch (Throwable $rollbackException) {
            report($rollbackException);
        }
    }

    private function restoreDefinitionStateForSnapshot(?MsanSpecificationSnapshot $snapshot): void
    {
        MsanSpecificationDefinition::query()->update([
            'is_stale' => true,
            'product_count' => 0,
        ]);
        if (! $snapshot?->exists) {
            return;
        }

        $snapshotId = (int) $snapshot->id;
        MsanSpecificationDefinition::query()
            ->whereIn('id', DB::table('msan_product_specifications')
                ->select('definition_id')
                ->where('snapshot_id', $snapshotId))
            ->update([
                'is_stale' => false,
                'product_count' => DB::raw(
                    '(SELECT COUNT(*) FROM msan_product_specifications '
                    .'WHERE snapshot_id = '.$snapshotId.' '
                    .'AND definition_id = msan_specification_definitions.id)'
                ),
            ]);
    }

    private function deleteCandidateSnapshot(MsanSpecificationSnapshot $snapshot): void
    {
        $createdAt = $snapshot->created_at;
        $snapshot->delete();

        MsanSpecificationDefinition::query()
            ->whereNull('updated_by')
            ->where('created_at', '>=', $createdAt)
            ->whereDoesntHave('productSpecifications')
            ->delete();
    }

    private function refreshDefinitionMetadata(string $path): void
    {
        $seenAt = now();
        $definitions = [];
        $bufferBytes = 0;

        foreach ($this->xml->rows($path) as $row) {
            $group = $this->text($row['SpecificationGroup'] ?? null, 255);
            $item = $this->text($row['SpecificationItemName'] ?? null, 255);
            if ($group === null || $item === null) {
                continue;
            }

            $measure = $this->text($row['SpecificationItemMeasure'] ?? null, 100);
            $values = $this->valuesParser->parse($row['SpecificationItemValues'] ?? '');
            if ($values === []) {
                continue;
            }

            $sourceKey = $this->sourceKey($group, $item, $measure);
            $sampleValues = json_encode(
                array_slice($values, 0, 5),
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            );
            $definitions[$sourceKey] = [
                'source_key' => $sourceKey,
                'group_name' => $group,
                'item_name' => $item,
                'measure' => $measure,
                'source_for_filter' => $this->boolean($row['SpecificationItemForFilter'] ?? false),
                'sample_values' => $sampleValues,
                'last_seen_at' => $seenAt,
                'is_stale' => false,
                'updated_at' => $seenAt,
            ];
            $bufferBytes += strlen($sampleValues);

            if (count($definitions) >= self::BATCH_SIZE || $bufferBytes >= self::MAX_PARSE_BUFFER_BYTES) {
                $this->upsertDefinitionMetadata($definitions);
                $definitions = [];
                $bufferBytes = 0;
            }
        }

        $this->upsertDefinitionMetadata($definitions);
    }

    /** @param array<string, array<string, mixed>> $definitions */
    private function upsertDefinitionMetadata(array $definitions): void
    {
        if ($definitions === []) {
            return;
        }

        DB::table('msan_specification_definitions')->upsert(
            array_values($definitions),
            ['source_key'],
            [
                'group_name', 'item_name', 'measure', 'source_for_filter', 'sample_values',
                'last_seen_at', 'is_stale', 'updated_at',
            ],
        );
    }

    private function cleanupTemporaryDirectoriesForRun(int $runId): void
    {
        $root = 'integrations/msan/specifications';
        foreach (Storage::disk('local')->directories($root) as $directory) {
            if (str_starts_with(basename($directory), $runId.'-')) {
                Storage::disk('local')->deleteDirectory($directory);
            }
        }
    }

    private function sourceKey(string $group, string $item, ?string $measure): string
    {
        return hash('sha256', implode("\n", [
            $this->normalizedKeyPart($group),
            $this->normalizedKeyPart($item),
            $this->normalizedKeyPart($measure ?? ''),
        ]));
    }

    private function normalizedKeyPart(string $value): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', Str::ascii($value)) ?? $value));
    }

    private function detectedRole(string $group, string $item): string
    {
        $value = $this->normalizedKeyPart($group.' '.$item);

        if (str_contains($value, 'eprel') && preg_match('/broj|registr|number/', $value) === 1) {
            return MsanSpecificationDefinition::ROLE_EPREL_NUMBER;
        }
        if (preg_match('/informacij.*list|product information sheet|product fiche/', $value) === 1) {
            return MsanSpecificationDefinition::ROLE_PRODUCT_INFORMATION_SHEET_URL;
        }
        if (preg_match('/energet.*(oznaka|naljepnica)|energy.*label/', $value) === 1
            && preg_match('/url|link|pdf/', $value) === 1) {
            return MsanSpecificationDefinition::ROLE_ENERGY_LABEL_URL;
        }
        if (preg_match('/raspon.*energet|energy.*(range|scale)/', $value) === 1) {
            return MsanSpecificationDefinition::ROLE_ENERGY_SCALE;
        }
        if (preg_match('/energet.*razred|energy efficiency class|energy class/', $value) === 1) {
            return MsanSpecificationDefinition::ROLE_ENERGY_CLASS;
        }

        return MsanSpecificationDefinition::ROLE_SPECIFICATION;
    }

    private function text(mixed $value, int $max): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : mb_substr($value, 0, $max);
    }

    private function boolean(mixed $value): bool
    {
        return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'da'], true);
    }

    private function sanitizeError(string $message): string
    {
        $message = preg_replace('/(password|passphrase|pin|api[_ -]?key)\s*[=:]\s*\S+/iu', '$1=[skriveno]', $message) ?? $message;

        return mb_substr(trim($message), 0, 1500);
    }
}
