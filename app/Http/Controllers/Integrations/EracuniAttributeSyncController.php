<?php

namespace App\Http\Controllers\Integrations;

use App\Http\Controllers\Controller;
use App\Services\Integrations\Eracuni\EracuniCatalogService;
use App\Services\Integrations\Stock\StockSyncSettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class EracuniAttributeSyncController extends Controller
{
    public function __invoke(Request $request, StockSyncSettingsService $settings, EracuniCatalogService $catalog): JsonResponse
    {
        $token = $request->bearerToken() ?: $request->query('token');
        abort_unless(is_string($token) && $settings->validToken('eracuni', $token), 403);
        if ($request->isMethod('HEAD')) {
            return $this->reply(['status' => 'ready']);
        }
        if (! $settings->attributesEnabled()) {
            return $this->reply(['status' => 'disabled', 'message' => 'Cron svojstava je isključen.'], 409);
        }
        if (! $settings->configured('eracuni')) {
            return $this->reply(['status' => 'unconfigured'], 503);
        }
        $bounds = $request->validate([
            'codeFrom' => ['nullable', 'string', 'max:120', 'not_regex:/[\x00-\x1f\x7f"\\\\]/'],
            'codeTo' => ['nullable', 'string', 'max:120', 'not_regex:/[\x00-\x1f\x7f"\\\\]/'],
        ]);
        try {
            $run = $catalog->syncAttributes(null, $bounds['codeFrom'] ?? null, $bounds['codeTo'] ?? null, 'cron');
        } catch (Throwable) {
            return $this->reply(['status' => 'busy', 'message' => 'Obradu nije moguće pokrenuti.'], 409);
        }

        return $this->reply([
            'run_id' => $run->id, 'status' => $run->status, 'completed_at' => $run->completed_at?->toIso8601String(),
            'fetched' => $run->fetched_count, 'updated' => $run->updated_count,
            'unchanged' => $run->unchanged_count, 'skipped' => $run->skipped_count,
            'scope' => $run->summary['scope'] ?? null, 'limit_reached' => $run->summary['limit_reached'] ?? false,
            'message' => $run->error_message,
        ], $run->status === 'completed' ? 200 : 502);
    }

    private function reply(array $data, int $status = 200): JsonResponse
    {
        return response()->json($data, $status)->header('Cache-Control', 'no-store, private')->header('X-Robots-Tag', 'noindex');
    }
}
