<?php

namespace Tests\Feature\Admin;

use App\Livewire\Admin\Catalog\Attribute\GroupManager;
use App\Models\Catalog\Attribute\Attribute;
use App\Models\Catalog\Attribute\AttributeGroup;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

class CatalogAttributeGroupDeletionSafetyTest extends TestCase
{
    use RefreshDatabase;

    public function test_database_prevents_deleting_a_group_that_has_attributes(): void
    {
        $user = User::factory()->create();
        $group = $this->createGroup($user, 'protected-group');
        $this->createAttribute($user, $group, 'protected-value');

        try {
            DB::table('catalog_attribute_groups')->where('id', $group->id)->delete();
            $this->fail('The attribute group foreign key should restrict deletion.');
        } catch (QueryException) {
            $this->assertDatabaseHas('catalog_attribute_groups', ['id' => $group->id]);
            $this->assertDatabaseHas('catalog_attributes', ['attribute_group_id' => $group->id]);
        }
    }

    public function test_group_manager_reports_used_and_missing_groups_and_deletes_an_empty_group(): void
    {
        $user = User::factory()->create();
        $usedGroup = $this->createGroup($user, 'used-group');
        $this->createAttribute($user, $usedGroup, 'used-value');
        $emptyGroup = $this->createGroup($user, 'empty-group');

        Livewire::actingAs($user)
            ->test(GroupManager::class)
            ->call('delete', $usedGroup->id)
            ->assertDispatched(
                'notify',
                type: 'warning',
                message: __('Delete the attributes in this group first.'),
            );

        Livewire::actingAs($user)
            ->test(GroupManager::class)
            ->call('delete', max($usedGroup->id, $emptyGroup->id) + 1000)
            ->assertDispatched(
                'notify',
                type: 'warning',
                message: __('Attribute group not found.'),
            );

        Livewire::actingAs($user)
            ->test(GroupManager::class)
            ->call('delete', $emptyGroup->id)
            ->assertDispatched(
                'notify',
                type: 'success',
                message: __('Attribute group deleted.'),
            );

        $this->assertDatabaseHas('catalog_attribute_groups', ['id' => $usedGroup->id]);
        $this->assertDatabaseMissing('catalog_attribute_groups', ['id' => $emptyGroup->id]);
    }

    private function createGroup(User $user, string $code): AttributeGroup
    {
        return AttributeGroup::query()->create([
            'code' => $code,
            'type' => Attribute::TYPE_SELECT,
            'sort_order' => 10,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);
    }

    private function createAttribute(User $user, AttributeGroup $group, string $code): Attribute
    {
        return Attribute::query()->create([
            'attribute_group_id' => $group->id,
            'code' => $code,
            'group_code' => $group->code,
            'type' => $group->type,
            'is_active' => true,
            'sort_order' => 10,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);
    }
}
