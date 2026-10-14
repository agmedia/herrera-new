<?php

namespace Tests\Feature\Admin;

use App\Models\Content\Reference\ReferenceProject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HerreraDemoReferenceCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_demo_command_refuses_non_local_environment_before_writing(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        $this->artisan('herrera:demo-reference')->expectsOutputToContain('restricted to the isolated local')->assertFailed();
        $this->assertSame(0, ReferenceProject::query()->count());
        $this->assertDatabaseCount('media', 0);
    }

    public function test_demo_command_refuses_any_other_database_driver_or_name(): void
    {
        $this->app->detectEnvironment(fn () => 'local');
        $this->artisan('herrera:demo-reference')->expectsOutputToContain('local loopback herrera_new_migration')->assertFailed();
        $this->assertSame(0, ReferenceProject::query()->count());
        $this->assertDatabaseCount('media', 0);
    }

    public function test_staging_option_refuses_production_environment_before_connecting(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        $this->artisan('herrera:demo-reference', ['--staging' => true])
            ->expectsOutputToContain('requires the isolated staging environment')->assertFailed();
        $this->assertDatabaseCount('content_reference_projects', 0);
    }

    public function test_staging_option_refuses_unverified_database_target(): void
    {
        $this->app->detectEnvironment(fn () => 'staging');
        config(['app.url' => 'https://herrera.herrera.hr', 'commerce.local_safe_mode' => true]);
        $this->artisan('herrera:demo-reference', ['--staging' => true])
            ->expectsOutputToContain('Staging demo requires herrera_redesign')->assertFailed();
        $this->assertDatabaseCount('content_reference_projects', 0);
    }
}
