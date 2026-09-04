<?php

namespace Tests\Feature\Admin;

use App\Livewire\Admin\User\AccessManager;
use App\Models\User;
use App\Services\Settings\SystemSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Silber\Bouncer\BouncerFacade as Bouncer;
use Silber\Bouncer\Database\Ability;
use Silber\Bouncer\Database\Role;
use Tests\TestCase;

class UserAccessFeatureTest extends TestCase
{
    use RefreshDatabase;

    public function test_superadmin_can_open_roles_and_abilities_page(): void
    {
        $admin = $this->makeUserWithRole('superadmin');

        $this->actingAs($admin)
            ->get('/admin/users/access')
            ->assertOk()
            ->assertSee(__('Roles & Abilities'))
            ->assertSee('Super Administrator is not shown in the matrix')
            ->assertDontSee('SUPER ADMINISTRATOR');
    }

    public function test_admin_and_editor_cannot_open_roles_and_abilities_page(): void
    {
        $admin = $this->makeUserWithRole('admin');
        $editor = $this->makeUserWithRole('editor');

        $this->actingAs($admin)
            ->get('/admin/users/access')
            ->assertForbidden();

        $this->actingAs($editor)
            ->get('/admin/users/access')
            ->assertForbidden();
    }

    public function test_loyalty_abilities_are_hidden_when_feature_is_disabled(): void
    {
        app(SystemSettingsService::class)->put('user_loyalty_enabled', false);

        $admin = $this->makeUserWithRole('superadmin');

        $this->actingAs($admin)
            ->get('/admin/users/access')
            ->assertOk()
            ->assertDontSee(__('Users / Loyalty'))
            ->assertDontSee('users.loyalty.view')
            ->assertDontSee('users.loyalty.adjust');
    }

    public function test_loyalty_abilities_are_visible_when_feature_is_enabled(): void
    {
        app(SystemSettingsService::class)->put('user_loyalty_enabled', true);

        $admin = $this->makeUserWithRole('superadmin');

        $this->actingAs($admin)
            ->get('/admin/users/access')
            ->assertOk()
            ->assertSee(__('Users / Loyalty'))
            ->assertSee('users.loyalty.view')
            ->assertSee('users.loyalty.adjust');
    }

    public function test_superadmin_can_create_ability_and_toggle_role_permission(): void
    {
        $admin = $this->makeUserWithRole('superadmin');
        $adminRole = Role::query()->where('name', 'admin')->firstOrFail();
        $roleMorph = $adminRole->getMorphClass();

        Livewire::actingAs($admin)
            ->test(AccessManager::class)
            ->set('form.name', 'users.view')
            ->set('form.title', 'View users')
            ->set('form.group', 'users')
            ->call('createAbility')
            ->assertHasNoErrors();

        $ability = Ability::query()
            ->where('name', 'users.view')
            ->whereNull('entity_id')
            ->whereNull('entity_type')
            ->first();

        $this->assertNotNull($ability);
        $this->assertSame('users', data_get($ability?->options, 'group'));

        Livewire::actingAs($admin)
            ->test(AccessManager::class)
            ->call('togglePermission', $ability->id, $adminRole->id);

        $this->assertDatabaseHas('permissions', [
            'entity_type' => $roleMorph,
            'entity_id' => $adminRole->id,
            'ability_id' => $ability->id,
            'forbidden' => 0,
        ]);

        Livewire::actingAs($admin)
            ->test(AccessManager::class)
            ->call('togglePermission', $ability->id, $adminRole->id);

        $this->assertDatabaseMissing('permissions', [
            'entity_type' => $roleMorph,
            'entity_id' => $adminRole->id,
            'ability_id' => $ability->id,
            'forbidden' => 0,
        ]);
    }

    private function makeUserWithRole(string $role): User
    {
        Bouncer::role()->firstOrCreate(['name' => 'superadmin'], ['title' => 'Super Administrator']);
        Bouncer::role()->firstOrCreate(['name' => 'admin'], ['title' => 'Administrator']);
        Bouncer::role()->firstOrCreate(['name' => 'editor'], ['title' => 'Editor']);
        Bouncer::role()->firstOrCreate(['name' => 'customer'], ['title' => 'Customer']);

        $user = User::factory()->create();
        Bouncer::assign($role)->to($user);

        return $user;
    }
}
