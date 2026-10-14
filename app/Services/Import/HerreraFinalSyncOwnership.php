<?php

namespace App\Services\Import;

use Illuminate\Support\Facades\DB;

/** Private source-field/edge baselines support a conservative three-way final merge. */
class HerreraFinalSyncOwnership
{
    private array $seenEdges = [];

    public function __construct(private readonly string $snapshot, private readonly \Closure $conflict) {}

    public function fields(string $entity, string $sourceId): ?array
    {
        return $this->read('final_sync_fields', $entity, $sourceId)['fields'] ?? null;
    }

    public function rememberFields(string $entity, string $sourceId, array $fields): void
    {
        unset($fields['password'], $fields['payload'], $fields['updated_at']);
        if (! in_array($entity, ['order_history', 'wishlist'], true)) {
            unset($fields['created_at']);
        }
        $this->write('final_sync_fields', $entity, $sourceId, ['fields' => $fields]);
    }

    /** Written in the same transaction as the new parent and its import map. */
    public function rememberCreatedParent(string $entity, string $sourceId, string $table, int $id): void
    {
        $current = (array) DB::table($table)->where('id', $id)->first();
        $passwordFingerprint = isset($current['password']) ? hash('sha256', $current['password']) : null;
        unset($current['password'], $current['created_at'], $current['updated_at']);
        if ($entity === 'customer') {
            $this->rememberHistoricalNullOrderParents($sourceId);
        }
        $this->write('final_sync_created_parents', $entity, $sourceId, [
            'table' => $table, 'target_id' => $id, 'fields' => $current,
            'password_fingerprint' => $passwordFingerprint, 'completed' => false,
        ]);
    }

    /** Capture the missing parent before creating its customer import map. */
    private function rememberHistoricalNullOrderParents(string $customerSourceId): void
    {
        $orders = DB::table('orders as native')->select('native.*', 'mapping.source_id as mapped_source_id')
            ->join('herrera_import_maps as mapping', function ($join): void {
                $join->on('mapping.target_id', '=', 'native.id')->where('mapping.source', 'herrera-opencart')->where('mapping.entity', 'order');
            })
            ->where('native.source', 'opencart_import')->whereNull('native.user_id')->orderBy('native.id');
        foreach ($orders->cursor() as $order) {
            $payload = $this->decoded($order->payload);
            $orderSourceId = (string) $order->mapped_source_id;
            if ((string) data_get($payload, 'opencart.customer_id', '') !== $customerSourceId
                || (string) data_get($payload, 'opencart.order_id', '') !== $orderSourceId
                || $order->order_number !== 'OC-HERRERA-'.$orderSourceId) {
                continue;
            }
            $baseline = $this->fields('order', $orderSourceId);
            // Existing full or partial baselines require their own reconciliation;
            // a prior linked parent may have been deliberately removed natively.
            if ($baseline !== null) {
                continue;
            }
            $this->rememberFields('order', $orderSourceId, ['user_id' => null]);
        }
    }

    /** An interrupted creation may finish only while the native identity is untouched. */
    public function pendingCreatedParentMatches(string $entity, string $sourceId, string $table, int $id): bool
    {
        $proof = $this->read('final_sync_created_parents', $entity, $sourceId);

        return $proof && ! ($proof['completed'] ?? true) && $this->createdParentMatches($proof, $table, $id);
    }

    public function completedCreatedParentMatches(string $entity, string $sourceId, string $table, int $id): bool
    {
        $proof = $this->read('final_sync_created_parents', $entity, $sourceId);

        return $proof && ($proof['completed'] ?? false) && $this->createdParentMatches($proof, $table, $id);
    }

    private function createdParentMatches(array $proof, string $table, int $id): bool
    {
        if (($proof['table'] ?? null) !== $table
            || (int) ($proof['target_id'] ?? 0) !== $id) {
            return false;
        }
        $current = DB::table($table)->where('id', $id)->first();
        if (! $current) {
            return false;
        }
        foreach ($proof['fields'] as $field => $value) {
            if (! $this->same($current->{$field} ?? null, $value, $field)) {
                return false;
            }
        }

        return $proof['password_fingerprint'] === null
            || hash_equals($proof['password_fingerprint'], hash('sha256', (string) ($current->password ?? '')));
    }

    public function completeCreatedParent(string $entity, string $sourceId): void
    {
        $proof = $this->read('final_sync_created_parents', $entity, $sourceId);
        if ($proof && ! ($proof['completed'] ?? true)) {
            $proof['completed'] = true;
            $this->write('final_sync_created_parents', $entity, $sourceId, $proof);
        }
    }

    /** null means a native/source overlap must be reviewed; no row is overwritten. */
    public function merge(string $entity, string $sourceId, array $current, array $incoming): ?array
    {
        $baseline = $this->fields($entity, $sourceId);
        $updates = [];
        $conflicts = [];
        foreach ($incoming as $field => $value) {
            if (in_array($field, ['password', 'updated_at'], true)
                || ($field === 'created_at' && ! in_array($entity, ['order_history', 'wishlist'], true))) {
                continue;
            }
            if ($field === 'payload') {
                $native = $this->decoded($current[$field] ?? null);
                $source = $this->decoded($value);
                // Existing native branches (documents, workflow, presentation,
                // integration provenance) have no OpenCart authority.
                $updates[$field] = $this->json(array_replace($native, array_intersect_key($source, ['opencart' => true])));

                continue;
            }
            if ($this->same($current[$field] ?? null, $value, $field)) {
                continue;
            }
            if ($baseline !== null && array_key_exists($field, $baseline)) {
                if ($this->same($baseline[$field], $value, $field)) {
                    $operational = match ($entity) {
                        'product' => ['base_price', 'stock_qty', 'supplier_stock_qty', 'is_active'],
                        'product_option_value' => ['price_override', 'stock_qty', 'is_active'],
                        'customer_group' => ['is_active'],
                        'tax_rate' => ['rate', 'is_active'],
                        'b2b_account' => ['customer_group_id', 'status'],
                        'customer' => ['email'],
                        default => [],
                    };
                    if (in_array($field, $operational, true)) {
                        // The latest OpenCart snapshot is the commercial oracle.
                        // A native operational override is preserved but must
                        // gate cutover even when the source field stayed stable.
                        $conflicts[] = $field;
                    }

                    continue; // Only the native value changed; preserve it.
                }
                if ($this->same($current[$field] ?? null, $baseline[$field], $field)) {
                    $updates[$field] = $value;

                    continue;
                }
            }
            $conflicts[] = $field;
        }
        if ($conflicts !== []) {
            ($this->conflict)($entity, $sourceId, 'final_sync_field_conflict', $conflicts);

            return null;
        }

        return $updates;
    }

    public function edge(string $table, array $where, array $fields, bool $finalSync, bool $allowNew = false): void
    {
        $key = $this->json($where);
        $this->seenEdges[$table][$this->key($table, $key)] = true;
        $prior = $this->read('final_sync_edges', $table, $key);
        $current = DB::table($table)->where($where)->first();
        $sourceFields = array_diff_key($fields, array_flip(['created_at', 'updated_at']));
        if (! $finalSync) {
            DB::table($table)->updateOrInsert($where, $fields);
        } elseif (! $current) {
            if ($prior === null && ! $allowNew && $this->read('final_sync_edge_sets', $table, 'complete') === null) {
                // Absence may be a native removal of an old source edge. An
                // untracked relationship is not safe to recreate implicitly.
                ($this->conflict)('edge_'.$table, hash('sha256', $key), 'final_sync_unverified_edge_addition', array_keys($where));

                return;
            }
            if ($prior !== null && ($prior['present'] ?? true) === true) {
                ($this->conflict)('edge_'.$table, hash('sha256', $key), 'final_sync_native_edge_removed', array_keys($where));

                return;
            }
            DB::table($table)->insert($where + $fields);
        } else {
            // A source-owned pivot's ordering/required/primary flags can be
            // customized natively. Change them only when the old baseline agrees.
            $baseline = $prior['fields'] ?? null;
            $updates = [];
            foreach ($sourceFields as $field => $value) {
                if ($field === 'payload') {
                    $updates[$field] = $this->json(array_replace($this->decoded($current->{$field} ?? null),
                        array_intersect_key($this->decoded($value), ['opencart' => true, 'locale' => true])));
                } elseif ($this->same($current->{$field} ?? null, $value, $field)
                    || ($baseline !== null && $this->same($baseline[$field] ?? null, $value, $field))) {
                    continue;
                } elseif ($baseline !== null && $this->same($current->{$field} ?? null, $baseline[$field] ?? null, $field)) {
                    $updates[$field] = $value;
                } else {
                    ($this->conflict)('edge_'.$table, hash('sha256', $key), 'final_sync_edge_field_conflict', [$field]);

                    return;
                }
            }
            if ($updates !== []) {
                DB::table($table)->where($where)->update($updates + ['updated_at' => now()]);
            }
        }
        $this->write('final_sync_edges', $table, $key, ['table' => $table, 'where' => $where, 'fields' => $sourceFields, 'present' => true]);
    }

    /** Remove only tracked, unchanged source edges; native additions are never candidates. */
    public function reconcileEdges(array $tables): int
    {
        $removed = 0;
        foreach ($tables as $table) {
            DB::table('herrera_source_records')->where('source_table', 'final_sync_edges')->where('source_key', 'like', $table.':%')
                ->orderBy('id')->chunkById(500, function ($records) use ($table, &$removed): void {
                    foreach ($records as $record) {
                        if (isset($this->seenEdges[$table][$record->source_key])) {
                            continue;
                        }
                        $state = $this->decoded($record->payload);
                        if (($state['present'] ?? false) !== true) {
                            continue;
                        }
                        $current = DB::table($table)->where($state['where'])->first();
                        $conflicts = [];
                        foreach ($state['fields'] as $field => $value) {
                            if ($current && $field === 'payload') {
                                $nativePayload = array_diff_key($this->decoded($current->{$field} ?? null), ['opencart' => true, 'locale' => true]);
                                $sourcePayload = array_diff_key($this->decoded($value), ['opencart' => true, 'locale' => true]);
                                if ($nativePayload != $sourcePayload) {
                                    $conflicts[] = 'payload';
                                }
                            } elseif ($current && ! $this->same($current->{$field} ?? null, $value, $field)) {
                                $conflicts[] = $field;
                            }
                        }
                        if ($conflicts !== []) {
                            ($this->conflict)('edge_'.$table, substr($record->source_key, strlen($table) + 1), 'final_sync_removed_edge_conflict', $conflicts);

                            continue;
                        }
                        if ($current) {
                            DB::table($table)->where($state['where'])->delete();
                            $removed++;
                        }
                        $state['present'] = false;
                        $state['snapshot'] = $this->snapshot;
                        DB::table('herrera_source_records')->where('id', $record->id)->update(['payload' => $this->json($state), 'checksum' => hash('sha256', $this->json($state)), 'updated_at' => now()]);
                    }
                });
        }

        return $removed;
    }

    public function markEdgeSetsComplete(array $tables): void
    {
        foreach ($tables as $table) {
            $this->write('final_sync_edge_sets', $table, 'complete', ['complete' => true]);
        }
    }

    public function same(mixed $left, mixed $right, ?string $field = null): bool
    {
        if (is_array($left) || is_array($right)) {
            return is_array($left) && is_array($right) && $left == $right;
        }
        if ($left === null || $right === null) {
            return $left === $right;
        }
        if (is_bool($left) || is_bool($right)) {
            return (string) (int) $left === (string) (int) $right;
        }
        $numeric = $field !== null && (str_ends_with($field, '_id') || in_array($field, [
            'rate', 'base_price', 'price_override', 'stock_qty', 'supplier_stock_qty', 'minimum_order_quantity', 'order_quantity_step',
            'weight_kg', 'length_cm', 'width_cm', 'height_cm', 'sort_order', 'unit_price', 'quantity', 'line_total', 'tax_amount',
            'grand_total', 'subtotal', 'shipping_total', 'tax_total', 'discount_total', 'currency_rate', 'minimum_quantity', 'value',
        ], true));
        if ($numeric && is_numeric($left) && is_numeric($right)) {
            return \Brick\Math\BigDecimal::of((string) $left)->isEqualTo((string) $right);
        }
        if (is_string($left) && is_string($right)) {
            $a = json_decode($left, true);
            $b = json_decode($right, true);
            if (is_array($a) && is_array($b)) {
                return $a == $b;
            }
        }

        return (string) $left === (string) $right;
    }

    private function read(string $type, string $entity, string $sourceId): ?array
    {
        $payload = DB::table('herrera_source_records')->where('source_table', $type)->where('source_key', $this->key($entity, $sourceId))->value('payload');

        return $payload === null ? null : $this->decoded($payload);
    }

    private function write(string $type, string $entity, string $sourceId, array $payload): void
    {
        $payload += ['entity' => $entity, 'source_id' => $sourceId, 'snapshot' => $this->snapshot];
        DB::table('herrera_source_records')->updateOrInsert(['source_table' => $type, 'source_key' => $this->key($entity, $sourceId)], [
            'payload' => $this->json($payload), 'checksum' => hash('sha256', $this->json($payload)), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function key(string $entity, string $sourceId): string
    {
        return $entity.':'.hash('sha256', $sourceId);
    }

    private function decoded(mixed $value): array
    {
        return is_array($value) ? $value : (json_decode((string) ($value ?? '{}'), true) ?: []);
    }

    private function json(array $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }
}
