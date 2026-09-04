<?php

namespace Tests\Feature\Admin;

use App\Models\Catalog\Category\Category;
use App\Models\Catalog\Product\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Silber\Bouncer\BouncerFacade as Bouncer;
use Tests\TestCase;

class AdminAiToolTest extends TestCase
{
    use RefreshDatabase;

    public function test_ai_preview_returns_a_plan_for_category_command(): void
    {
        $user = $this->makeAdminUser();

        $response = $this->actingAs($user)->postJson('/admin/ai/preview', [
            'prompt' => 'Napravi mi kategoriju Ugljikohidrati unutar Prehrane, dodaj opis i dodaj danas dodane artikle u kategoriju.',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('domain_key', 'category_management')
            ->assertJsonStructure([
                'plan_id',
                'summary',
                'actions',
                'warnings',
                'domain_key',
                'domain_title',
                'function_steps',
                'can_execute',
            ]);

        $this->assertNotEmpty((array) $response->json('actions'));
        $this->assertNotEmpty((array) $response->json('function_steps'));
        $this->assertTrue((bool) $response->json('can_execute'));
    }

    public function test_ai_execute_creates_category_path_and_attaches_only_today_products(): void
    {
        $user = $this->makeAdminUser();

        $todayProduct = Product::query()->create([
            'code' => 'ai-test-today',
            'sku' => 'AI-TODAY-1',
            'is_active' => true,
            'base_price' => 10.00,
            'stock_qty' => 3,
            'payload' => null,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);

        $oldProduct = Product::query()->create([
            'code' => 'ai-test-old',
            'sku' => 'AI-OLD-1',
            'is_active' => true,
            'base_price' => 5.00,
            'stock_qty' => 2,
            'payload' => null,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);
        $oldProduct->forceFill([
            'created_at' => now()->subDay(),
            'updated_at' => now()->subDay(),
        ])->save();

        $preview = $this->actingAs($user)->postJson('/admin/ai/preview', [
            'prompt' => 'Napravi mi kategoriju Ugljikohidrati unutar Prehrane, dodaj opis i dodaj danas dodane artikle u kategoriju.',
        ])->assertOk();

        $planId = (string) $preview->json('plan_id');
        $this->assertNotSame('', $planId);

        $execute = $this->actingAs($user)->postJson('/admin/ai/execute', [
            'plan_id' => $planId,
        ]);

        $execute
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('attached_products', 1)
            ->assertJsonPath('category_name', 'Ugljikohidrati');

        $scope = Category::SCOPE_CATALOG;
        $locale = (string) config('app.locale', 'en');

        $parent = Category::query()
            ->where('scope', $scope)
            ->whereHas('translations', fn ($q) => $q
                ->where('scope', $scope)
                ->where('locale', $locale)
                ->where('name', 'Prehrane'))
            ->first();

        $child = Category::query()
            ->where('scope', $scope)
            ->whereHas('translations', fn ($q) => $q
                ->where('scope', $scope)
                ->where('locale', $locale)
                ->where('name', 'Ugljikohidrati'))
            ->first();

        $this->assertNotNull($parent);
        $this->assertNotNull($child);
        $this->assertSame($parent->id, $child->parent_id);

        $childTranslation = $child->translations()
            ->where('scope', $scope)
            ->where('locale', $locale)
            ->first();

        $this->assertNotNull($childTranslation);
        $this->assertSame('Automatski opis kategorije Ugljikohidrati.', (string) $childTranslation->description);

        $this->assertTrue($child->products()->whereKey($todayProduct->id)->exists());
        $this->assertFalse($child->products()->whereKey($oldProduct->id)->exists());
    }

    private function makeAdminUser(): User
    {
        $user = User::factory()->create();

        Bouncer::role()->firstOrCreate(['name' => 'admin']);
        Bouncer::assign('admin')->to($user);

        return $user;
    }
}
