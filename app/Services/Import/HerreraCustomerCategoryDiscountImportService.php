<?php

namespace App\Services\Import;

use App\Models\Catalog\Category\Category;
use App\Models\Catalog\Pricing\CustomerDiscountRule;
use App\Models\User;
use App\Services\Pricing\PriceCatalogResolver;
use App\Services\Pricing\PriceCatalogService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/** Restores category definitions, not percentages guessed from generated product prices. */
class HerreraCustomerCategoryDiscountImportService
{
    public function preview(?array $sourceRows = null): array
    {
        $plan = $this->plan($sourceRows);
        $summary = $plan['summary'];
        if (! Schema::hasColumn('customer_discount_rules', 'source_key')) {
            return $summary;
        }
        $existing = CustomerDiscountRule::withTrashed()->whereNotNull('source_key')->get()->keyBy('source_key');
        $summary['planned'] = 0;
        foreach ($plan['rules'] as $data) {
            $rule = $existing->get($data['source_key']);
            if ($rule && ($rule->trashed() || data_get($rule->payload, 'customer_rule_edited', false))) {
                $summary['protected']++;
            } elseif ($rule && data_get($rule->payload, 'source_checksum') === $data['payload']['source_checksum']) {
                $summary['existing']++;
            } else {
                $summary['planned']++;
            }
        }

        return $summary;
    }

    public function import(?User $actor = null, ?array $sourceRows = null): array
    {
        if ($actor) {
            app(PriceCatalogService::class)->authorize($actor, 'update');
        }
        $plan = $this->plan($sourceRows);
        $summary = $plan['summary'];
        if (! Schema::hasColumn('customer_discount_rules', 'source_key')) {
            return $summary;
        }

        DB::transaction(function () use ($plan, $actor, &$summary): void {
            foreach ($plan['rules'] as $data) {
                $rule = CustomerDiscountRule::withTrashed()->where('source_key', $data['source_key'])->lockForUpdate()->first();
                if ($rule && ($rule->trashed() || data_get($rule->payload, 'customer_rule_edited', false))) {
                    $summary['protected']++;

                    continue;
                }
                if ($rule && data_get($rule->payload, 'source_checksum') === $data['payload']['source_checksum']) {
                    $summary['existing']++;

                    continue;
                }
                if ($rule) {
                    $rule->fill($data + ['updated_by' => $actor?->id])->save();
                    $summary['updated']++;
                } else {
                    CustomerDiscountRule::query()->create($data + ['created_by' => $actor?->id, 'updated_by' => $actor?->id]);
                    $summary['created']++;
                }
            }
            if ($summary['created'] || $summary['updated']) {
                $activity = activity('catalog_b2b_prices')->event('customer_category_rules_imported')
                    ->withProperties(collect($summary)->except('issues')->all());
                if ($actor) {
                    $activity->causedBy($actor);
                }
                $activity->log('Original customer category discounts restored');
            }
        });
        app(PriceCatalogResolver::class)->forgetActiveCatalog();

        return $summary;
    }

    private function plan(?array $sourceRows): array
    {
        $summary = ['source_rows' => 0, 'planned' => 0, 'created' => 0, 'updated' => 0, 'existing' => 0,
            'protected' => 0, 'invalid' => 0, 'unmapped' => 0, 'customers' => 0, 'issues' => []];
        if (! Schema::hasTable('herrera_source_records') || ! Schema::hasTable('herrera_import_maps')) {
            return ['summary' => $summary, 'rules' => []];
        }
        $sourceRows ??= DB::table('herrera_source_records')->where('source_table', 'customer_transaction')->orderBy('id')
            ->pluck('payload')->map(fn ($payload) => json_decode($payload, true))->all();
        $maps = DB::table('herrera_import_maps')->where('source', 'herrera-opencart')->whereIn('entity', ['customer', 'category'])
            ->get(['entity', 'source_id', 'target_id'])->groupBy('entity')->map(fn ($rows) => $rows->pluck('target_id', 'source_id'));
        $customers = DB::table('b2b_accounts')->pluck('user_id')->flip();
        $categories = Category::query()->where('scope', Category::SCOPE_CATALOG)->with('translations')->get()->keyBy('id');
        $rules = [];
        foreach ($sourceRows as $row) {
            $summary['source_rows']++;
            $sourceCustomerId = (int) ($row['customer_id'] ?? 0);
            $sourceCategoryId = trim((string) ($row['description'] ?? ''));
            $percent = (string) ($row['amount'] ?? '');
            if ($sourceCustomerId < 1 || ! ctype_digit($sourceCategoryId) || (int) $sourceCategoryId < 1
                || (int) ($row['order_id'] ?? 0) !== 0 || ! preg_match('/^\d{1,3}(?:\.\d{1,4})?$/', $percent)
                || (float) $percent < 0 || (float) $percent > 100) {
                $summary['invalid']++;
                $summary['issues'][] = ['transaction_id' => $row['customer_transaction_id'] ?? null, 'reason' => 'invalid_category_discount'];

                continue;
            }
            $customerId = $maps->get('customer')?->get((string) $sourceCustomerId);
            $categoryId = $maps->get('category')?->get($sourceCategoryId);
            $category = $categories->get($categoryId);
            if (! $customerId || ! $customers->has($customerId) || ! $category) {
                $summary['unmapped']++;
                $summary['issues'][] = ['transaction_id' => $row['customer_transaction_id'] ?? null,
                    'customer_id' => $sourceCustomerId, 'category_id' => (int) $sourceCategoryId, 'reason' => 'missing_customer_or_category'];

                continue;
            }
            $sourceKey = 'opencart:customer-category:'.$sourceCustomerId.':'.(int) $sourceCategoryId;
            $transactionId = (int) ($row['customer_transaction_id'] ?? 0);
            // Repeated snapshots may contain an older copy; retain the latest original definition.
            if (isset($rules[$sourceKey]) && $transactionId < $rules[$sourceKey]['payload']['opencart']['transaction_id']) {
                continue;
            }
            $label = $category->translations->firstWhere('locale', app()->getLocale())?->name
                ?? $category->translations->first()?->name ?? $category->code;
            $rules[$sourceKey] = ['user_id' => (int) $customerId, 'name' => Str::limit($label.' – '.$percent.' %', 191, ''),
                'percent' => $percent, 'category_ids' => [(int) $categoryId], 'manufacturer_ids' => [],
                // The old customer editor used direct membership. Descendants remain an explicit choice.
                'include_descendants' => false, 'excluded_product_ids' => [], 'starts_at' => null, 'ends_at' => null,
                'priority' => 1000000000 - $transactionId, 'is_active' => true, 'source_system' => 'opencart', 'source_key' => $sourceKey,
                'payload' => ['opencart' => ['customer_id' => $sourceCustomerId, 'category_id' => (int) $sourceCategoryId,
                    'transaction_id' => $transactionId, 'date_added' => $row['date_added'] ?? null],
                    'source_checksum' => hash('sha256', json_encode($row, JSON_THROW_ON_ERROR)), 'customer_rule_edited' => false]];
        }
        $summary['planned'] = count($rules);
        $summary['customers'] = count(array_unique(array_column($rules, 'user_id')));

        return ['summary' => $summary, 'rules' => $rules];
    }
}
