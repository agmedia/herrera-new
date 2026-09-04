<?php

namespace Tests\Feature\Settings;

use App\Services\Settings\SystemSettingsService;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Event;
use Mockery;
use Tests\TestCase;

class SystemSettingsQueueCacheTest extends TestCase
{
    use RefreshDatabase;

    public function test_queue_job_refreshes_the_process_local_settings_cache(): void
    {
        $workerSettings = app(SystemSettingsService::class);
        $workerSettings->put('queue_cache_test', 'disabled');

        $this->assertSame('disabled', $workerSettings->get('queue_cache_test'));

        $adminRequestSettings = new SystemSettingsService;
        $adminRequestSettings->put('queue_cache_test', 'enabled');

        $this->assertSame('disabled', $workerSettings->get('queue_cache_test'));

        $job = Mockery::mock(Job::class);
        $job->shouldReceive('payload')->andReturn([]);

        Event::dispatch(new JobProcessing('database', $job));

        $this->assertSame('enabled', $workerSettings->get('queue_cache_test'));
    }
}
