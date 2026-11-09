<?php

namespace App\Http\Controllers\Front;

use App\Http\Controllers\Controller;
use App\Models\Catalog\Product\Product;
use App\Models\Catalog\Product\ProductOptionValue;
use App\Services\Pricing\B2BAccessService;
use App\Services\Shipping\SupplierDeliveryService;
use App\Support\CountryCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ProductDeliveryController extends Controller
{
    public function __invoke(Request $request, Product $product, SupplierDeliveryService $delivery): JsonResponse
    {
        app(B2BAccessService::class)->ensureCanPurchase($request->user());
        abort_unless($product->is_active, 404);
        if (is_string($request->input('country_code'))) {
            $request->merge(['country_code' => strtoupper(trim($request->input('country_code')))]);
        }
        $data = $request->validate(['quantity' => ['nullable', 'integer', 'between:1,999'], 'product_option_value_id' => ['nullable', 'integer'],
            'country_code' => ['nullable', 'string', Rule::in(CountryCatalog::codes())]]);
        $option = ! empty($data['product_option_value_id']) ? ProductOptionValue::query()->where('product_id', $product->id)->where('is_active', true)->findOrFail($data['product_option_value_id']) : null;
        if ($option) {
            abort_unless($option->isVisibleOnStorefront(), 404);
        } elseif ($product->hasVisibleOptionRows()) {
            return response()->json(['label' => __('Odaberite varijantu'), 'status' => 'inquiry'])->header('Cache-Control', 'private, no-store');
        }

        return response()->json($delivery->estimate($product, (int) ($data['quantity'] ?? 1), $option, countryCode: $data['country_code'] ?? null))->header('Cache-Control', 'private, no-store');
    }
}
