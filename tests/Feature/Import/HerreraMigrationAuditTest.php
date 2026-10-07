<?php

namespace Tests\Feature\Import;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class HerreraMigrationAuditTest extends TestCase
{
    use RefreshDatabase;

    public function test_aggregate_audit_refuses_an_unapproved_database_and_writes_no_report(): void
    {
        Storage::fake('local');
        $this->artisan('herrera:audit', ['--report' => true])
            ->expectsOutput('Audit is restricted to the isolated local Herrera migration and source snapshot.')
            ->assertFailed();
        Storage::disk('local')->assertMissing('herrera/migration-audit.json');
    }
}
