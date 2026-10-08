<?php

namespace Tests\Feature\Admin;

use App\Http\Middleware\EnsureAdminAbility;
use App\Livewire\Admin\Settings\Local\ResourceManager;
use App\Models\Settings\Local\Currency;
use App\Models\Settings\Local\GeoZone;
use App\Models\Settings\Local\Language;
use App\Models\User;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Silber\Bouncer\BouncerFacade as Bouncer;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class LocalSettingsAccessFeatureTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('editableResources')]
    public function test_granular_editors_can_manage_only_their_local_resource(string $resource, string $ability, string $model): void
    {
        $operator = $this->operator([$ability]);
        $this->actingAs($operator)->get(route('admin.settings.local.resource', compact('resource')))->assertOk();
        $this->get(route('admin.settings.local.resource.create', compact('resource')))->assertOk();

        $component = Livewire::actingAs($operator)->test(ResourceManager::class, compact('resource'))
            ->set('form.code', 'access-test')
            ->set('form.name', 'Access test')
            ->set('form.locale', 'hr')
            ->set('form.is_active', true)
            ->call('save')->assertHasNoErrors();
        $record = $model::query()->where('code', 'access-test')->firstOrFail();
        $this->get(route('admin.settings.local.resource.edit', ['resource' => $resource, 'record' => $record->id]))->assertOk();
        $component->call('edit', $record->id)->set('form.name', 'Updated access test')->call('save')->assertHasNoErrors();
        $this->assertSame('Updated access test', $record->fresh()->name);
        $component->call('toggleActive', $record->id)->assertSuccessful();
        $this->assertFalse($record->fresh()->is_active);
        $component->call('makeDefault', $record->id)->assertSuccessful();
        $this->assertTrue($record->fresh()->is_default);
        $component->call('delete', $record->id)->assertSuccessful();
        $this->assertModelMissing($record);

        foreach (array_diff(['currencies', 'languages', 'geo-zones', 'payment-methods', 'tax-rates', 'order-statuses'], [$resource]) as $otherResource) {
            $this->get(route('admin.settings.local.resource', ['resource' => $otherResource]))->assertForbidden();
            Livewire::actingAs($operator)->test(ResourceManager::class, ['resource' => $otherResource])->assertForbidden();
        }
        $this->get(route('admin.shipping.index'))->assertForbidden();
        $this->get(route('admin.settings.api.wholesale'))->assertForbidden();
    }

    public static function editableResources(): array
    {
        return [
            'currencies' => ['currencies', 'settings.currencies.manage', Currency::class],
            'languages' => ['languages', 'settings.languages.manage', Language::class],
        ];
    }

    public function test_geographic_viewers_can_search_but_cannot_open_forms_or_call_any_mutator(): void
    {
        $operator = $this->operator(['settings.regions.view']);
        $zone = GeoZone::query()->create(['code' => 'readonly-zone', 'name' => 'Readonly zone', 'is_active' => true]);
        foreach (['geo-zones', 'geo-zone-countries', 'regions'] as $resource) {
            $this->actingAs($operator)->get(route('admin.settings.local.resource', compact('resource')))->assertOk()
                ->assertDontSee('wire:click="toggleActive(', false)
                ->assertDontSee('wire:click="delete(', false)
                ->assertDontSee(route('admin.settings.local.resource.create', compact('resource')), false);
            $this->get(route('admin.settings.local.resource.create', compact('resource')))->assertForbidden();
            $this->get(route('admin.settings.local.resource.edit', ['resource' => $resource, 'record' => $zone->id]))->assertForbidden();
        }

        Livewire::actingAs($operator)->test(ResourceManager::class, ['resource' => 'geo-zones'])
            ->set('search', 'Readonly')->assertSee('Readonly zone');
        foreach (['save', 'edit', 'delete', 'toggleActive', 'makeDefault'] as $method) {
            Livewire::actingAs($operator)->test(ResourceManager::class, ['resource' => 'geo-zones'])
                ->call($method, ...($method === 'save' ? [] : [$zone->id]))->assertForbidden();
        }
        $this->assertSame('Readonly zone', $zone->fresh()->name);
        $this->assertTrue($zone->fresh()->is_active);
    }

    public function test_resource_identity_cannot_be_changed_in_livewire_updates(): void
    {
        $operator = $this->operator(['settings.currencies.manage']);
        $this->expectException(CannotUpdateLockedPropertyException::class);
        Livewire::actingAs($operator)->test(ResourceManager::class, ['resource' => 'currencies'])
            ->set('resource', 'payment-methods');
    }

    #[DataProvider('snapshotAccess')]
    public function test_persistent_middleware_uses_the_origin_resource_for_livewire_authorization(string $ability, string $resource, ?string $method, int $status): void
    {
        $operator = $this->operator([$ability]);
        $request = Request::create(route('admin.settings.local.resource', compact('resource')), 'GET', [
            'components' => [[
                'snapshot' => json_encode(['memo' => ['path' => 'admin/settings/local/'.$resource, 'method' => 'GET']], JSON_THROW_ON_ERROR),
                'calls' => $method === null ? [] : [['method' => $method]],
            ]],
        ]);
        $route = app('router')->getRoutes()->match($request);
        $request->setRouteResolver(fn () => $route);
        $request->setUserResolver(fn () => $operator);
        try {
            $response = app(EnsureAdminAbility::class)->handle($request, fn () => response('Allowed'));
            $this->assertSame($status, $response->getStatusCode());
        } catch (HttpException $exception) {
            $this->assertSame($status, $exception->getStatusCode());
        }
    }

    public static function snapshotAccess(): array
    {
        return [
            'currency write' => ['settings.currencies.manage', 'currencies', 'save', 200],
            'language write' => ['settings.languages.manage', 'languages', 'delete', 200],
            'geographic read' => ['settings.regions.view', 'geo-zones', null, 200],
            'geographic search' => ['settings.regions.view', 'regions', 'updatedSearch', 200],
            'geographic save denied' => ['settings.regions.view', 'geo-zones', 'save', 403],
            'geographic delete denied' => ['settings.regions.view', 'geo-zone-countries', 'delete', 403],
            'payment write denied' => ['settings.currencies.manage', 'payment-methods', 'save', 403],
        ];
    }

    public function test_sidebar_shows_only_accessible_links_and_hides_empty_groups(): void
    {
        $operator = $this->operator(['sales.orders.view', 'users.list.view', 'settings.currencies.manage', 'settings.languages.manage', 'settings.regions.view']);
        $html = $this->actingAs($operator)->get(route('admin.settings.local.resource', ['resource' => 'currencies']))->assertOk()->getContent();
        $xpath = $this->xpath($html);
        foreach (['currencies', 'languages', 'geo-zones', 'geo-zone-countries', 'regions'] as $resource) {
            $this->assertSame(1, $xpath->query('//*[@id="admin-sidebar"]//nav//a[@href="'.route('admin.settings.local.resource', compact('resource')).'"]')->count());
        }
        foreach (['admin.dashboard', 'admin.categories', 'admin.products', 'admin.content.pages.index', 'admin.content.blocks', 'admin.shipping.index', 'admin.settings.system.admin-appearance-controls', 'admin.settings.api.wholesale', 'admin.users.groups', 'admin.users.access', 'admin.settings.user.index'] as $route) {
            $this->assertSame(0, $xpath->query('//*[@id="admin-sidebar"]//nav//a[@href="'.route($route).'"]')->count(), $route);
        }
        foreach (['catalog', 'content', 'system'] as $group) {
            $this->assertSame(0, $xpath->query('//*[@id="admin-sidebar"]//nav//summary//span[normalize-space()="'.__('admin.layout.menu.'.$group).'"]')->count(), $group);
        }
        $this->assertSame(1, $xpath->query('//*[@id="admin-sidebar"]//nav//a[@href="'.route('admin.orders').'"]')->count());
        $this->assertSame(1, $xpath->query('//*[@id="admin-sidebar"]//nav//a[@href="'.route('admin.users').'"]')->count());
    }

    public function test_existing_local_settings_managers_keep_full_access(): void
    {
        $operator = $this->operator(['settings.local.manage']);
        foreach (['currencies', 'languages', 'geo-zones', 'geo-zone-countries', 'regions', 'payment-methods', 'tax-rates', 'order-statuses'] as $resource) {
            $this->actingAs($operator)->get(route('admin.settings.local.resource', compact('resource')))->assertOk();
            $this->get(route('admin.settings.local.resource.create', compact('resource')))->assertOk();
        }
        $zone = GeoZone::query()->create(['code' => 'managed-zone', 'name' => 'Managed zone', 'is_active' => true]);
        Livewire::actingAs($operator)->test(ResourceManager::class, ['resource' => 'geo-zones'])->call('toggleActive', $zone->id)->assertSuccessful();
        $this->assertFalse($zone->fresh()->is_active);
    }

    public function test_staff_preview_banner_names_the_current_operator_and_provides_a_csrf_post_return(): void
    {
        if (! Route::has('admin.staff-impersonation.stop')) {
            Route::post('admin/staff-impersonation/stop', fn () => response())->name('admin.staff-impersonation.stop');
            Route::getRoutes()->refreshNameLookups();
        }
        $operator = $this->operator(['settings.currencies.manage']);
        $admin = User::factory()->create();
        $html = $this->actingAs($operator)->withSession(['admin.staff_impersonation' => [
            'admin_id' => $admin->id,
            'staff_id' => $operator->id,
            'started_at' => now()->toIso8601String(),
        ]])->get(route('admin.settings.local.resource', ['resource' => 'currencies']))->assertOk()
            ->assertSeeText('Pregled kao '.$operator->name)->assertSeeText('Vrati se u svoj admin')->getContent();
        $xpath = $this->xpath($html);
        $form = $xpath->query('//*[@data-staff-impersonation-banner]//form[@method="POST" and @action="'.route('admin.staff-impersonation.stop').'"]');
        $this->assertSame(1, $form->count());
        $this->assertSame(1, $xpath->query('.//input[@name="_token"]', $form->item(0))->count());
    }

    private function operator(array $abilities): User
    {
        $operator = User::factory()->create();
        Bouncer::allow($operator)->to(['admin.access', ...$abilities]);
        Bouncer::refreshFor($operator);

        return $operator;
    }

    private function xpath(string $html): DOMXPath
    {
        $dom = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">'.$html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return new DOMXPath($dom);
    }
}
