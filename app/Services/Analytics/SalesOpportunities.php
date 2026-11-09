<?php

namespace App\Services\Analytics;

use App\Models\Catalog\Category\Category;
use App\Models\Catalog\Product\Product;
use App\Models\Sales\Order\Order;
use App\Models\User;
use App\Models\User\B2BAccount;
use App\Services\Admin\OrderManagerAccess;
use App\Services\Sales\ReceivedReturnQuantities;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class SalesOpportunities
{
    public function authorize(?User $actor = null): void
    {
        $actor ??= auth()->user();
        abort_unless($actor && ($actor->isA('superadmin') || ($actor->can('admin.access') && $actor->can('sales.opportunities.view'))), 403);
    }

    public function products(User $customer, object $snapshot, ?int $categoryId = null): Builder
    {
        $this->authorize();
        app(OrderManagerAccess::class)->assertCustomer($customer);
        abort_unless($customer->b2bAccount?->status === B2BAccount::STATUS_APPROVED, 422);
        $customer->loadMissing('profile', 'addresses');
        $identity = app(SalesOpportunitySync::class)->buyerIdentity([
            'buyerTaxNumber' => $customer->b2bAccount->oib ?: $customer->b2bAccount->vat_id ?: $customer->profile?->oib,
            'buyerCountry' => $customer->b2bAccount->country_code ?: 'HR',
            'buyerDocumentID' => $customer->b2bAccount->erp_customer_id,
            'buyerCode' => data_get($customer->b2bAccount->payload, 'eracuni.buyerCode'),
        ]);
        abort_unless($identity['known_buyer'], 422, 'Za ERP usporedbu potreban je OIB ili ERP ID odabranog kupca.');
        // Generic cash-customer codes may be shared. They are only a fallback
        // when the selected partner has no tax number or immutable ERP ID.
        $identityFields = $identity['tax_key'] || $identity['document_key'] ? ['tax_key', 'document_key'] : ['code_key'];
        $canonicalKeys = DB::table('sales_opportunity_buyer_aliases')->where('snapshot_id', $snapshot->id)
            ->whereIn('alias_key', array_filter(array_intersect_key($identity, array_flip($identityFields))))->pluck('buyer_key')->all();
        $bought = DB::table('sales_opportunity_purchases')->where('snapshot_id', $snapshot->id)
            ->where('quantity_365', '>', 0)->where(function ($query) use ($identity, $canonicalKeys, $identityFields): void {
                $query->whereIn('buyer_key', $canonicalKeys);
                foreach ($identityFields as $field) {
                    if ($identity[$field]) {
                        $query->orWhere($field, $identity[$field]);
                    }
                }
            })->select('product_id');
        $ranking = DB::table('sales_opportunity_purchases')->where('snapshot_id', $snapshot->id)
            ->select('product_id')->selectRaw('SUM(CASE WHEN known_buyer = 1 AND quantity_90 > 0 THEN 1 ELSE 0 END) AS opportunity_buyers')
            ->selectRaw('SUM(quantity_90) AS opportunity_quantity')
            ->groupBy('product_id')->havingRaw('SUM(quantity_90) > 0');
        $categoryIds = $categoryId ? Category::query()->descendantsAndSelf($categoryId)->pluck('id')->all() : [];

        return Product::query()->select('products.*', 'ranking.opportunity_buyers', 'ranking.opportunity_quantity')
            ->joinSub($ranking, 'ranking', 'ranking.product_id', '=', 'products.id')
            ->where('products.is_active', true)->whereNotIn('products.id', $bought)
            ->whereNotIn('products.id', $this->confirmedLocalProducts($customer))
            ->when($categoryId, fn (Builder $query) => $query->whereHas('categories', fn ($categories) => $categories->whereIn('categories.id', $categoryIds)))
            ->with(['translations', 'categories.translations'])
            ->orderByDesc('ranking.opportunity_buyers')->orderByDesc('ranking.opportunity_quantity')->orderBy('products.id');
    }

    private function confirmedLocalProducts(User $customer): \Illuminate\Database\Query\Builder
    {
        $orders = Order::query()->select('orders.id')->where('user_id', $customer->id)->withoutCancelled()
            ->whereBetween(DB::raw('COALESCE(orders.placed_at, orders.created_at)'), [now()->subDays(365)->startOfDay(), now()])
            ->where(fn (Builder $query) => $query->whereNotNull('paid_at')->orWhereHas('status', fn ($status) => $status->where('is_paid', true)
                ->orWhereIn('code', ['confirmed', 'processing', 'ready', 'shipped', 'completed', 'delivered', 'sent', 'herrera-oc-status-2', 'herrera-oc-status-3', 'herrera-oc-status-5'])));

        return DB::table('order_items')->whereIn('order_id', $orders)->whereNotNull('product_id')
            ->leftJoinSub(app(ReceivedReturnQuantities::class)->query(), 'returns', 'returns.order_item_id', '=', 'order_items.id')
            ->whereRaw('order_items.quantity > COALESCE(returns.returned_quantity, 0)')->select('product_id');
    }
}
