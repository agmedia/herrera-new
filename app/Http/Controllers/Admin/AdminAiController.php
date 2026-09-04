<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\AdminAi\AdminAgentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminAiController extends Controller
{
    public function preview(Request $request, AdminAgentService $service): JsonResponse
    {
        $validated = $request->validate([
            'prompt' => ['required', 'string', 'max:4000'],
        ]);

        $result = $service->buildPlan((string) $validated['prompt'], $request->user());

        if (!$result['ok']) {
            return response()->json($this->withDeveloperNotice($result), 422);
        }

        $planId = (string) $result['plan_id'];
        $plans = (array) $request->session()->get('admin_ai_plans', []);
        $plans[$planId] = [
            'plan' => $result['plan'],
            'prompt' => $validated['prompt'],
            'created_at' => now()->toIso8601String(),
        ];

        if (count($plans) > 20) {
            $plans = array_slice($plans, -20, null, true);
        }

        $request->session()->put('admin_ai_plans', $plans);

        return response()->json([
            'ok' => true,
            'plan_id' => $planId,
            'summary' => $result['summary'],
            'actions' => $result['actions'],
            'warnings' => $result['warnings'],
            'domain_key' => $result['domain_key'] ?? null,
            'domain_title' => $result['domain_title'] ?? null,
            'function_steps' => $result['function_steps'] ?? [],
            'can_execute' => true,
        ]);
    }

    public function execute(Request $request, AdminAgentService $service): JsonResponse
    {
        $validated = $request->validate([
            'plan_id' => ['required', 'string', 'max:100'],
        ]);

        $planId = (string) $validated['plan_id'];
        $plans = (array) $request->session()->get('admin_ai_plans', []);
        $entry = $plans[$planId] ?? null;

        if (!$entry || !is_array($entry) || !isset($entry['plan']) || !is_array($entry['plan'])) {
            return response()->json($this->withDeveloperNotice([
                'ok' => false,
                'message' => 'Plan not found or expired. Preview again.',
            ]), 404);
        }

        $result = $service->executePlan($entry['plan'], $request->user());

        if (!$result['ok']) {
            return response()->json($this->withDeveloperNotice($result), 422);
        }

        unset($plans[$planId]);
        $request->session()->put('admin_ai_plans', $plans);

        return response()->json($result);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function withDeveloperNotice(array $payload): array
    {
        $notice = (string) config('admin_ai.fallback.notice', 'If this action is not possible, contact developers for estimate on delivery time and cost.');
        $contact = (string) config('admin_ai.fallback.contact', '');

        $payload['developer_notice'] = $contact !== '' ? $notice.' Contact: '.$contact : $notice;

        if (isset($payload['message']) && is_string($payload['message']) && $payload['message'] !== '') {
            $payload['message'] = $payload['message'].' '.$notice;
        }

        return $payload;
    }
}
