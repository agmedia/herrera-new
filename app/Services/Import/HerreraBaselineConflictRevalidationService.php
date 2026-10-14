<?php

namespace App\Services\Import;

use Brick\Math\BigDecimal;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Rechecks old priming conflicts against the completed fresh merge, without changing history. */
class HerreraBaselineConflictRevalidationService
{
    private const OPERATIONAL = ['base_price', 'stock_qty', 'supplier_stock_qty', 'is_active'];

    public function plan(array $baselineIssues, ConnectionInterface $freshSource, string $prefix = 'oc_'): array
    {
        if (! preg_match('/^[a-zA-Z0-9_]*$/D', $prefix)) {
            throw new RuntimeException('Invalid source table prefix.');
        }
        HerreraCutoverSafety::assertNoSplitConnection($freshSource->getConfig());
        HerreraCutoverSafety::assertNoSplitConnection(DB::connection()->getConfig());
        $details = [];
        $candidates = [];
        foreach ($baselineIssues as $issue) {
            $issue = (array) $issue;
            $context = $this->decoded($issue['context'] ?? null);
            $fields = $context['fields'] ?? [];
            $detail = ['entity' => $issue['entity'] ?? null, 'source_id' => (string) ($issue['source_id'] ?? ''),
                'code' => $issue['code'] ?? null, 'fields' => $fields, 'resolved' => false, 'reason' => 'unsupported_baseline_conflict'];
            $index = count($details);
            $details[] = $detail;
            if ($detail['entity'] !== 'product' || $detail['code'] !== 'final_sync_field_conflict'
                || ! is_array($fields) || ! array_is_list($fields) || $fields === []
                || count(array_filter($fields, 'is_string')) !== count($fields)
                || array_diff($fields, self::OPERATIONAL) !== []) {
                continue;
            }
            if (! preg_match('/^[1-9][0-9]*$/D', $detail['source_id'])) {
                $details[$index]['reason'] = 'invalid_source_identity';

                continue;
            }
            $candidates[$index] = $detail['source_id'];
        }

        foreach (array_chunk(array_values(array_unique($candidates)), 500) as $sourceIds) {
            $maps = DB::table('herrera_import_maps')->where('source', 'herrera-opencart')->where('entity', 'product')
                ->whereIn('source_id', $sourceIds)->get()->groupBy('source_id');
            $targetIds = $maps->flatten(1)->pluck('target_id')->unique()->values()->all();
            $targets = DB::table('products')->whereIn('id', $targetIds)->get()->keyBy('id');
            $targetMapCounts = DB::table('herrera_import_maps')->where('source', 'herrera-opencart')->where('entity', 'product')
                ->whereIn('target_id', $targetIds)->select('target_id')->selectRaw('COUNT(*) AS map_count')->groupBy('target_id')->get()->keyBy('target_id');
            $sourceProducts = $freshSource->table($prefix.'product')->whereIn('product_id', $sourceIds)->get()->groupBy('product_id');
            $baselineKeys = array_map(fn ($id) => 'product:'.hash('sha256', $id), $sourceIds);
            $baselines = DB::table('herrera_source_records')->where('source_table', 'final_sync_fields')
                ->whereIn('source_key', $baselineKeys)->get()->keyBy('source_key');

            foreach ($candidates as $index => $sourceId) {
                if (! in_array($sourceId, $sourceIds, true)) {
                    continue;
                }
                $mapRows = $maps->get($sourceId);
                $map = $mapRows?->first();
                if (! $map || $mapRows->count() !== 1 || (int) ($targetMapCounts->get($map->target_id)->map_count ?? 0) !== 1) {
                    $details[$index]['reason'] = 'missing_or_ambiguous_source_mapping';

                    continue;
                }
                $sourceRows = $sourceProducts->get($sourceId);
                if (! $sourceRows || $sourceRows->count() !== 1) {
                    $details[$index]['reason'] = 'missing_or_ambiguous_source_product';

                    continue;
                }
                $native = $targets->get($map->target_id);
                if (! $native) {
                    $details[$index]['reason'] = 'missing_native_product';

                    continue;
                }
                $nativePayload = $this->decoded($native->payload);
                if ((string) ($nativePayload['opencart']['product_id'] ?? '') !== $sourceId) {
                    $details[$index]['reason'] = 'target_identity_mismatch';

                    continue;
                }
                $source = (array) $sourceRows->first();
                if (! hash_equals((string) $map->checksum, hash('sha256', json_encode($source, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)))) {
                    $details[$index]['reason'] = 'fresh_source_map_checksum_mismatch';

                    continue;
                }
                $baseline = $this->decoded($baselines->get('product:'.hash('sha256', $sourceId))->payload ?? null);
                if (($baseline['snapshot'] ?? null) !== $freshSource->getDatabaseName()
                    || ($baseline['entity'] ?? null) !== 'product' || (string) ($baseline['source_id'] ?? '') !== $sourceId) {
                    $details[$index]['reason'] = 'fresh_baseline_missing_or_stale';

                    continue;
                }
                $reason = null;
                foreach ($details[$index]['fields'] as $field) {
                    $sourceField = ['base_price' => 'price', 'stock_qty' => 'quantity', 'supplier_stock_qty' => 'suplierqty', 'is_active' => 'status'][$field];
                    if (! array_key_exists($sourceField, $source) || ! array_key_exists($field, $baseline['fields'] ?? [])) {
                        $reason = 'incomplete_operational_proof';

                        break;
                    }
                    $incoming = $source[$sourceField];
                    if (! $this->sameDecimal($native->{$field}, $incoming) || ! $this->sameDecimal($baseline['fields'][$field], $incoming)) {
                        $reason = 'fresh_operational_state_mismatch';

                        break;
                    }
                }
                $details[$index]['resolved'] = $reason === null;
                $details[$index]['reason'] = $reason ?? 'resolved_fresh_operational_state';
            }
        }
        $resolved = count(array_filter($details, fn ($detail) => $detail['resolved']));

        return ['total' => count($details), 'resolved' => $resolved, 'unresolved' => count($details) - $resolved, 'details' => $details];
    }

    private function decoded(mixed $value): array
    {
        $decoded = is_array($value) ? $value : json_decode((string) ($value ?? '{}'), true);

        return is_array($decoded) ? $decoded : [];
    }

    private function sameDecimal(mixed $left, mixed $right): bool
    {
        $left = is_bool($left) ? (int) $left : $left;
        $right = is_bool($right) ? (int) $right : $right;
        if (! is_numeric($left) || ! is_numeric($right)) {
            return false;
        }

        return BigDecimal::of((string) $left)->isEqualTo((string) $right);
    }
}
