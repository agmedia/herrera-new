<?php

use Illuminate\Database\Migrations\Migration;
use Silber\Bouncer\BouncerFacade as Bouncer;
use Silber\Bouncer\Database\Ability;
use Silber\Bouncer\Database\Role;

return new class extends Migration
{
    public function up(): void
    {
        $ability = Ability::query()
            ->where('name', 'settings.system.store.manage')
            ->whereNull('entity_id')
            ->whereNull('entity_type')
            ->first();

        if (! $ability) {
            $ability = new Ability;
            $ability->name = 'settings.system.store.manage';
        }

        $ability->title = 'Manage store settings';
        $ability->options = ['group' => 'settings.system'];
        $ability->save();

        $role = Role::query()->firstOrCreate(
            ['name' => 'admin'],
            ['title' => 'Administrator'],
        );

        Bouncer::unforbid($role)->to($ability);
        Bouncer::allow($role)->to($ability);
        Bouncer::refresh($role);
    }

    public function down(): void
    {
        $ability = Ability::query()
            ->where('name', 'settings.system.store.manage')
            ->whereNull('entity_id')
            ->whereNull('entity_type')
            ->first();

        $role = Role::query()->where('name', 'admin')->first();

        if ($ability && $role) {
            Bouncer::disallow($role)->to($ability);
            Bouncer::refresh($role);
        }
    }
};
