<?php

namespace App\Http\Controllers\Front;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Front\Concerns\ResolvesFrontendView;
use App\Models\Catalog\Product\Product;
use App\Models\Catalog\Product\ProductOptionValue;
use App\Services\Front\CartService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class CartController extends Controller
{
    use ResolvesFrontendView;

    public function __construct(
        private readonly CartService $cart
    ) {
    }

    public function index(Request $request): View
    {
        return view($this->frontendView($request, 'cart.index'), [
            'lines' => $this->cart->lines(),
            'summary' => $this->cart->summary(),
        ]);
    }

    public function preview(): View
    {
        return view('front.desktop.partials.header-cart-popover-content', [
            'cartLines' => $this->cart->lines(),
            'cartSummary' => $this->cart->summary(),
        ]);
    }

    public function store(Request $request): JsonResponse|RedirectResponse
    {
        $validated = $request->validate([
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'product_option_value_id' => ['nullable', 'integer', Rule::exists('catalog_product_option_values', 'id')],
            'quantity' => ['nullable', 'integer', 'min:1', 'max:999'],
        ]);

        $product = Product::query()
            ->where('is_active', true)
            ->findOrFail((int) $validated['product_id']);

        $optionValueId = isset($validated['product_option_value_id'])
            ? (int) $validated['product_option_value_id']
            : null;

        $hasVisibleOptions = $product->hasVisibleOptionRows();
        $hasAvailableOptions = $product->hasAvailableOptionRows();

        if ($hasVisibleOptions && ! $hasAvailableOptions) {
            $message = __('ui.cart.status.unavailable');

            if ($request->expectsJson() || $request->ajax()) {
                return response()->json([
                    'ok' => false,
                    'message' => $message,
                ], 422);
            }

            return back()->with('status', $message);
        }

        if ($hasAvailableOptions && ! $optionValueId) {
            if ($request->expectsJson() || $request->ajax()) {
                return response()->json([
                    'ok' => false,
                    'message' => __('ui.cart.errors.select_size'),
                    'errors' => ['product_option_value_id' => [__('ui.cart.errors.select_size')]],
                ], 422);
            }

            return back()
                ->withErrors(['product_option_value_id' => __('ui.cart.errors.select_size')])
                ->withInput();
        }

        if ($optionValueId) {
            $optionRow = ProductOptionValue::query()
                ->where('id', $optionValueId)
                ->where('product_id', $product->id)
                ->where('is_active', true)
                ->with([
                    'optionValue.option:id,payload',
                    'parentOptionValue.option:id,payload',
                ])
                ->first();
            if (! $optionRow || ! $optionRow->showsOnProductPage()) {
                if ($request->expectsJson() || $request->ajax()) {
                    return response()->json([
                        'ok' => false,
                        'message' => __('ui.cart.errors.invalid_size'),
                        'errors' => ['product_option_value_id' => [__('ui.cart.errors.invalid_size')]],
                    ], 422);
                }

                return back()
                    ->withErrors(['product_option_value_id' => __('ui.cart.errors.invalid_size')])
                    ->withInput();
            }
        }

        $ok = $this->cart->add($product, (int) ($validated['quantity'] ?? 1), $optionValueId);

        $message = $ok ? __('ui.cart.status.added') : __('ui.cart.status.unavailable');
        if ($request->expectsJson() || $request->ajax()) {
            return response()->json([
                'ok' => $ok,
                'message' => $message,
                'summary' => $this->cart->summary(),
            ], $ok ? 200 : 422);
        }

        return $ok
            ? back()->with('status', $message)
            : back()->with('warning', $message);
    }

    public function update(Request $request, Product $product): RedirectResponse
    {
        $validated = $request->validate([
            'product_option_value_id' => ['nullable', 'integer', Rule::exists('catalog_product_option_values', 'id')],
            'quantity' => ['required', 'integer', 'min:0', 'max:999'],
        ]);

        $optionValueId = isset($validated['product_option_value_id'])
            ? (int) $validated['product_option_value_id']
            : null;
        $availableStock = (int) $product->stock_qty;
        if ($optionValueId !== null) {
            $availableStock = (int) (ProductOptionValue::query()
                ->where('id', $optionValueId)
                ->where('product_id', $product->id)
                ->where('is_active', true)
                ->value('stock_qty') ?? 0);
        }

        $ok = $this->cart->set(
            $product,
            (int) $validated['quantity'],
            $optionValueId
        );

        $quantityWasStockAdjusted = (int) $validated['quantity'] > $availableStock && $availableStock > 0;
        $message = $ok
            ? __('ui.cart.status.updated')
            : ($quantityWasStockAdjusted
                ? __('ui.cart.status.adjusted_with_stock', ['stock' => $availableStock])
                : __('ui.cart.status.adjusted'));

        return $ok
            ? back()->with('status', $message)
            : back()->with('warning', $message);
    }

    public function destroy(Request $request, Product $product): JsonResponse|RedirectResponse
    {
        $validated = $request->validate([
            'product_option_value_id' => ['nullable', 'integer', Rule::exists('catalog_product_option_values', 'id')],
        ]);

        $this->cart->remove(
            (int) $product->id,
            isset($validated['product_option_value_id']) ? (int) $validated['product_option_value_id'] : null
        );

        if ($request->expectsJson() || $request->ajax()) {
            return response()->json([
                'ok' => true,
                'message' => __('ui.cart.status.removed'),
                'summary' => $this->cart->summary(),
            ]);
        }

        return back()->with('status', __('ui.cart.status.removed'));
    }

    public function clear(): RedirectResponse
    {
        $this->cart->clear();

        return redirect()->route('cart.index')->with('status', __('ui.cart.status.cleared'));
    }

    public function applyCoupon(Request $request): RedirectResponse
    {
        $validated = $request->validate(
            [
                'coupon_code' => ['required', 'string', 'max:60'],
            ],
            [
                'coupon_code.required' => __('ui.cart.validation.coupon_required'),
                'coupon_code.string' => __('ui.cart.validation.coupon_string'),
                'coupon_code.max' => __('ui.cart.validation.coupon_max'),
            ]
        );

        $applied = $this->cart->applyCoupon((string) $validated['coupon_code']);

        return redirect()
            ->route('cart.index')
            ->with('status', $applied ? __('ui.cart.status.coupon_applied') : __('ui.cart.status.coupon_invalid'));
    }

    public function removeCoupon(): RedirectResponse
    {
        $this->cart->clearCoupon();

        return redirect()
            ->route('cart.index')
            ->with('status', __('ui.cart.status.coupon_removed'));
    }
}
