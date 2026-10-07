<?php

namespace App\Http\Controllers\Integrations;

use App\Http\Controllers\Controller;
use App\Services\Integrations\Stock\StockSyncService;
use App\Services\Integrations\Stock\StockSyncSettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class StockSyncController extends Controller
{
    public function __invoke(Request $request, string $supplier, StockSyncSettingsService $settings, StockSyncService $sync): JsonResponse
    {
        $token = $request->bearerToken() ?: $request->query('token');
        abort_unless(is_string($token) && $settings->validToken($supplier, $token), 403);
        if ($request->isMethod('HEAD')) {
            return $this->reply(['status' => 'ready']);
        }
        if (! $settings->enabled($supplier)) {
            return $this->reply(['status' => 'disabled', 'message' => 'Automatsko ažuriranje je isključeno.'], 409);
        }
        if (! $settings->configured($supplier)) {
            return $this->reply(['status' => 'unconfigured', 'message' => 'Nedostaju pristupne postavke izvora.'], 503);
        }
        try {
            $run = $sync->run($supplier, 'cron');
        } catch (RuntimeException) {
            return $this->reply(['status' => 'busy', 'message' => 'Ažuriranje nije moguće pokrenuti. Pokušajte ponovno.'], 409);
        }

        return $this->reply([
            'run_id' => $run->id, 'status' => $run->status, 'supplier' => $run->supplier,
            'completed_at' => $run->completed_at?->toIso8601String(),
            'fetched' => $run->fetched_count, 'matched' => $run->matched_count,
            'updated' => $run->updated_count, 'unchanged' => $run->unchanged_count,
            'unmatched' => $run->unmatched_count, 'invalid' => $run->invalid_count,
            'message' => $run->error_message,
        ], $run->status === 'completed' ? 200 : 502);
    }

    private function reply(array $data, int $status = 200): JsonResponse
    {
        return response()->json($data, $status)->header('Cache-Control', 'no-store, private')->header('X-Robots-Tag', 'noindex');
    }
}
