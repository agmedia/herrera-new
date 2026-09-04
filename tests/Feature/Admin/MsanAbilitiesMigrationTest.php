<?php

namespace Tests\Feature\Admin;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Silber\Bouncer\BouncerFacade as Bouncer;
use Silber\Bouncer\Database\Ability;
use Silber\Bouncer\Database\Role;
use Tests\TestCase;

class MsanAbilitiesMigrationTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, string> */
    private const ABILITIES = [
        'integrations.msan.view' => 'Pregled M SAN integracije',
        'integrations.msan.settings.manage' => 'Upravljanje M SAN postavkama',
        'integrations.msan.sync.run' => 'Pokretanje M SAN sinkronizacije',
        'integrations.msan.mapping.manage' => 'Upravljanje M SAN mapiranjem',
        'integrations.msan.import.manage' => 'Upravljanje M SAN uvozom',
    ];

    public function test_up_creates_the_msan_abilities_and_grants_them_to_admin_idempotently(): void
    {
        $this->removeMsanAbilities();
        Role::query()->where('name', 'admin')->delete();

        $migration = $this->migration();
        $migration->up();
        $migration->up();

        $admin = Role::query()->where('name', 'admin')->firstOrFail();

        $this->assertSame('Administrator', $admin->title);
        $this->assertSame(
            count(self::ABILITIES),
            Ability::query()
                ->whereIn('name', array_keys(self::ABILITIES))
                ->whereNull('entity_id')
                ->whereNull('entity_type')
                ->count(),
        );

        foreach (self::ABILITIES as $name => $title) {
            $ability = $this->globalAbility($name);

            $this->assertSame($title, $ability->title);
            $this->assertSame(['group' => 'integrations.msan'], $ability->options);
            $this->assertAdminPermissionExists($admin, $ability);
        }
    }

    public function test_down_revokes_only_admin_grants_and_preserves_abilities_and_other_role_grants(): void
    {
        $this->removeMsanAbilities();

        $migration = $this->migration();
        $migration->up();

        $admin = Role::query()->where('name', 'admin')->firstOrFail();
        $auditor = Role::query()->firstOrCreate(
            ['name' => 'msan-auditor'],
            ['title' => 'M SAN auditor'],
        );
        $viewAbility = $this->globalAbility('integrations.msan.view');

        Bouncer::allow($auditor)->to($viewAbility);
        Bouncer::refresh($auditor);

        $migration->down();

        foreach (array_keys(self::ABILITIES) as $name) {
            $ability = $this->globalAbility($name);

            $this->assertAdminPermissionMissing($admin, $ability);
        }

        $this->assertPermissionExists($auditor, $viewAbility);
    }

    private function migration(): Migration
    {
        return require database_path('migrations/2026_08_30_086000_grant_msan_integration_access_to_admin_role.php');
    }

    private function removeMsanAbilities(): void
    {
        $abilityIds = Ability::query()
            ->whereIn('name', array_keys(self::ABILITIES))
            ->whereNull('entity_id')
            ->whereNull('entity_type')
            ->pluck('id');

        DB::table('permissions')->whereIn('ability_id', $abilityIds)->delete();
        Ability::query()->whereKey($abilityIds)->delete();
        Bouncer::refresh();
    }

    private function globalAbility(string $name): Ability
    {
        return Ability::query()
            ->where('name', $name)
            ->whereNull('entity_id')
            ->whereNull('entity_type')
            ->firstOrFail();
    }

    private function assertAdminPermissionExists(Role $admin, Ability $ability): void
    {
        $this->assertPermissionExists($admin, $ability);
        $this->assertDatabaseMissing('permissions', [
            'ability_id' => $ability->id,
            'entity_type' => $admin->getMorphClass(),
            'entity_id' => $admin->id,
            'forbidden' => true,
        ]);
    }

    private function assertAdminPermissionMissing(Role $admin, Ability $ability): void
    {
        $this->assertDatabaseMissing('permissions', [
            'ability_id' => $ability->id,
            'entity_type' => $admin->getMorphClass(),
            'entity_id' => $admin->id,
        ]);
    }

    private function assertPermissionExists(Role $role, Ability $ability): void
    {
        $this->assertDatabaseHas('permissions', [
            'ability_id' => $ability->id,
            'entity_type' => $role->getMorphClass(),
            'entity_id' => $role->id,
            'forbidden' => false,
        ]);
    }
}
