<?php

namespace Tests\Feature\Admin;

use App\Livewire\Admin\Catalog\Option\ValueManager;
use App\Livewire\Admin\Content\Comment\Manager as CommentManager;
use App\Livewire\Admin\Integrations\Msan\SpecificationMappingManager;
use App\Livewire\Admin\User\GroupManager;
use App\Models\Catalog\Option\Option;
use App\Models\Catalog\Option\OptionValue;
use App\Models\Content\Support\Comment;
use App\Models\Integrations\Msan\MsanSpecificationDefinition;
use App\Models\User;
use App\Models\User\CustomerGroup;
use App\Services\Settings\SystemSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Silber\Bouncer\BouncerFacade as Bouncer;
use Tests\TestCase;

class RemainingAdminEditNavigationFeatureTest extends TestCase
{
    use RefreshDatabase;

    public function test_option_value_list_links_to_a_standalone_editor(): void
    {
        app(SystemSettingsService::class)->put('catalog_use_options', true);

        $admin = $this->makeAdmin();
        $option = $this->makeOption('navigation-color');
        $value = $this->makeOptionValue($option, 'navigation-blue');
        $createUrl = route('admin.options.values.create', [
            'option' => $option->id,
            'locale' => 'en',
            'search' => 'navigation',
        ]);
        $editUrl = route('admin.options.values.edit', [
            'option' => $option->id,
            'value' => $value->id,
            'locale' => 'en',
            'search' => 'navigation',
        ]);
        $listUrl = route('admin.options.values', [
            'option' => $option->id,
            'locale' => 'en',
            'search' => 'navigation',
        ]);

        $this->actingAs($admin)
            ->get($listUrl)
            ->assertOk()
            ->assertSee('href="'.e($createUrl).'"', false)
            ->assertSee('href="'.e($editUrl).'"', false)
            ->assertDontSee('wire:model="form.name"', false)
            ->assertDontSee('wire:click="edit('.$value->id.')"', false);

        $this->actingAs($admin)
            ->get($createUrl)
            ->assertOk()
            ->assertSee('wire:model="form.name"', false)
            ->assertSee('href="'.e($listUrl).'"', false)
            ->assertDontSee('<table class="admin-items-table', false);

        $this->actingAs($admin)
            ->get($editUrl)
            ->assertOk()
            ->assertSee('wire:model="form.name"', false)
            ->assertSee('href="'.e($listUrl).'"', false)
            ->assertDontSee('<table class="admin-items-table', false);
    }

    public function test_standalone_option_value_creator_stores_the_record_and_redirects_to_the_list(): void
    {
        app(SystemSettingsService::class)->put('catalog_use_options', true);

        $admin = $this->makeAdmin();
        $option = $this->makeOption('create-color');

        Livewire::withQueryParams([
            'locale' => 'en',
            'search' => 'Green',
            'page' => 2,
        ])
            ->actingAs($admin)
            ->test(ValueManager::class, [
                'optionId' => $option->id,
                'createPage' => true,
            ])
            ->assertSet('editingId', null)
            ->set('form.code', 'create-green')
            ->set('form.name', 'Green')
            ->set('form.slug', 'create-green')
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect(route('admin.options.values', [
                'option' => $option->id,
                'locale' => 'en',
                'search' => 'Green',
                'page' => 2,
            ]))
            ->assertSessionHas('notify.type', 'success');

        $this->assertDatabaseHas('catalog_option_values', [
            'option_id' => $option->id,
            'code' => 'create-green',
        ]);
        $this->assertDatabaseHas('catalog_option_value_translations', [
            'locale' => 'en',
            'name' => 'Green',
            'slug' => 'create-green',
        ]);
    }

    public function test_standalone_option_value_editor_updates_the_record_and_redirects_to_the_list(): void
    {
        app(SystemSettingsService::class)->put('catalog_use_options', true);

        $admin = $this->makeAdmin();
        $option = $this->makeOption('editor-color');
        $value = $this->makeOptionValue($option, 'editor-blue');

        Livewire::withQueryParams(['locale' => 'en'])
            ->actingAs($admin)
            ->test(ValueManager::class, [
                'optionId' => $option->id,
                'recordId' => $value->id,
                'editPage' => true,
            ])
            ->assertSet('editingId', $value->id)
            ->assertSet('editPage', true)
            ->set('form.code', 'editor-navy')
            ->set('form.name', 'Navy')
            ->set('form.slug', 'editor-navy')
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect(route('admin.options.values', [
                'option' => $option->id,
                'locale' => 'en',
            ]))
            ->assertSessionHas('notify.type', 'success');

        $this->assertDatabaseHas('catalog_option_values', [
            'id' => $value->id,
            'option_id' => $option->id,
            'code' => 'editor-navy',
        ]);
        $this->assertDatabaseHas('catalog_option_value_translations', [
            'option_value_id' => $value->id,
            'locale' => 'en',
            'name' => 'Navy',
            'slug' => 'editor-navy',
        ]);
    }

    public function test_option_value_editor_returns_not_found_for_a_value_owned_by_another_option(): void
    {
        app(SystemSettingsService::class)->put('catalog_use_options', true);

        $admin = $this->makeAdmin();
        $requestedOption = $this->makeOption('requested-option');
        $owningOption = $this->makeOption('owning-option');
        $value = $this->makeOptionValue($owningOption, 'owned-value');

        $this->actingAs($admin)
            ->get(route('admin.options.values.edit', [
                'option' => $requestedOption->id,
                'value' => $value->id,
                'locale' => 'en',
            ]))
            ->assertNotFound();
    }

    public function test_user_group_list_links_to_a_standalone_editor(): void
    {
        $admin = $this->makeAdmin();
        $group = $this->makeCustomerGroup('navigation-group');
        $createUrl = route('admin.users.groups.create', [
            'search' => 'navigation',
        ]);
        $editUrl = route('admin.users.groups.edit', [
            'customerGroup' => $group->id,
            'search' => 'navigation',
        ]);
        $listUrl = route('admin.users.groups', [
            'search' => 'navigation',
        ]);

        $this->actingAs($admin)
            ->get($listUrl)
            ->assertOk()
            ->assertSee('href="'.e($createUrl).'"', false)
            ->assertSee('href="'.e($editUrl).'"', false)
            ->assertDontSee('wire:model="form.name"', false)
            ->assertDontSee('wire:click="edit('.$group->id.')"', false);

        $this->actingAs($admin)
            ->get($createUrl)
            ->assertOk()
            ->assertSee('wire:model="form.name"', false)
            ->assertSee('href="'.e($listUrl).'"', false)
            ->assertDontSee('<table class="admin-items-table', false);

        $this->actingAs($admin)
            ->get($editUrl)
            ->assertOk()
            ->assertSee('wire:model="form.name"', false)
            ->assertSee('href="'.e($listUrl).'"', false)
            ->assertDontSee('<table class="admin-items-table', false);
    }

    public function test_standalone_user_group_creator_stores_the_record_and_redirects_to_the_list(): void
    {
        $admin = $this->makeAdmin();

        Livewire::withQueryParams([
            'search' => 'Navigation',
            'adminUserGroupsPage' => 4,
        ])->actingAs($admin)
            ->test(GroupManager::class, [
                'createPage' => true,
            ])
            ->assertSet('editingId', null)
            ->set('form.code', 'create-navigation-group')
            ->set('form.name', 'Create navigation group')
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect(route('admin.users.groups', [
                'search' => 'Navigation',
                'adminUserGroupsPage' => 4,
            ]))
            ->assertSessionHas('notify.type', 'success');

        $this->assertDatabaseHas('customer_groups', [
            'code' => 'create-navigation-group',
            'name' => 'Create navigation group',
        ]);
    }

    public function test_standalone_user_group_editor_updates_the_record_and_redirects_to_the_list(): void
    {
        $admin = $this->makeAdmin();
        $group = $this->makeCustomerGroup('editor-group');

        Livewire::actingAs($admin)
            ->test(GroupManager::class, [
                'recordId' => $group->id,
                'editPage' => true,
            ])
            ->assertSet('editingId', $group->id)
            ->assertSet('editPage', true)
            ->set('form.name', 'Updated customer group')
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect(route('admin.users.groups'))
            ->assertSessionHas('notify.type', 'success');

        $this->assertDatabaseHas('customer_groups', [
            'id' => $group->id,
            'name' => 'Updated customer group',
        ]);
    }

    public function test_comment_list_links_to_a_standalone_editor(): void
    {
        $admin = $this->makeAdmin();
        $comment = $this->makeComment('Comment shown in the navigation list.');
        $editUrl = route('admin.content.comments.edit', [
            'comment' => $comment->id,
        ]);

        $this->actingAs($admin)
            ->get(route('admin.content.comments.index'))
            ->assertOk()
            ->assertSee('href="'.$editUrl.'"', false)
            ->assertDontSee('wire:click="edit('.$comment->id.')"', false);

        $this->actingAs($admin)
            ->get($editUrl)
            ->assertOk()
            ->assertSee('wire:model="editForm.body"', false)
            ->assertDontSee('<table class="admin-items-table', false);
    }

    public function test_comment_editor_preserves_active_list_filters(): void
    {
        $admin = $this->makeAdmin();
        $comment = $this->makeComment('Filtered navigation comment.');
        $filters = [
            'search' => 'Filtered navigation',
            'status' => 'all',
            'locale' => 'en',
        ];
        $editUrl = route('admin.content.comments.edit', array_merge([
            'comment' => $comment->id,
        ], $filters));
        $listUrl = route('admin.content.comments.index', $filters);

        $this->actingAs($admin)
            ->get(route('admin.content.comments.index', $filters))
            ->assertOk()
            ->assertSee('href="'.e($editUrl).'"', false);

        $this->actingAs($admin)
            ->get($editUrl)
            ->assertOk()
            ->assertSee('href="'.e($listUrl).'"', false);
    }

    public function test_standalone_comment_editor_updates_the_record_and_redirects_to_the_list(): void
    {
        $admin = $this->makeAdmin();
        $comment = $this->makeComment('Original comment body.');
        $filters = [
            'search' => 'Original',
            'status' => 'all',
            'locale' => 'en',
        ];

        Livewire::withQueryParams($filters)
            ->actingAs($admin)
            ->test(CommentManager::class, [
                'recordId' => $comment->id,
                'editPage' => true,
            ])
            ->assertSet('editingId', $comment->id)
            ->assertSet('editPage', true)
            ->set('editForm.body', 'Updated standalone comment body.')
            ->set('editForm.status', Comment::STATUS_APPROVED)
            ->call('saveEdit')
            ->assertHasNoErrors()
            ->assertRedirect(route('admin.content.comments.index', $filters))
            ->assertSessionHas('notify.type', 'success');

        $this->assertDatabaseHas('content_comments', [
            'id' => $comment->id,
            'body' => 'Updated standalone comment body.',
            'status' => Comment::STATUS_APPROVED,
            'reviewed_by' => $admin->id,
        ]);
    }

    public function test_comment_editor_returns_to_the_list_if_the_comment_disappears_before_save(): void
    {
        $admin = $this->makeAdmin();
        $comment = $this->makeComment('Comment deleted during editing.');

        $component = Livewire::actingAs($admin)
            ->test(CommentManager::class, [
                'recordId' => $comment->id,
                'editPage' => true,
            ]);

        $comment->delete();

        $component
            ->call('saveEdit')
            ->assertRedirect(route('admin.content.comments.index'))
            ->assertSessionHas('notify.type', 'warning');
    }

    public function test_msan_specification_list_links_to_a_standalone_editor(): void
    {
        $admin = $this->makeAdmin();
        $definition = $this->makeSpecification('navigation-specification');
        $editUrl = route('admin.integrations.msan.specifications.edit', [
            'definition' => $definition->id,
        ]);

        $this->actingAs($admin)
            ->get(route('admin.integrations.msan.specifications'))
            ->assertOk()
            ->assertSee('href="'.$editUrl.'"', false)
            ->assertDontSee('wire:click="openEditor('.$definition->id.')"', false);

        $this->actingAs($admin)
            ->get($editUrl)
            ->assertOk()
            ->assertSee('wire:model="displayItemName"', false)
            ->assertDontSee('<table class="admin-items-table', false);
    }

    public function test_standalone_msan_specification_editor_updates_the_record_and_redirects_to_the_list(): void
    {
        Queue::fake();

        $admin = $this->makeAdmin();
        $definition = $this->makeSpecification('editor-specification');

        Livewire::actingAs($admin)
            ->test(SpecificationMappingManager::class, [
                'recordId' => $definition->id,
                'editPage' => true,
            ])
            ->assertSet('editingDefinitionId', $definition->id)
            ->assertSet('editPage', true)
            ->set('displayItemName', 'Updated specification label')
            ->call('saveDefinition')
            ->assertHasNoErrors()
            ->assertRedirect(route('admin.integrations.msan.specifications'))
            ->assertSessionHas('notify.type', 'success');

        $this->assertDatabaseHas('msan_specification_definitions', [
            'id' => $definition->id,
            'display_item_name' => 'Updated specification label',
            'updated_by' => $admin->id,
        ]);
    }

    public function test_admin_access_alone_cannot_open_the_new_standalone_edit_pages(): void
    {
        app(SystemSettingsService::class)->put('catalog_use_options', true);

        $user = $this->makeUserWithAbilities(['admin.access']);
        $option = $this->makeOption('access-only-option');
        $value = $this->makeOptionValue($option, 'access-only-value');
        $group = $this->makeCustomerGroup('access-only-group');
        $comment = $this->makeComment('Access-only comment.');
        $definition = $this->makeSpecification('access-only-specification');

        $editUrls = [
            route('admin.options.values.create', [
                'option' => $option->id,
                'locale' => 'en',
            ]),
            route('admin.options.values.edit', [
                'option' => $option->id,
                'value' => $value->id,
                'locale' => 'en',
            ]),
            route('admin.users.groups.create'),
            route('admin.users.groups.edit', ['customerGroup' => $group->id]),
            route('admin.content.comments.edit', ['comment' => $comment->id]),
            route('admin.integrations.msan.specifications.edit', ['definition' => $definition->id]),
        ];

        foreach ($editUrls as $editUrl) {
            $this->actingAs($user)
                ->get($editUrl)
                ->assertForbidden();
        }
    }

    public function test_comment_viewer_without_moderation_ability_cannot_open_comment_editor(): void
    {
        $viewer = $this->makeUserWithAbilities([
            'admin.access',
            'content.comments.view',
        ]);
        $comment = $this->makeComment('Viewer-only comment.');

        $this->actingAs($viewer)
            ->get(route('admin.content.comments.index'))
            ->assertOk();

        $this->actingAs($viewer)
            ->get(route('admin.content.comments.edit', ['comment' => $comment->id]))
            ->assertForbidden();
    }

    public function test_msan_viewer_without_mapping_manage_ability_cannot_open_specification_editor(): void
    {
        $viewer = $this->makeUserWithAbilities([
            'admin.access',
            'integrations.msan.view',
        ]);
        $definition = $this->makeSpecification('viewer-only-specification');

        $this->actingAs($viewer)
            ->get(route('admin.integrations.msan.specifications'))
            ->assertOk();

        $this->actingAs($viewer)
            ->get(route('admin.integrations.msan.specifications.edit', [
                'definition' => $definition->id,
            ]))
            ->assertForbidden();
    }

    private function makeAdmin(): User
    {
        $user = User::factory()->create();
        Bouncer::assign('admin')->to($user);

        return $user;
    }

    /**
     * @param  array<int, string>  $abilities
     */
    private function makeUserWithAbilities(array $abilities): User
    {
        $user = User::factory()->create();
        Bouncer::allow($user)->to($abilities);
        Bouncer::refreshFor($user);

        return $user;
    }

    private function makeOption(string $code): Option
    {
        $option = Option::query()->create([
            'code' => $code,
            'type' => Option::TYPE_SELECT,
            'is_active' => true,
            'sort_order' => 10,
            'payload' => null,
        ]);

        $option->translations()->create([
            'locale' => 'en',
            'name' => str($code)->headline()->toString(),
            'slug' => $code,
            'description' => null,
            'payload' => null,
        ]);

        return $option;
    }

    private function makeOptionValue(Option $option, string $code): OptionValue
    {
        $value = $option->values()->create([
            'code' => $code,
            'is_active' => true,
            'sort_order' => 10,
            'payload' => null,
        ]);

        $value->translations()->create([
            'locale' => 'en',
            'name' => str($code)->headline()->toString(),
            'slug' => $code,
            'payload' => null,
        ]);

        return $value;
    }

    private function makeCustomerGroup(string $code): CustomerGroup
    {
        return CustomerGroup::query()->create([
            'code' => $code,
            'name' => str($code)->headline()->toString(),
            'description' => 'Standalone editor navigation test group.',
            'is_active' => true,
            'is_default' => false,
            'sort_order' => 90,
            'payload' => null,
        ]);
    }

    private function makeComment(string $body): Comment
    {
        return Comment::query()->create([
            'author_name' => 'Navigation Tester',
            'author_email' => 'navigation@example.test',
            'locale' => 'en',
            'body' => $body,
            'rating' => 4,
            'status' => Comment::STATUS_PENDING,
            'is_featured' => false,
            'payload' => null,
        ]);
    }

    private function makeSpecification(string $source): MsanSpecificationDefinition
    {
        return MsanSpecificationDefinition::query()->create([
            'source_key' => hash('sha256', $source),
            'group_name' => 'Technical data',
            'item_name' => str($source)->headline()->toString(),
            'measure' => 'mm',
            'source_for_filter' => true,
            'import_enabled' => true,
            'use_as_filter' => false,
            'data_role' => MsanSpecificationDefinition::ROLE_SPECIFICATION,
            'sample_values' => ['100'],
            'product_count' => 1,
            'last_seen_at' => now(),
            'is_stale' => false,
        ]);
    }
}
