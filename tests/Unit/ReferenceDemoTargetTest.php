<?php

namespace Tests\Unit;

use App\Support\ReferenceDemoTarget;
use PHPUnit\Framework\TestCase;

class ReferenceDemoTargetTest extends TestCase
{
    public function test_only_explicit_isolated_staging_target_is_allowed(): void
    {
        $target = $this->stagingTarget();
        $this->assertTrue(ReferenceDemoTarget::allows($target));
        foreach ([
            ['explicit_staging' => false],
            ['environment' => 'production'],
            ['database' => 'herrera_shop'],
            ['database' => 'herrera_new_migration'],
            ['app_url' => 'https://herrera.hr'],
            ['app_url' => 'https://herrera.herrera.hr.evil.example'],
            ['safe_mode' => false],
            ['safe_mode' => 'true'],
            ['driver' => 'sqlite'],
            ['split_connections' => true],
        ] as $unsafe) {
            $this->assertFalse(ReferenceDemoTarget::allows(array_replace($target, $unsafe)), json_encode($unsafe));
        }
    }

    public function test_default_command_target_remains_local_loopback_only(): void
    {
        $target = ['explicit_staging' => false, 'environment' => 'local', 'driver' => 'mysql', 'database' => 'herrera_new_migration', 'host' => '127.0.0.1'];
        $this->assertTrue(ReferenceDemoTarget::allows($target));
        foreach ([['host' => 'remote.example'], ['database' => 'herrera_redesign'], ['environment' => 'staging'], ['split_connections' => true]] as $unsafe) {
            $this->assertFalse(ReferenceDemoTarget::allows(array_replace($target, $unsafe)));
        }
    }

    private function stagingTarget(): array
    {
        return [
            'explicit_staging' => true, 'environment' => 'staging', 'driver' => 'mysql',
            'database' => 'herrera_redesign', 'app_url' => 'https://herrera.herrera.hr',
            'safe_mode' => true, 'split_connections' => false,
        ];
    }
}
