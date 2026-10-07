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
        foreach (['spreadsheet' => 'Uvoz cijena i zaliha iz tablice', 'media' => 'Uvoz fotografija proizvoda'] as $module => $title) {
            $ability = Ability::query()->firstOrCreate(['name' => 'integrations.'.$module.'.manage', 'entity_id' => null, 'entity_type' => null], [
                'title' => $title, 'options' => ['group' => 'integrations.'.$module],
            ]);
            Bouncer::allow($role)->to($ability);
        }
        Bouncer::refresh($role);
    }

    public function down(): void {}
};
