<?php

namespace App\Jobs\Integrations\Msan;

use App\Models\Integrations\Msan\MsanSyncRun;
use App\Services\Integrations\Msan\MsanClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class TestMsanConnectionJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 360;

    public function __construct(private readonly int $runId) {}

    public function handle(MsanClient $client): void
    {
        $run = MsanSyncRun::query()->find($this->runId);
        if (! $run) {
            return;
        }

        $run->forceFill([
            'status' => MsanSyncRun::STATUS_RUNNING,
            'started_at' => now(),
            'error_message' => null,
        ])->save();

        try {
            $result = $client->testConnection();
            $run->forceFill([
                'status' => MsanSyncRun::STATUS_COMPLETED,
                'progress' => 100,
                'total_count' => 1,
                'processed_count' => 1,
                'succeeded_count' => 1,
                'summary' => ['connection' => $result],
                'completed_at' => now(),
            ])->save();
        } catch (Throwable $exception) {
            $message = preg_replace('/(password|passphrase|pin)\s*[=:]\s*\S+/iu', '$1=[skriveno]', $exception->getMessage()) ?? $exception->getMessage();
            $run->forceFill([
                'status' => MsanSyncRun::STATUS_FAILED,
                'failed_count' => 1,
                'error_message' => mb_substr(trim($message), 0, 1500),
                'completed_at' => now(),
            ])->save();
            throw $exception;
        }
    }

    public function failed(?Throwable $exception): void
    {
        $message = preg_replace(
            '/(password|passphrase|pin)\s*[=:]\s*\S+/iu',
            '$1=[skriveno]',
            $exception?->getMessage() ?: 'Provjera M SAN veze nije dovršena.',
        );

        MsanSyncRun::query()
            ->whereKey($this->runId)
            ->where('status', '!=', MsanSyncRun::STATUS_COMPLETED)
            ->update([
                'status' => MsanSyncRun::STATUS_FAILED,
                'failed_count' => 1,
                'error_message' => mb_substr(trim((string) $message), 0, 1500),
                'completed_at' => now(),
            ]);
    }
}
