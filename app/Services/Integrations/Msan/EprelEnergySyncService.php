<?php

namespace App\Services\Integrations\Msan;

use App\Models\Catalog\Product\Product;
use App\Models\Catalog\Product\ProductEnergyDeclaration;
use App\Models\Integrations\Msan\MsanProduct;
use App\Models\Integrations\Msan\MsanSyncRun;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

class EprelEnergySyncService
{
    /** Keep a manual run bounded even when one product needs several EPREL calls. */
    private const MAX_PRODUCTS_PER_RUN = 50;

    private const REQUEST_BUDGET_SECONDS = 600;

    private const MAX_REQUESTS_PER_PRODUCT = 5;

    private const EXACT_FRESH_FOR_DAYS = 30;

    private const NO_MATCH_FRESH_FOR_DAYS = 7;

    public function __construct(
        private readonly EprelClient $client,
        private readonly MsanSettingsService $settings,
        private readonly EprelDeclarationWriter $declarations,
    ) {}

    public function sync(MsanSyncRun $run): MsanSyncRun
    {
        if (! $this->settings->enabled()) {
            throw new RuntimeException('M SAN integracija nije uključena.');
        }
        if (! $this->settings->eprelEnabled()) {
            throw new RuntimeException('EPREL dohvat nije uključen.');
        }
        // Validate/decrypt once before any run state or network activity. The
        // returned secret is deliberately not retained in a property or summary.
        $this->settings->eprelApiKey();

        $runLimit = $this->runLimit();
        $eligibleQuery = $this->eligibleProductsQuery();
        $eligibleCount = (clone $eligibleQuery)->count();
        $candidates = $eligibleQuery
            ->with(['localProduct.energyDeclarations', 'categories.mapping'])
            ->orderByRaw('CASE WHEN eprel_checked_at IS NULL THEN 0 ELSE 1 END')
            ->orderBy('eprel_checked_at')
            ->orderBy('id')
            ->limit($runLimit)
            ->get();
        $total = $candidates->count();

        $run->forceFill([
            'status' => MsanSyncRun::STATUS_RUNNING,
            'started_at' => $run->started_at ?: now(),
            'completed_at' => null,
            'error_message' => null,
            'progress' => $total === 0 ? 100 : 2,
            'total_count' => $total,
            'processed_count' => 0,
            'succeeded_count' => 0,
            'failed_count' => 0,
            'skipped_count' => 0,
        ])->save();

        $processed = 0;
        $succeeded = 0;
        $notMatched = 0;
        $invalid = 0;
        $skipped = 0;

        try {
            foreach ($candidates as $source) {
                $processed++;
                $group = $this->mappedGroup($source);
                $sourceFingerprint = $this->sourceIdentityFingerprint($source, $group);
                if ($group === null || ! $source->localProduct) {
                    $invalid++;
                    $skipped++;
                    $this->clearStaleEprelDeclaration($source, $group, $sourceFingerprint);
                    $this->recordAttempt($source, MsanProduct::EPREL_INVALID, $group, $sourceFingerprint);
                    $this->persistProgress($run, $processed, $succeeded, $skipped, $total);

                    continue;
                }

                try {
                    $outcome = $this->findExactDeclaration($source, $group);
                } catch (InvalidArgumentException) {
                    // A malformed supplier identifier or stale administrator
                    // mapping is local data, not a reason to fail the whole run.
                    $invalid++;
                    $skipped++;
                    $this->clearStaleEprelDeclaration($source, $group, $sourceFingerprint);
                    $this->recordAttempt($source, MsanProduct::EPREL_INVALID, $group, $sourceFingerprint);
                    $this->persistProgress($run, $processed, $succeeded, $skipped, $total);

                    continue;
                } catch (EprelMatchConflictException) {
                    $invalid++;
                    $skipped++;
                    $this->recordAttempt($source, MsanProduct::EPREL_INVALID, $group, $sourceFingerprint);
                    $this->persistProgress($run, $processed, $succeeded, $skipped, $total);

                    continue;
                }

                if (! $this->sourceIdentityMatches($source, $group, $sourceFingerprint)) {
                    $skipped++;
                    $this->persistProgress($run, $processed, $succeeded, $skipped, $total);

                    continue;
                }

                if ($outcome['data'] === null) {
                    $outcome['status'] === MsanProduct::EPREL_INVALID ? $invalid++ : $notMatched++;
                    $skipped++;
                    $this->clearStaleEprelDeclaration($source, $group, $sourceFingerprint);
                } else {
                    try {
                        $this->declarations->store(
                            (int) $source->local_product_id,
                            $outcome['data'],
                            EprelDeclarationWriter::ORIGIN_MSAN_SYNC,
                            [],
                            fn (Product $lockedProduct): bool => $this->sourceIdentityMatches(
                                $source,
                                $group,
                                $sourceFingerprint,
                                (int) $lockedProduct->getKey(),
                                true,
                            ),
                        );
                    } catch (EprelMatchConflictException) {
                        $invalid++;
                        $skipped++;
                        $this->recordAttempt(
                            $source,
                            MsanProduct::EPREL_INVALID,
                            $group,
                            $sourceFingerprint,
                        );
                        $this->persistProgress($run, $processed, $succeeded, $skipped, $total);

                        continue;
                    }
                    $succeeded++;
                }
                $this->recordAttempt($source, $outcome['status'], $group, $sourceFingerprint);

                $this->persistProgress($run, $processed, $succeeded, $skipped, $total);
            }

            $run->forceFill([
                'status' => MsanSyncRun::STATUS_COMPLETED,
                'progress' => 100,
                'processed_count' => $processed,
                'succeeded_count' => $succeeded,
                'failed_count' => 0,
                'skipped_count' => $skipped,
                'summary' => [
                    'eligible_products' => $eligibleCount,
                    'run_limit' => $runLimit,
                    'deferred_products' => max(0, $eligibleCount - $total),
                    'exact_matches' => $succeeded,
                    'not_matched' => $notMatched,
                    'invalid_local_data' => $invalid,
                    'exact_fresh_for_days' => self::EXACT_FRESH_FOR_DAYS,
                    'no_match_fresh_for_days' => self::NO_MATCH_FRESH_FOR_DAYS,
                ],
                'completed_at' => now(),
            ])->save();
        } catch (Throwable $exception) {
            $summary = is_array($run->summary) ? $run->summary : [];
            $summary['last_attempt_failed_at'] = now()->toIso8601String();
            $run->forceFill([
                // The queue job controls retries and the eventual terminal state.
                'status' => MsanSyncRun::STATUS_RUNNING,
                'processed_count' => $processed,
                'succeeded_count' => $succeeded,
                'skipped_count' => $skipped,
                'summary' => $summary,
                'error_message' => $this->sanitizeError($exception->getMessage()),
                'completed_at' => null,
            ])->save();

            throw $exception;
        }

        return $run->refresh();
    }

    private function eligibleProductsQuery(): Builder
    {
        return MsanProduct::query()
            ->select([
                'id', 'external_code', 'brand', 'model', 'part_number', 'selected',
                'import_status', 'local_product_id', 'is_stale', 'eprel_match_status',
                'eprel_identifier_checksum', 'eprel_checked_at',
            ])
            ->where('is_stale', false)
            ->whereNotNull('local_product_id')
            ->where(function (Builder $query): void {
                $query->where('selected', true)
                    ->orWhere('import_status', MsanProduct::IMPORT_IMPORTED);
            })
            ->whereHas('categories.mapping', function (Builder $query): void {
                $query->whereNotNull('eprel_product_group')
                    ->where('eprel_product_group', '!=', '')
                    ->where('energy_requirement', '!=', 'not_applicable');
            })
            ->where(function (Builder $query): void {
                $query->whereNull('eprel_checked_at')
                    ->orWhere(function (Builder $exact): void {
                        $exact->where('eprel_match_status', MsanProduct::EPREL_EXACT)
                            ->where('eprel_checked_at', '<=', now()->subDays(self::EXACT_FRESH_FOR_DAYS));
                    })
                    ->orWhere(function (Builder $notExact): void {
                        $notExact->where('eprel_match_status', '!=', MsanProduct::EPREL_EXACT)
                            ->where('eprel_checked_at', '<=', now()->subDays(self::NO_MATCH_FRESH_FOR_DAYS));
                    });
            });
    }

    private function runLimit(): int
    {
        // Registration lookup plus two possible model search/detail pairs is
        // the strict per-product maximum. Scale the batch to the
        // configured HTTP timeout so it always fits comfortably inside the
        // queue job's 840-second timeout, even when EPREL is slow.
        $perProductBudget = max(1, $this->settings->eprelTimeout()) * self::MAX_REQUESTS_PER_PRODUCT;

        return max(1, min(
            self::MAX_PRODUCTS_PER_RUN,
            intdiv(self::REQUEST_BUDGET_SECONDS, $perProductBudget),
        ));
    }

    /**
     * @return array{status:string,data:?array<string, mixed>}
     */
    private function findExactDeclaration(MsanProduct $source, string $group): array
    {
        $attempted = false;
        $product = $source->localProduct;
        $registration = $this->existingRegistrationNumber($product);
        if ($registration !== null) {
            try {
                $result = $this->client->findByRegistrationNumber($group, $registration);
                $attempted = true;
                if ($result !== null) {
                    return ['status' => MsanProduct::EPREL_EXACT, 'data' => $result];
                }
            } catch (InvalidArgumentException) {
                // A malformed historic registration number may still be repaired
                // by an exact model-identifier lookup below.
            }
        }

        $models = collect([$source->model, $source->part_number])
            ->map(static fn ($value): string => trim((string) $value))
            ->filter(static fn (string $value): bool => $value !== '')
            ->unique()
            ->values();
        $brand = trim((string) $source->brand);
        if ($brand === '') {
            return [
                'status' => $attempted ? MsanProduct::EPREL_NO_MATCH : MsanProduct::EPREL_INVALID,
                'data' => null,
            ];
        }
        $modelMatches = [];
        foreach ($models as $model) {
            try {
                $result = $this->client->findByModelIdentifier($group, $model, [$brand]);
                $attempted = true;
            } catch (InvalidArgumentException) {
                continue;
            }
            if ($result !== null) {
                $modelMatches[$result['eprel_registration_number']] = $result;
            }
            if (count($modelMatches) > 1) {
                throw new EprelMatchConflictException('M SAN model i part number upućuju na različite EPREL zapise. Ništa nije promijenjeno.');
            }
        }
        if ($modelMatches !== []) {
            return ['status' => MsanProduct::EPREL_EXACT, 'data' => reset($modelMatches)];
        }

        return [
            'status' => $attempted ? MsanProduct::EPREL_NO_MATCH : MsanProduct::EPREL_INVALID,
            'data' => null,
        ];
    }

    private function mappedGroup(MsanProduct $source): ?string
    {
        $groups = $source->categories
            ->pluck('mapping')
            ->filter()
            ->reject(fn ($mapping): bool => (string) $mapping->energy_requirement === 'not_applicable')
            ->map(static fn ($mapping): string => strtolower(trim((string) $mapping->eprel_product_group)))
            ->filter(static fn (string $group): bool => $group !== '')
            ->unique()
            ->values();

        // Multiple conflicting category mappings must be resolved by an
        // administrator; guessing a group could attach another product's label.
        return $groups->count() === 1 ? $groups->first() : null;
    }

    private function existingRegistrationNumber(?Product $product): ?string
    {
        if (! $product) {
            return null;
        }

        $number = trim((string) $product->eprel_registration_number);
        if ($number !== '') {
            return $number;
        }

        $declaration = $product->energyDeclarations
            ->first(fn (ProductEnergyDeclaration $item): bool => trim((string) $item->eprel_registration_number) !== '');

        return $declaration ? trim((string) $declaration->eprel_registration_number) : null;
    }

    private function clearStaleEprelDeclaration(
        MsanProduct $source,
        ?string $expectedGroup,
        string $sourceFingerprint,
    ): void {
        if (! $source->local_product_id) {
            return;
        }

        DB::transaction(function () use ($source, $expectedGroup, $sourceFingerprint): void {
            /** @var Product|null $product */
            $product = Product::query()->lockForUpdate()->find($source->local_product_id);
            if (! $product) {
                return;
            }
            if (! $this->sourceIdentityMatches(
                $source,
                $expectedGroup,
                $sourceFingerprint,
                (int) $product->getKey(),
                true,
            )) {
                return;
            }

            $ownedDeclarationIds = $product->energyDeclarations()
                ->where('source', ProductEnergyDeclaration::SOURCE_EPREL)
                ->get(['id', 'payload'])
                ->filter(function (ProductEnergyDeclaration $declaration): bool {
                    $origins = collect(data_get($declaration->payload, 'origins', []))
                        ->push(data_get($declaration->payload, 'origin'))
                        ->filter(static fn ($value): bool => is_string($value) && $value !== '')
                        ->unique();

                    if ($origins->isEmpty()) {
                        return true;
                    }

                    return $origins->contains(EprelDeclarationWriter::ORIGIN_MSAN_SYNC)
                        && ! $origins->contains(EprelDeclarationWriter::ORIGIN_ADMIN_LOOKUP);
                })
                ->modelKeys();
            if ($ownedDeclarationIds === []) {
                return;
            }
            $product->energyDeclarations()->whereKey($ownedDeclarationIds)->delete();

            /** @var ProductEnergyDeclaration|null $primary */
            $primary = $product->energyDeclarations()
                ->where('is_primary', true)
                ->orderBy('id')
                ->first();
            if (! $primary) {
                $primary = $product->energyDeclarations()
                    ->orderByRaw('CASE WHEN source = ? THEN 0 ELSE 1 END', [ProductEnergyDeclaration::SOURCE_MANUAL])
                    ->orderBy('id')
                    ->first();
                $primary?->forceFill(['is_primary' => true])->save();
            }

            $requiresEnergyLabel = $source->categories
                ->pluck('mapping')
                ->filter()
                ->contains(fn ($mapping): bool => (string) $mapping->energy_requirement === 'required');
            $product->forceFill([
                'energy_label_required' => $requiresEnergyLabel || (bool) $product->energy_label_required,
                'energy_efficiency_class' => $primary?->energy_class,
                'energy_efficiency_scale' => $primary
                    ? $this->scaleLabel($primary->scale_min, $primary->scale_max)
                    : null,
                'eprel_registration_number' => $primary?->eprel_registration_number,
                'eprel_product_group' => $primary?->eprel_product_group,
                'eprel_energy_label_image' => $primary?->energy_label_image,
                'energy_label_url' => $primary?->energy_label_url,
                'product_information_sheet_url' => $primary?->product_information_sheet_url,
                'energy_data_synced_at' => $primary?->synced_at,
            ])->save();
        }, 3);
    }

    private function scaleLabel(?string $minimum, ?string $maximum): ?string
    {
        $parts = array_values(array_filter([
            trim((string) $minimum),
            trim((string) $maximum),
        ], static fn (string $value): bool => $value !== ''));

        return $parts === [] ? null : implode('-', array_unique($parts));
    }

    private function identifierChecksum(MsanProduct $source, ?string $group): string
    {
        $mappedGroups = $source->categories
            ->pluck('mapping.eprel_product_group')
            ->map(static fn ($value): string => strtolower(trim((string) $value)))
            ->filter(static fn (string $value): bool => $value !== '')
            ->unique()
            ->sort()
            ->values()
            ->all();

        return hash('sha256', json_encode([
            'groups' => $group !== null ? [$group] : $mappedGroups,
            'registration_number' => $this->existingRegistrationNumber($source->localProduct),
            'brand' => trim((string) $source->brand),
            'model' => trim((string) $source->model),
            'part_number' => trim((string) $source->part_number),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    private function sourceIdentityFingerprint(MsanProduct $source, ?string $group): string
    {
        $mappedGroups = $source->categories
            ->pluck('mapping.eprel_product_group')
            ->map(static fn ($value): string => strtolower(trim((string) $value)))
            ->filter(static fn (string $value): bool => $value !== '')
            ->unique()
            ->sort()
            ->values()
            ->all();

        return hash('sha256', json_encode([
            'local_product_id' => (int) ($source->local_product_id ?? 0),
            'groups' => $group !== null ? [$group] : $mappedGroups,
            'brand' => trim((string) $source->brand),
            'model' => trim((string) $source->model),
            'part_number' => trim((string) $source->part_number),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    private function sourceIdentityMatches(
        MsanProduct $source,
        ?string $expectedGroup,
        string $expectedFingerprint,
        ?int $expectedProductId = null,
        bool $lockForUpdate = false,
    ): bool {
        $query = MsanProduct::query()->with('categories.mapping');
        if ($lockForUpdate) {
            $query->lockForUpdate();
        }
        $freshSource = $query->find($source->getKey());
        if (! $freshSource
            || ($expectedProductId !== null && (int) $freshSource->local_product_id !== $expectedProductId)) {
            return false;
        }

        $freshGroup = $this->mappedGroup($freshSource);

        return $freshGroup === $expectedGroup
            && hash_equals($expectedFingerprint, $this->sourceIdentityFingerprint($freshSource, $freshGroup));
    }

    private function recordAttempt(
        MsanProduct $source,
        string $status,
        ?string $expectedGroup,
        string $sourceFingerprint,
    ): bool {
        return DB::transaction(function () use ($source, $status, $expectedGroup, $sourceFingerprint): bool {
            $freshSource = MsanProduct::query()
                ->with(['categories.mapping', 'localProduct.energyDeclarations'])
                ->lockForUpdate()
                ->find($source->getKey());
            if (! $freshSource) {
                return false;
            }
            $freshGroup = $this->mappedGroup($freshSource);
            if ($freshGroup !== $expectedGroup
                || ! hash_equals(
                    $sourceFingerprint,
                    $this->sourceIdentityFingerprint($freshSource, $freshGroup),
                )) {
                return false;
            }

            $freshSource->forceFill([
                'eprel_match_status' => $status,
                'eprel_identifier_checksum' => $this->identifierChecksum($freshSource, $freshGroup),
                'eprel_checked_at' => now(),
            ])->save();

            return true;
        }, 3);
    }

    private function persistProgress(
        MsanSyncRun $run,
        int $processed,
        int $succeeded,
        int $skipped,
        int $total,
    ): void {
        if ($processed !== $total && $processed % 10 !== 0) {
            return;
        }

        $run->forceFill([
            'progress' => $total > 0 ? min(99, max(2, (int) floor(($processed / $total) * 100))) : 100,
            'processed_count' => $processed,
            'succeeded_count' => $succeeded,
            'failed_count' => 0,
            'skipped_count' => $skipped,
        ])->save();
    }

    private function sanitizeError(string $message): string
    {
        $safe = preg_replace(
            '/(api[-_ ]?key|x-api-key|authorization|password|passphrase|pin)\s*[=:]\s*\S+/iu',
            '$1=[skriveno]',
            $message,
        );

        return mb_substr(trim((string) $safe), 0, 1500);
    }
}
