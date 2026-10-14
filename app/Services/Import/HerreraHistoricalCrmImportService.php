<?php

namespace App\Services\Import;

use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Retains original impersonation history; it does not invent visits, quotes or identities. */
class HerreraHistoricalCrmImportService
{
    private const ENTITY = 'order_manager_sales';

    private const FIELDS = ['customer_user_id', 'sales_rep_id', 'performed_by_id', 'started_at', 'ended_at', 'outcome', 'order_id'];

    public function import(ConnectionInterface $source, string $prefix = 'oc_', bool $apply = false): array
    {
        $this->guard($source, $prefix);
        $report = ['mode' => $apply ? 'apply' : 'dry-run', 'available' => true, 'source_table_present' => true, 'unavailable_reason' => null,
            'rows' => 0, 'archived_rows' => 0, 'session_rows' => 0,
            'mapped_sessions' => 0, 'created_sessions' => 0, 'updated_sessions' => 0, 'unchanged_sessions' => 0,
            'native_edits_preserved' => 0, 'archive_only_rows' => 0, 'review_rows' => 0, 'conflicts' => 0,
            'open_sessions' => 0, 'order_sessions' => 0, 'nothing_sessions' => 0, 'unknown_sessions' => 0, 'review_counts' => []];
        $schema = $source->getSchemaBuilder();
        if (! $schema->hasTable($prefix.self::ENTITY)) {
            return array_replace($report, ['available' => false, 'source_table_present' => false, 'unavailable_reason' => 'source_table_missing', 'all_rows_archived' => false]);
        }
        if (! $schema->hasColumns($prefix.self::ENTITY, ['id', 'start', 'end', 'napomena', 'user_id', 'customer_id', 'order_id', 'date_added', 'date_modified'])
            || $source->table($prefix.self::ENTITY)->select('id')->groupBy('id')->havingRaw('COUNT(*) > 1')->exists()) {
            return array_replace($report, ['available' => false, 'unavailable_reason' => 'source_schema_or_ids_unverified',
                'rows' => $source->table($prefix.self::ENTITY)->count(), 'all_rows_archived' => false]);
        }
        $maps = [];
        $ambiguous = [];
        foreach (['customer', 'admin_user', 'order'] as $entity) {
            $maps[$entity] = DB::table('herrera_import_maps')->where('source', 'herrera-opencart')->where('entity', $entity)->pluck('target_id', 'source_id')->all();
            $ambiguous[$entity] = array_keys(array_filter(array_count_values($maps[$entity]), fn ($count) => $count > 1));
        }
        $users = DB::table('users')->whereIn('id', array_unique([...array_values($maps['customer']), ...array_values($maps['admin_user'])]))->pluck('account_type', 'id')->all();
        $provenance = DB::table('user_profiles')->whereIn('user_id', array_keys($users))->pluck('payload', 'user_id')
            ->map(fn ($payload) => json_decode($payload ?? '{}', true, 512, JSON_THROW_ON_ERROR))->all();
        $orders = DB::table('orders')->whereIn('id', array_values($maps['order']))->get(['id', 'user_id', 'source', 'order_number', 'payload'])->keyBy('id');
        $hasSourceOrders = $source->getSchemaBuilder()->hasTable($prefix.'order');
        $seen = [];
        $source->table($prefix.self::ENTITY)->orderBy('id')->chunkById(500, function ($rows) use ($source, $prefix, $apply, $maps, $ambiguous, $users, $provenance, $orders, $hasSourceOrders, &$report, &$seen): void {
            $sourceOrders = $hasSourceOrders ? $source->table($prefix.'order')->whereIn('order_id', $rows->pluck('order_id')->all())->get(['order_id', 'customer_id'])->keyBy('order_id') : collect();
            $work = function () use ($rows, $source, $apply, $maps, $ambiguous, $users, $provenance, $orders, $sourceOrders, &$report, &$seen): void {
                foreach ($rows as $record) {
                    $row = (array) $record;
                    $id = (string) $row['id'];
                    $seen[$id] = true;
                    $report['rows']++;
                    $raw = $this->json($row);
                    $checksum = hash('sha256', $raw);
                    if ($apply) {
                        $this->archive($id, $raw, $checksum);
                        $report['archived_rows']++;
                    }
                    $reviews = [];
                    $customer = $this->identity('customer', $row['customer_id'], $maps, $ambiguous, $users, $provenance, $reviews);
                    $staff = $this->identity('admin_user', $row['user_id'], $maps, $ambiguous, $users, $provenance, $reviews);
                    $start = $this->date($row['start']);
                    if (! $start) {
                        $reviews[] = 'invalid_start';
                    }
                    $end = $this->date($row['end']);
                    $invalidEnd = ! $this->emptyDate($row['end']) && (! $end || ($start && $end < $start));
                    if ($invalidEnd) {
                        $reviews[] = 'invalid_end';
                        $end = null;
                    }
                    $order = null;
                    $sourceOrderId = (int) $row['order_id'];
                    if ($sourceOrderId < 0 || ! preg_match('/^\d+$/D', (string) $row['order_id'])) {
                        $reviews[] = 'invalid_order_id';
                    } elseif ($sourceOrderId > 0) {
                        $orderId = $maps['order'][$sourceOrderId] ?? null;
                        $sourceOrder = $sourceOrders->get($sourceOrderId);
                        if ($orderId && in_array($orderId, $ambiguous['order'], true)) {
                            $reviews[] = 'ambiguous_order';
                        } elseif (! $orderId || ! $orders->has($orderId) || ! $sourceOrder) {
                            $reviews[] = 'missing_order';
                        } elseif ($orders->get($orderId)->source !== 'opencart_import'
                            || $orders->get($orderId)->order_number !== 'OC-HERRERA-'.$sourceOrderId
                            || (string) data_get(json_decode($orders->get($orderId)->payload ?? '{}', true, 512, JSON_THROW_ON_ERROR), 'opencart.order_id') !== (string) $sourceOrderId) {
                            $reviews[] = 'order_identity_mismatch';
                        } elseif (! $customer || (int) $orders->get($orderId)->user_id !== $customer || (int) $sourceOrder->customer_id !== (int) $row['customer_id']) {
                            $reviews[] = 'cross_customer_order';
                        } else {
                            $order = (int) $orderId;
                        }
                    }
                    if (! $customer || ! $start) {
                        $report['archive_only_rows']++;
                        $this->reviews($id, $reviews, $apply, $report);

                        continue;
                    }
                    $outcome = $order ? 'order' : (($sourceOrderId !== 0 || in_array('invalid_order_id', $reviews, true) || $invalidEnd) ? 'unknown' : ($end ? 'nothing' : 'open'));
                    $fields = ['customer_user_id' => $customer, 'sales_rep_id' => $staff, 'performed_by_id' => $staff,
                        'started_at' => $start, 'ended_at' => $end, 'outcome' => $outcome, 'order_id' => $order];
                    $report['session_rows']++;
                    $report[$outcome.'_sessions']++;
                    $map = DB::table('herrera_import_maps')->where('source', 'herrera-opencart')->where('entity', self::ENTITY)->where('source_id', $id)->lockForUpdate()->first();
                    $current = $map ? DB::table('customer_sales_sessions')->where('id', $map->target_id)->lockForUpdate()->first() : null;
                    $payload = $current ? json_decode($current->payload ?? '{}', true, 512, JSON_THROW_ON_ERROR) : [];
                    $baseline = $payload['historical_import']['baseline'] ?? null;
                    if ($map && (! $current || ($payload['historical_import']['source'] ?? null) !== 'herrera-opencart'
                        || ($payload['historical_import']['source_id'] ?? null) !== $id || ! is_array($baseline))) {
                        $reviews[] = 'mapped_session_ownership_unverifiable';
                        $report['conflicts']++;
                        $this->reviews($id, $reviews, $apply, $report);

                        continue;
                    }
                    $updates = [];
                    $preserved = false;
                    foreach ($fields as $field => $value) {
                        if (! $current || $this->same($current->{$field}, $value)) {
                            continue;
                        }
                        if (array_key_exists($field, $baseline) && $this->same($baseline[$field], $value)) {
                            $preserved = true;
                        } elseif (array_key_exists($field, $baseline) && $this->same($current->{$field}, $baseline[$field])) {
                            $updates[$field] = $value;
                        } else {
                            $reviews[] = 'native_source_session_conflict';
                        }
                    }
                    $effective = $current ? array_replace((array) $current, $updates) : $fields;
                    if ($effective['order_id'] && (! $orders->has($effective['order_id']) || (int) $orders->get($effective['order_id'])->user_id !== (int) $effective['customer_user_id'])) {
                        $reviews[] = 'native_source_session_conflict';
                    }
                    if ($effective['ended_at'] && $effective['ended_at'] < $effective['started_at']) {
                        $reviews[] = 'native_source_session_conflict';
                    }
                    if (in_array('native_source_session_conflict', $reviews, true)) {
                        $report['conflicts']++;
                        $this->reviews($id, $reviews, $apply, $report);

                        continue;
                    }
                    $report['native_edits_preserved'] += (int) $preserved;
                    $report['mapped_sessions']++;
                    $nextPayload = array_replace($payload, ['opencart' => $row, 'historical_import' => ['source' => 'herrera-opencart',
                        'source_id' => $id, 'snapshot' => $source->getDatabaseName(), 'baseline' => $fields, 'review_codes' => array_values(array_unique($reviews))]]);
                    $payloadChanged = $nextPayload != $payload;
                    $report[! $current ? 'created_sessions' : (($updates !== [] || $payloadChanged) ? 'updated_sessions' : 'unchanged_sessions')]++;
                    if ($apply) {
                        if ($current) {
                            $targetId = $current->id;
                            if ($updates !== [] || $payloadChanged) {
                                DB::table('customer_sales_sessions')->where('id', $targetId)->update($updates + ['payload' => $this->json($nextPayload), 'updated_at' => now()]);
                            }
                        } else {
                            $targetId = DB::table('customer_sales_sessions')->insertGetId($fields + ['payload' => $this->json($nextPayload),
                                'created_at' => $this->date($row['date_added']) ?? $start, 'updated_at' => $this->date($row['date_modified']) ?? $this->date($row['date_added']) ?? $start]);
                        }
                        DB::table('herrera_import_maps')->updateOrInsert(['source' => 'herrera-opencart', 'entity' => self::ENTITY, 'source_id' => $id],
                            ['target_id' => $targetId, 'checksum' => $checksum, 'created_at' => $map?->created_at ?? now(), 'updated_at' => now()]);
                    }
                    $this->reviews($id, $reviews, $apply, $report);
                }
            };
            $apply ? DB::transaction($work) : $work();
        });
        $report['missing_source_rows_preserved'] = DB::table('herrera_import_maps')->where('source', 'herrera-opencart')->where('entity', self::ENTITY)
            ->whereNotIn('source_id', array_keys($seen))->count();
        $report['all_rows_archived'] = $apply && $report['archived_rows'] === $report['rows'];

        return $report;
    }

    private function identity(string $entity, mixed $sourceId, array $maps, array $ambiguous, array $users, array $provenance, array &$reviews): ?int
    {
        $target = $maps[$entity][$sourceId] ?? null;
        $label = $entity === 'customer' ? 'customer' : 'staff';
        $profileSource = data_get($provenance[$target] ?? [], $entity === 'customer' ? 'opencart.customer_id' : 'legacy_admin.source_id');
        if ($target && (in_array($target, $ambiguous[$entity], true) || ($profileSource !== null && (string) $profileSource !== (string) $sourceId))) {
            $reviews[] = 'ambiguous_'.$label;
        } elseif (! $target || ($users[$target] ?? null) !== ($entity === 'customer' ? 'customer' : 'staff')) {
            $reviews[] = 'missing_'.$label;
        } else {
            return (int) $target;
        }

        return null;
    }

    private function archive(string $id, string $raw, string $checksum): void
    {
        $old = DB::table('herrera_source_records')->where('source_table', self::ENTITY)->where('source_key', $id)->first();
        if ($old && $old->checksum !== $checksum) {
            DB::table('herrera_source_records')->updateOrInsert(['source_table' => self::ENTITY.'_history', 'source_key' => $id.':'.$old->checksum],
                ['checksum' => $old->checksum, 'payload' => $old->payload, 'created_at' => $old->created_at, 'updated_at' => now()]);
        }
        DB::table('herrera_source_records')->updateOrInsert(['source_table' => self::ENTITY, 'source_key' => $id],
            ['checksum' => $checksum, 'payload' => $raw, 'created_at' => $old?->created_at ?? now(), 'updated_at' => now()]);
    }

    private function reviews(string $id, array $codes, bool $apply, array &$report): void
    {
        $codes = array_values(array_unique($codes));
        $report['review_rows'] += (int) ($codes !== []);
        foreach ($codes as $code) {
            $report['review_counts'][$code] = ($report['review_counts'][$code] ?? 0) + 1;
        }
        if ($apply) {
            DB::table('herrera_import_issues')->where('entity', self::ENTITY)->where('source_id', $id)->delete();
            foreach ($codes as $code) {
                DB::table('herrera_import_issues')->insert(['entity' => self::ENTITY, 'source_id' => $id, 'code' => $code, 'context' => null, 'created_at' => now(), 'updated_at' => now()]);
            }
        }
    }

    private function date(mixed $value): ?string
    {
        // Keep original wall-clock values, within the portable MySQL timestamp
        // range; invalid or backwards times remain only in the raw archive.
        if (! is_string($value) || ! preg_match('/^(19[7-9]\d|20[0-2]\d|203[0-7])-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $value)) {
            return null;
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value);

        return $date && $date->format('Y-m-d H:i:s') === $value ? $value : null;
    }

    private function emptyDate(mixed $value): bool
    {
        return $value === null || trim((string) $value) === '' || str_starts_with((string) $value, '0000-');
    }

    private function same(mixed $left, mixed $right): bool
    {
        return $left === null || $right === null ? $left === $right : (string) $left === (string) $right;
    }

    private function json(array $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }

    private function guard(ConnectionInterface $source, string $prefix): void
    {
        $target = DB::connection();
        if (! app()->environment(['local', 'testing']) || ! preg_match('/^[a-zA-Z0-9_]+$/D', $prefix)
            || $source === $target || $source->getDatabaseName() === $target->getDatabaseName()) {
            throw new RuntimeException('Historical CRM import requires separate isolated local databases and a valid prefix.');
        }
        if (! app()->environment('testing')) {
            if (! HerreraCutoverSafety::rehearsalDatabase($target->getDatabaseName())
                || ! preg_match('/^herrera_(?:rehearsal_source_[0-9]{8}_[0-9]{6}_[a-f0-9]{4}|live_snapshot_[a-zA-Z0-9_]+)$/D', $source->getDatabaseName())) {
                throw new RuntimeException('Historical CRM import writes only to a new rehearsal database from a frozen local snapshot.');
            }
        }
        foreach ([$source, $target] as $connection) {
            HerreraCutoverSafety::assertNoSplitConnection($connection->getConfig());
            if (! (app()->environment('testing') && $connection->getDriverName() === 'sqlite')
                && (! in_array($connection->getDriverName(), ['mysql', 'mariadb'], true)
                    || ! in_array($connection->getConfig('host'), ['127.0.0.1', 'localhost', '::1'], true))) {
                throw new RuntimeException('Historical CRM source and destination must stay on localhost.');
            }
        }
        if (! $target->getSchemaBuilder()->hasColumn('customer_sales_sessions', 'payload')) {
            throw new RuntimeException('Install the historical session payload migration before importing.');
        }
    }
}
