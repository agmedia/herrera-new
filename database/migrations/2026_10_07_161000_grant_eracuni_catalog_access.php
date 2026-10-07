<?php

use Illuminate\Database\Migrations\Migration;
use Silber\Bouncer\BouncerFacade as Bouncer;
use Silber\Bouncer\Database\Ability;
use Silber\Bouncer\Database\Role;

return new class extends Migration
{
    public function up(): void
    {
        $role = Role::query()->firstOrCreate(['name' => 'admin'], ['title' => 'Administrator']);
        $ability = Ability::query()->firstOrCreate(['name' => 'integrations.eracuni.manage', 'entity_id' => null, 'entity_type' => null], [
            'title' => 'Upravljanje e-Računi katalogom', 'options' => ['group' => 'integrations.eracuni'],
        ]);
        Bouncer::allow($role)->to($ability);
        Bouncer::refresh($role);
    }

    public function down(): void {}
};
