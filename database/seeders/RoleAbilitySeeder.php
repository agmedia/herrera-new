<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Silber\Bouncer\BouncerFacade as Bouncer;
use Silber\Bouncer\Database\Ability;
use Silber\Bouncer\Database\Role;

class RoleAbilitySeeder extends Seeder
{
    public function run(): void
    {
        /** @var array<int, array{name:string,title:string,group:string}> $abilityDefinitions */
        $abilityDefinitions = config('admin_acl.abilities', []);
        /** @var array<string, array<int, string>> $roleDefaults */
        $roleDefaults = config('admin_acl.roles', []);

        $abilityIdsByName = [];

        foreach ($abilityDefinitions as $definition) {
            $ability = Ability::query()
                ->where('name', $definition['name'])
                ->whereNull('entity_id')
                ->whereNull('entity_type')
                ->first();

            if (!$ability) {
                $ability = new Ability();
                $ability->name = $definition['name'];
            }

            $ability->title = $definition['title'];
            $ability->options = ['group' => $definition['group']];
            $ability->save();

            $abilityIdsByName[$definition['name']] = (int) $ability->id;
        }

        // Superadmin gets full wildcard access by default.
        Bouncer::allow('superadmin')->everything();

        /** @var array<int, int> $projectAbilityIds */
        $projectAbilityIds = array_values($abilityIdsByName);

        foreach ($roleDefaults as $roleName => $abilities) {
            $role = Role::query()->where('name', $roleName)->first();
            if (!$role) {
                continue;
            }

            $roleMorph = $role->getMorphClass();

            // Reset only project-defined abilities to keep seeder deterministic.
            DB::table('permissions')
                ->where('entity_type', $roleMorph)
                ->where('entity_id', $role->id)
                ->whereIn('ability_id', $projectAbilityIds)
                ->delete();

            foreach ($abilities as $abilityName) {
                if (!isset($abilityIdsByName[$abilityName])) {
                    continue;
                }

                Bouncer::allow($role)->to($abilityName);
            }
        }
    }
}

