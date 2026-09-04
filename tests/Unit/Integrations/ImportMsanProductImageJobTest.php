<?php

namespace Tests\Unit\Integrations;

use App\Jobs\Integrations\Msan\ImportMsanProductImageJob;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use PHPUnit\Framework\TestCase;

class ImportMsanProductImageJobTest extends TestCase
{
    public function test_overlap_lock_outlives_the_job_timeout(): void
    {
        $job = new ImportMsanProductImageJob(123);
        $middleware = $job->middleware();

        $this->assertCount(1, $middleware);
        $this->assertInstanceOf(WithoutOverlapping::class, $middleware[0]);
        $this->assertSame(480, $middleware[0]->expiresAfter);
        $this->assertGreaterThan($job->timeout, $middleware[0]->expiresAfter);
    }
}
