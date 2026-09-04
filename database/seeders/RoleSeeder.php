<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Silber\Bouncer\BouncerFacade as Bouncer;

class RoleSeeder extends Seeder
{
    /**
     * Seed Bouncer roles used by admin and storefront access control.
     */
    public function run(): void
    {
        Bouncer::role()->firstOrCreate(['name' => 'superadmin'], ['title' => 'Super Administrator']);
        Bouncer::role()->firstOrCreate(['name' => 'admin'], ['title' => 'Administrator']);
        Bouncer::role()->firstOrCreate(['name' => 'editor'], ['title' => 'Editor']);
        Bouncer::role()->firstOrCreate(['name' => 'customer'], ['title' => 'Customer']);
    }
}
