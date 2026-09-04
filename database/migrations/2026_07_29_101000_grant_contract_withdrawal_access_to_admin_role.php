<?php

use Illuminate\Database\Migrations\Migration;
use Silber\Bouncer\BouncerFacade as Bouncer;
use Silber\Bouncer\Database\Ability;
use Silber\Bouncer\Database\Role;

return new class extends Migration
{
    /** @var array<string, array{title:string,group:string}> */
    private const ABILITIES = [
        'sales.withdrawals.view' => [
            'title' => 'View contract withdrawals',
            'group' => 'sales.withdrawals',
        ],
        'sales.withdrawals.manage' => [
            'title' => 'Manage contract withdrawals',
            'group' => 'sales.withdrawals',
        ],
    ];

    public function up(): void
    {
        $role = Role::query()->firstOrCreate(
            ['name' => 'admin'],
            ['title' => 'Administrator'],
        );

        foreach (self::ABILITIES as $name => $definition) {
            $ability = Ability::query()
                ->where('name', $name)
                ->whereNull('entity_id')
                ->whereNull('entity_type')
                ->first();

            if (! $ability) {
                $ability = new Ability;
                $ability->name = $name;
            }

            $ability->title = $definition['title'];
            $ability->options = ['group' => $definition['group']];
            $ability->save();

            Bouncer::unforbid($role)->to($ability);
            Bouncer::allow($role)->to($ability);
        }

        Bouncer::refresh($role);
    }

    public function down(): void
    {
        $role = Role::query()->where('name', 'admin')->first();

        foreach (array_keys(self::ABILITIES) as $name) {
            $ability = Ability::query()
                ->where('name', $name)
                ->whereNull('entity_id')
                ->whereNull('entity_type')
                ->first();

            if ($ability && $role) {
                Bouncer::disallow($role)->to($ability);
            }
        }

        if ($role) {
            Bouncer::refresh($role);
        }
    }
};
