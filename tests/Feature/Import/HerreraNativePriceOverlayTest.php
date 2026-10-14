<?php

namespace Tests\Feature\Import;

use App\Models\Catalog\Category\Category;
use App\Models\Catalog\Pricing\PriceCatalog;
use App\Models\Catalog\Pricing\PriceCatalogEntry;
use App\Models\Catalog\Product\Product;
use App\Models\User;
use App\Models\User\CustomerGroup;
use App\Services\Import\HerreraNativePriceOverlayService;
use App\Services\Pricing\CatalogGroupDiscountService;
use App\Services\Pricing\PriceCatalogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Silber\Bouncer\BouncerFacade as Bouncer;
use Tests\TestCase;

class HerreraNativePriceOverlayTest extends TestCase
{
    use RefreshDatabase;

    public function test_live_source_price_collisions_require_reconciliation_instead_of_silently_applying_native_prices_or_rules(): void
    {
        [$actor, $active, $source, $product, $group] = $this->fixture();
        app(PriceCatalogService::class)->saveEntry($active, $this->data($product, $group, ['price' => '77.1234']), $actor);
        app(CatalogGroupDiscountService::class)->saveRule($active, ['name' => 'Native category discount', 'percent' => '10', 'customer_group_ids' => [$group->id]], $actor);
        $this->imported($active, $product, $group, 'source-group', '95.4321', sourcePrice: '100.0000');
        $this->imported($source, $product, $group, 'source-group', '120.0000', sourcePrice: '120.0000');
        $this->publish($active);
        $this->complete($source);
        $activeBefore = $active->entries()->orderBy('id')->get()->toArray();
        $sourceBefore = $source->entries()->orderBy('id')->get()->toArray();
        $audit = $this->proof($source);
        $service = app(HerreraNativePriceOverlayService::class);

        $plan = $service->plan($source, $actor, $audit);
        $this->assertFalse($plan['ready']);
        $this->assertSame(2, $plan['native_entry_count']);
        $this->assertSame(1, $plan['native_override_count']);
        $this->assertSame(1, $plan['native_rule_count']);
        $this->assertSame(3, $plan['reconciliation_required_count']);
        $this->assertContains('native_rule_changes_live_source_prices_requires_reconciliation', array_column($plan['conflicts'], 'code'));
        $this->assertContains('native_entry_may_change_live_source_prices_requires_reconciliation', array_column($plan['conflicts'], 'code'));
        $this->assertContains('native_override_changes_live_source_prices_requires_reconciliation', array_column($plan['conflicts'], 'code'));
        try {
            $service->prepare($source, $actor, $audit);
            $this->fail('Live price conflicts must block the working copy.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('native_overlay', $exception->errors());
        }
        $this->assertSame(2, PriceCatalog::query()->count());
        $this->assertSame($active->id, DB::table('catalog_price_catalog_state')->where('id', 1)->value('price_catalog_id'));
        $this->assertSame($activeBefore, $active->entries()->orderBy('id')->get()->toArray());
        $this->assertSame($sourceBefore, $source->entries()->orderBy('id')->get()->toArray());
    }

    public function test_disjoint_native_prices_and_scoped_rules_are_carried_into_an_inactive_copy_without_changing_either_input(): void
    {
        [$actor, $active, $source, , $group] = $this->fixture();
        $product = Product::query()->create(['code' => 'NATIVE', 'base_price' => 50, 'is_active' => true]);
        $category = Category::query()->create(['code' => 'native-only-category', 'scope' => Category::SCOPE_CATALOG, 'is_active' => true]);
        $product->categories()->attach($category);
        $manual = app(PriceCatalogService::class)->saveEntry($active, $this->data($product, $group, ['price' => '77.1234']), $actor);
        app(CatalogGroupDiscountService::class)->saveRule($active, ['name' => 'Native category discount', 'percent' => '10', 'customer_group_ids' => [$group->id], 'category_ids' => [$category->id]], $actor);
        $this->publish($active);
        $this->complete($source);
        $activeBefore = $active->entries()->orderBy('id')->get()->toArray();
        $sourceBefore = $source->entries()->orderBy('id')->get()->toArray();
        $audit = $this->proof($source);
        $service = app(HerreraNativePriceOverlayService::class);

        $plan = $service->plan($source, $actor, $audit);
        $this->assertTrue($plan['ready'], json_encode($plan['conflicts']));
        $result = $service->prepare($source, $actor, $audit);
        $working = PriceCatalog::query()->findOrFail($result['draft_catalog_id']);

        $this->assertSame('draft', $working->status);
        $this->assertSame(1, $plan['native_entry_count']);
        $this->assertSame(1, $plan['native_rule_count']);
        $this->assertSame(0, $plan['reconciliation_required_count']);
        $this->assertSame($active->id, DB::table('catalog_price_catalog_state')->where('id', 1)->value('price_catalog_id'));
        $this->assertSame($activeBefore, $active->entries()->orderBy('id')->get()->toArray());
        $this->assertSame($sourceBefore, $source->entries()->orderBy('id')->get()->toArray());
        $this->assertSame('77.1234', $working->entries()->where('source_key', $manual->source_key)->sole()->price);
        $this->assertSame('10.0000', $working->discountRules()->sole()->percent);
        $this->assertSame('45.0000', $working->entries()->whereNotNull('discount_rule_id')->sole()->price);
        $this->assertSame($audit, $working->metadata['native_overlay']['source_audit']);
        $this->assertDatabaseHas('catalog_price_catalog_audits', ['price_catalog_id' => $working->id, 'event' => 'native_overlay_prepared', 'actor_id' => $actor->id]);
    }

    public function test_inactive_native_rules_and_entries_can_be_carried_without_changing_live_prices(): void
    {
        [$actor, $active, $source, $product, $group] = $this->fixture();
        app(PriceCatalogService::class)->saveEntry($active, $this->data($product, $group, ['is_active' => false]), $actor);
        app(CatalogGroupDiscountService::class)->saveRule($active, ['name' => 'Inactive discount', 'percent' => '10', 'customer_group_ids' => [$group->id], 'is_active' => false], $actor);
        $this->publish($active);
        $this->complete($source);
        $service = app(HerreraNativePriceOverlayService::class);

        $plan = $service->plan($source, $actor, $this->proof($source));
        $this->assertTrue($plan['ready'], json_encode($plan['conflicts']));
        $result = $service->prepare($source, $actor, $this->proof($source));
        $working = PriceCatalog::query()->findOrFail($result['draft_catalog_id']);
        $this->assertFalse($working->discountRules()->sole()->is_active);
        $this->assertSame(1, $working->entries()->where('is_active', true)->count());
    }

    public function test_native_imported_scope_edits_unknown_ownership_and_deleted_source_overrides_block_without_creating_a_copy(): void
    {
        [$actor, $active, $source, $product, $group] = $this->fixture();
        $otherGroup = CustomerGroup::query()->create(['code' => 'other', 'name' => 'Other', 'is_active' => true]);
        $entry = $this->imported($active, $product, $group, 'source-group', '95.0000', sourcePrice: '100.0000');
        $entry->update(['customer_group_id' => $otherGroup->id]);
        $this->imported($source, $product, $group, 'source-group', '120.0000', sourcePrice: '120.0000');
        $active->entries()->create($this->data($product, $group) + ['source_key' => 'unverified-ownership']);
        $this->publish($active);
        $this->complete($source);
        $service = app(HerreraNativePriceOverlayService::class);
        $plan = $service->plan($source, $actor, $this->proof($source));
        $this->assertFalse($plan['ready']);
        $this->assertContains('native_override_source_scope_changed', array_column($plan['conflicts'], 'code'));
        $this->assertContains('entry_ownership_unverifiable', array_column($plan['conflicts'], 'code'));
        $before = PriceCatalog::query()->count();
        try {
            $service->prepare($source, $actor, $this->proof($source));
            $this->fail('Conflicts must block creating the overlay.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('native_overlay', $exception->errors());
        }
        $this->assertSame($before, PriceCatalog::query()->count());
    }

    public function test_source_audit_proof_is_bound_to_catalog_checksum_and_count(): void
    {
        [$actor, $active, $source] = $this->fixture();
        $this->publish($active);
        $this->complete($source);
        $proof = $this->proof($source);
        $proof['source_checksum'] = 'different-source';
        $plan = app(HerreraNativePriceOverlayService::class)->plan($source, $actor, $proof);
        $this->assertFalse($plan['ready']);
        $this->assertContains('source_audit_proof_mismatch', array_column($plan['conflicts'], 'code'));
        $this->assertSame(2, PriceCatalog::query()->count());
    }

    public function test_previous_native_deletion_of_an_imported_entry_is_not_silently_resurrected(): void
    {
        [$actor, $active, $source, $product, $group] = $this->fixture();
        $entry = $this->imported($active, $product, $group, 'source-group', '100.0000', sourcePrice: '100.0000');
        app(PriceCatalogService::class)->deleteEntry($active, $entry->id, $actor);
        $this->imported($source, $product, $group, 'source-group', '120.0000', sourcePrice: '120.0000');
        $this->publish($active);
        $this->complete($source);
        $plan = app(HerreraNativePriceOverlayService::class)->plan($source, $actor, $this->proof($source));
        $this->assertFalse($plan['ready']);
        $this->assertContains('native_source_entry_deletion_requires_review', array_column($plan['conflicts'], 'code'));
    }

    public function test_an_edited_source_draft_cannot_reuse_an_earlier_checksum_and_count_proof(): void
    {
        [$actor, $active, $source] = $this->fixture();
        $this->publish($active);
        $this->complete($source);
        $proof = $this->proof($source);
        $source->entries()->sole()->update(['price' => '1.0000']);

        $plan = app(HerreraNativePriceOverlayService::class)->plan($source, $actor, $proof);
        $this->assertFalse($plan['ready']);
        $this->assertContains('source_draft_contains_unverified_or_edited_entry', array_column($plan['conflicts'], 'code'));
        $this->assertSame(2, PriceCatalog::query()->count());
    }

    public function test_pricing_authority_is_required_for_overlay_preparation(): void
    {
        [, , $source] = $this->fixture();
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        app(HerreraNativePriceOverlayService::class)->plan($source, User::factory()->create(), []);
    }

    private function fixture(): array
    {
        $actor = User::factory()->create();
        Bouncer::assign('superadmin')->to($actor);
        $group = CustomerGroup::query()->create(['code' => 'partners', 'name' => 'Partners', 'is_active' => true]);
        $product = Product::query()->create(['code' => 'OVERLAY', 'base_price' => 120, 'is_active' => true]);
        foreach (['product' => [30, $product->id], 'customer_group' => [4, $group->id]] as $entity => [$sourceId, $targetId]) {
            DB::table('herrera_import_maps')->insert(['source' => 'herrera-opencart', 'entity' => $entity, 'source_id' => (string) $sourceId, 'target_id' => $targetId, 'checksum' => 'fixture', 'created_at' => now(), 'updated_at' => now()]);
        }
        $active = app(PriceCatalogService::class)->createDraft('Existing native setup', $actor);
        $source = PriceCatalog::query()->create(['name' => 'Fresh source-only draft', 'status' => 'draft', 'currency_code' => 'EUR', 'source_system' => 'herrera-opencart', 'source_snapshot' => 'fresh-final-snapshot', 'source_checksum' => str_repeat('a', 64)]);
        $this->imported($active, $product, $group, 'base', '100.0000', sourcePrice: '100.0000', kind: 'base');
        $this->imported($source, $product, $group, 'base', '120.0000', sourcePrice: '120.0000', kind: 'base');

        return [$actor, $active, $source, $product, $group];
    }

    private function imported(PriceCatalog $catalog, Product $product, CustomerGroup $group, string $key, string $price, string $sourcePrice, string $kind = 'group'): PriceCatalogEntry
    {
        return $catalog->entries()->create($this->data($product, $group, ['kind' => $kind, 'price' => $price, 'customer_group_id' => $kind === 'base' ? null : $group->id])
            + ['source_key' => ($kind === 'base' ? 'product:' : 'product_price_by_cigroup:').$key, 'payload' => ['opencart' => ['product_id' => 30, 'group_id' => 4, 'price' => $sourcePrice]]]);
    }

    private function data(Product $product, CustomerGroup $group, array $extra = []): array
    {
        return $extra + ['product_id' => $product->id, 'kind' => 'group', 'customer_group_id' => $group->id, 'user_id' => null, 'minimum_quantity' => 1, 'price' => '77.0000', 'priority' => 0, 'starts_at' => null, 'ends_at' => null, 'is_active' => true];
    }

    private function publish(PriceCatalog $catalog): void
    {
        $catalog->update(['status' => 'active', 'activated_at' => now()]);
        DB::table('catalog_price_catalog_state')->where('id', 1)->update(['price_catalog_id' => $catalog->id]);
    }

    private function complete(PriceCatalog $source): void
    {
        $source->update(['metadata' => ['import_complete' => true, 'import_expected_entries' => $source->entries()->count()]]);
    }

    private function proof(PriceCatalog $source): array
    {
        return ['passed' => true, 'catalog_id' => $source->id, 'source_checksum' => $source->source_checksum, 'entry_count' => $source->entries()->count()];
    }
}
