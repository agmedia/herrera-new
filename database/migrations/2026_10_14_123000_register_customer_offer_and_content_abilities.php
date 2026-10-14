<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Silber\Bouncer\BouncerFacade as Bouncer;
use Silber\Bouncer\Database\Ability;
use Silber\Bouncer\Database\Role;

return new class extends Migration
{
    public function up(): void
    {
        $names = [
            'sales.offers.view', 'sales.offers.manage', 'sales.offers.send',
            'content.supplier_catalogs.manage',
            'content.references.view', 'content.references.create',
            'content.references.update', 'content.references.delete',
        ];
        foreach (config('admin_acl.abilities', []) as $definition) {
            if (! in_array($definition['name'], $names, true)) {
                continue;
            }
            $ability = Ability::query()->firstOrCreate([
                'name' => $definition['name'], 'entity_id' => null, 'entity_type' => null,
            ], ['title' => $definition['title'], 'options' => ['group' => $definition['group']]]);
            foreach (['admin', 'order_manager'] as $roleName) {
                if ($roleName === 'order_manager' && ! str_starts_with($definition['name'], 'sales.offers.')) {
                    continue;
                }
                $role = Role::query()->where('name', $roleName)->first();
                if (! $role || DB::table('permissions')->where('entity_type', $role->getMorphClass())
                    ->where('entity_id', $role->id)->where('ability_id', $ability->id)->exists()) {
                    continue;
                }
                Bouncer::allow($role)->to($ability);
            }
        }
        Bouncer::refresh();
    }

    public function down(): void
    {
        // Retain explicit grants/denials and their audit history on code rollback.
    }
};
