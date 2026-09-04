<?php

namespace App\Http\Controllers\Api\V1\Wholesale;

use App\Services\Api\Wholesale\ProductSkuFeedService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProductQuantityController extends BaseWholesaleController
{
    public function __construct(private readonly ProductSkuFeedService $feedService) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'per_page' => ['nullable', 'integer', 'min:1', 'max:250'],
            'include_option_values' => ['nullable'],
            'include_inactive' => ['nullable'],
            'updated_since' => ['nullable', 'string', 'max:40'],
            'manufacturer_id' => ['nullable', 'integer', 'min:1'],
            'category_id' => ['nullable', 'integer', 'min:1'],
            'sku' => ['nullable', 'string', 'max:120'],
            'sort' => ['nullable', 'string', 'in:sku_asc,sku_desc,updated_asc,updated_desc'],
        ]);

        $rows = $this->feedService->paginate([
            'per_page' => $this->resolvePerPage($request, 200, 250),
            'include_option_values' => $this->toBoolean($validated['include_option_values'] ?? null, true),
            'include_inactive' => $this->toBoolean($validated['include_inactive'] ?? null, false),
            'updated_since' => $this->resolveUpdatedSince($validated['updated_since'] ?? null),
            'manufacturer_id' => isset($validated['manufacturer_id']) ? (int) $validated['manufacturer_id'] : null,
            'category_id' => isset($validated['category_id']) ? (int) $validated['category_id'] : null,
            'sku' => $validated['sku'] ?? null,
            'sort' => (string) ($validated['sort'] ?? 'sku_asc'),
        ]);

        return response()->json([
            'data' => collect($rows->items())->map(static function ($row): array {
                return [
                    'sku' => $row->sku,
                    'quantity' => (int) $row->quantity,
                    'product_id' => (int) $row->product_id,
                    'product_code' => $row->product_code,
                    'product_option_value_id' => $row->product_option_value_id ? (int) $row->product_option_value_id : null,
                    'source' => $row->source,
                    'is_active' => (bool) $row->is_active,
                    'updated_at' => (string) $row->updated_at,
                ];
            })->values(),
            'meta' => [
                'current_page' => $rows->currentPage(),
                'last_page' => $rows->lastPage(),
                'per_page' => $rows->perPage(),
                'total' => $rows->total(),
            ],
            'links' => [
                'first' => $rows->url(1),
                'last' => $rows->url($rows->lastPage()),
                'prev' => $rows->previousPageUrl(),
                'next' => $rows->nextPageUrl(),
            ],
        ]);
    }
}
