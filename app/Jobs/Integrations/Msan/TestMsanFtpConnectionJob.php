<?php

namespace App\Jobs\Integrations\Msan;

use App\Models\Integrations\Msan\MsanSyncRun;
use App\Services\Integrations\Msan\MsanFtpClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class TestMsanFtpConnectionJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 180;

    public function __construct(private readonly int $runId) {}

    public function handle(MsanFtpClient $client): void
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
                'summary' => ['ftp_connection' => $result],
                'completed_at' => now(),
            ])->save();
        } catch (Throwable $exception) {
            $this->markFailed($exception->getMessage());
            throw $exception;
        }
    }

    public function failed(?Throwable $exception): void
    {
        $this->markFailed($exception?->getMessage() ?: 'Provjera M SAN FTPS veze nije dovršena.');
    }

    private function markFailed(string $message): void
    {
        $message = preg_replace('/(password|passphrase|pin)\s*[=:]\s*\S+/iu', '$1=[skriveno]', $message) ?? $message;
        MsanSyncRun::query()
            ->whereKey($this->runId)
            ->where('status', '!=', MsanSyncRun::STATUS_COMPLETED)
            ->update([
                'status' => MsanSyncRun::STATUS_FAILED,
                'failed_count' => 1,
                'error_message' => mb_substr(trim($message), 0, 1500),
                'completed_at' => now(),
            ]);
    }
}
