<?php

namespace Tests\Feature\Admin;

use App\Livewire\Admin\User\GroupManager;
use App\Models\User;
use App\Models\User\CustomerGroup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Silber\Bouncer\BouncerFacade as Bouncer;
use Tests\TestCase;

class PrimaryGroupDefaultEditorTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('validDefaultDiscounts')]
    public function test_superadmin_can_edit_clear_and_normalize_the_group_default_discount(string $input, ?string $stored): void
    {
        $admin = $this->superadmin();
        $group = $this->group();

        Livewire::actingAs($admin)->test(GroupManager::class, ['recordId' => $group->id, 'editPage' => true])
            ->assertSet('form.default_discount_percent', '50.0000')
            ->assertSee('Osnovni popust grupe (%)')
            ->assertViewHas('canManagePricing', true)
            ->set('form.default_discount_percent', $input)
            ->call('save')->assertHasNoErrors()->assertRedirect(route('admin.users.groups'));

        $this->assertSame($stored, $group->fresh()->default_discount_percent);
        $this->assertSame('B2B50', $group->fresh()->name);
        Livewire::actingAs($admin)->test(GroupManager::class, ['recordId' => $group->id, 'editPage' => true])
            ->assertSet('form.default_discount_percent', $stored ?? '');
    }

    public static function validDefaultDiscounts(): array
    {
        return [
            'change percentage' => ['55', '55.0000'],
            'clear percentage' => ['', null],
            'normalize decimal comma' => ['52,1250', '52.1250'],
        ];
    }

    public function test_group_manager_without_pricing_permission_can_rename_group_while_retaining_its_discount(): void
    {
        $manager = $this->groupManager();
        $group = $this->group();

        Livewire::actingAs($manager)->test(GroupManager::class, ['recordId' => $group->id, 'editPage' => true])
            ->assertSet('form.default_discount_percent', '50.0000')
            ->assertViewHas('canManagePricing', false)
            ->set('form.name', 'B2B partneri')
            ->call('save')->assertHasNoErrors()->assertRedirect(route('admin.users.groups'));

        $this->assertSame('B2B partneri', $group->fresh()->name);
        $this->assertSame('50.0000', $group->fresh()->default_discount_percent);
    }

    #[DataProvider('insufficientPricingPermissions')]
    public function test_forged_default_discount_update_requires_both_admin_access_and_pricing_update(bool $adminAccess, bool $pricingUpdate): void
    {
        $manager = $this->groupManager();
        $group = $this->group();
        if ($adminAccess) {
            Bouncer::allow($manager)->to('admin.access');
        }
        if ($pricingUpdate) {
            Bouncer::allow($manager)->to('catalog.b2b_prices.update');
        }

        Livewire::actingAs($manager)->test(GroupManager::class, ['recordId' => $group->id, 'editPage' => true])
            ->assertViewHas('canManagePricing', false)
            ->set('form.name', 'Forged accompanying change')
            ->set('form.default_discount_percent', '90')
            ->call('save')->assertForbidden();

        $this->assertSame('50.0000', $group->fresh()->default_discount_percent);
        $this->assertSame('B2B50', $group->fresh()->name);
    }

    public static function insufficientPricingPermissions(): array
    {
        return [
            'group permission only' => [false, false],
            'missing pricing update' => [true, false],
            'missing admin access' => [false, true],
        ];
    }

    public function test_group_manager_with_both_pricing_permissions_can_update_the_default(): void
    {
        $manager = $this->groupManager();
        Bouncer::allow($manager)->to('admin.access');
        Bouncer::allow($manager)->to('catalog.b2b_prices.update');
        $group = $this->group();

        Livewire::actingAs($manager)->test(GroupManager::class, ['recordId' => $group->id, 'editPage' => true])
            ->assertViewHas('canManagePricing', true)
            ->set('form.default_discount_percent', '55')->call('save')->assertHasNoErrors();

        $this->assertSame('55.0000', $group->fresh()->default_discount_percent);
    }

    #[DataProvider('invalidDefaultDiscounts')]
    public function test_invalid_default_discount_is_rejected_without_changing_the_group(string $percent): void
    {
        $group = $this->group();
        Livewire::actingAs($this->superadmin())->test(GroupManager::class, ['recordId' => $group->id, 'editPage' => true])
            ->set('form.default_discount_percent', $percent)
            ->call('save')->assertHasErrors('form.default_discount_percent');

        $this->assertSame('50.0000', $group->fresh()->default_discount_percent);
    }

    public static function invalidDefaultDiscounts(): array
    {
        return [
            'above one hundred' => ['100.0001'],
            'negative' => ['-1'],
            'too many decimal places' => ['52.12345'],
            'not numeric' => ['discount'],
        ];
    }

    #[DataProvider('businessSegmentClassifications')]
    public function test_business_segments_cannot_be_assigned_a_pricing_default(string $classification): void
    {
        $group = $this->group(null);
        Livewire::actingAs($this->superadmin())->test(GroupManager::class, ['recordId' => $group->id, 'editPage' => true])
            ->set('form.classification_type', $classification)
            ->set('form.default_discount_percent', '60')
            ->call('save')->assertHasErrors('form.default_discount_percent');

        $this->assertNull($group->fresh()->default_discount_percent);
        $this->assertSame(CustomerGroup::CLASSIFICATION_GENERAL, $group->fresh()->classificationType());
    }

    public static function businessSegmentClassifications(): array
    {
        return [[CustomerGroup::CLASSIFICATION_INDUSTRY], [CustomerGroup::CLASSIFICATION_DIVISION]];
    }

    public function test_group_can_be_created_without_a_default_discount_by_a_group_manager(): void
    {
        Livewire::actingAs($this->groupManager())->test(GroupManager::class, ['createPage' => true])
            ->assertSet('form.default_discount_percent', '')
            ->set('form.code', 'new-b2b')->set('form.name', 'Nova cjenovna grupa')
            ->call('save')->assertHasNoErrors()->assertRedirect(route('admin.users.groups'));

        $group = CustomerGroup::query()->where('code', 'new-b2b')->sole();
        $this->assertNull($group->default_discount_percent);
        $this->assertSame(CustomerGroup::CLASSIFICATION_GENERAL, $group->classificationType());
    }

    private function superadmin(): User
    {
        $admin = User::factory()->create();
        Bouncer::assign('superadmin')->to($admin);

        return $admin;
    }

    private function groupManager(): User
    {
        $manager = User::factory()->create();
        Bouncer::allow($manager)->to('users.groups.manage');

        return $manager;
    }

    private function group(?string $percent = '50'): CustomerGroup
    {
        return CustomerGroup::query()->create([
            'code' => 'b2b50', 'name' => 'B2B50', 'default_discount_percent' => $percent,
            'is_active' => true, 'is_default' => false, 'sort_order' => 0,
        ]);
    }
}
