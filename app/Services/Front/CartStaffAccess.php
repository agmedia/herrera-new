<?php

namespace App\Services\Front;

use App\Models\Catalog\Product\ProductOptionValue;
use App\Models\User;
use App\Services\User\CustomerImpersonationService;
use App\Services\User\StaffImpersonationService;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class CartStaffAccess
{
    public function canViewStock(Request $request): bool
    {
        $viewer = $request->user();
        if (! $viewer) {
            return false;
        }

        $customerPreview = $request->session()->get(CustomerImpersonationService::SESSION_KEY);
        if ($customerPreview !== null) {
            if (! is_array($customerPreview) || (int) ($customerPreview['customer_id'] ?? 0) !== (int) $viewer->id) {
                return false;
            }

            $actor = User::query()->find((int) ($customerPreview['admin_id'] ?? 0));

            // Recheck current privileges and assigned-customer access after entering a preview.
            return $this->isSalesStaff($actor)
                && app(CustomerImpersonationService::class)->canStart($actor, $viewer);
        }

        $staffPreview = $request->session()->get(StaffImpersonationService::SESSION_KEY);
        if ($staffPreview !== null) {
            if (! is_array($staffPreview) || (int) ($staffPreview['staff_id'] ?? 0) !== (int) $viewer->id) {
                return false;
            }

            $actor = User::query()->find((int) ($staffPreview['admin_id'] ?? 0));
            if (! $actor || $actor->account_type !== 'staff' || ! $actor->admin_login_enabled
                || ! ($actor->isA('superadmin') || ($actor->can('admin.access') && $actor->can('users.access.manage')))) {
                return false;
            }
        }

        return $this->isSalesStaff($viewer);
    }

    /** @return array<string, array{own: int, supplier: int|null}> */
    public function stockForLines(Request $request, Collection $lines): array
    {
        if (! $this->canViewStock($request)) {
            return [];
        }

        $options = ProductOptionValue::query()
            ->whereIn('id', $lines->pluck('product_option_value_id')->filter()->unique())
            ->get(['id', 'product_id', 'stock_qty'])->keyBy('id');

        return $lines->mapWithKeys(function (array $line) use ($options): array {
            $product = $line['product'];
            $optionId = (int) ($line['product_option_value_id'] ?? 0);
            $option = $options->get($optionId);
            if ($optionId > 0 && (! $option || (int) $option->product_id !== (int) $product->id)) {
                return [];
            }

            return [$line['key'] => [
                'own' => max(0, (int) ($option?->stock_qty ?? $product->stock_qty)),
                // Supplier quantities are recorded for the product, not individual variants.
                'supplier' => $option ? null : max(0, (int) $product->supplier_stock_qty),
            ]];
        })->all();
    }

    private function isSalesStaff(?User $actor): bool
    {
        return $actor && $actor->admin_login_enabled
            && $actor->isA('superadmin', 'super-admin', 'admin', 'order_manager')
            && ($actor->isA('superadmin') || $actor->can('admin.access'));
    }
}
