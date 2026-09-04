<?php

namespace Tests;

use Database\Seeders\RoleAbilitySeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Schema;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Some feature tests do not run migrations (e.g. static page checks).
        // Seed role data only when Bouncer tables are present.
        if (Schema::hasTable('roles') && Schema::hasTable('abilities')) {
            $this->seed(RoleSeeder::class);
            $this->seed(RoleAbilitySeeder::class);
        }
    }
}
