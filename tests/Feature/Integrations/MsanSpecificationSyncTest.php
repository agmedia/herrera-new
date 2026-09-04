<?php

namespace Tests\Feature\Integrations;

use App\Jobs\Integrations\Msan\RepublishMsanSpecificationDefinitionJob;
use App\Jobs\Integrations\Msan\SyncMsanSpecificationsJob;
use App\Models\Catalog\Attribute\Attribute;
use App\Models\Catalog\Product\Product;
use App\Models\Catalog\Product\ProductEnergyDeclaration;
use App\Models\Integrations\Msan\MsanCategory;
use App\Models\Integrations\Msan\MsanCategoryMapping;
use App\Models\Integrations\Msan\MsanProduct;
use App\Models\Integrations\Msan\MsanSpecificationDefinition;
use App\Models\Integrations\Msan\MsanSpecificationSnapshot;
use App\Models\Integrations\Msan\MsanSyncRun;
use App\Services\Integrations\Msan\MsanCatalogSyncCoordinator;
use App\Services\Integrations\Msan\MsanClient;
use App\Services\Integrations\Msan\MsanSpecificationPublisher;
use App\Services\Integrations\Msan\MsanSpecificationSyncService;
use App\Services\Integrations\Msan\MsanSpecificationValuesParser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

class MsanSpecificationSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_selected_product_specifications_are_streamed_published_and_replayable(): void
    {
        [$product, $source] = $this->linkedProduct('MSAN-SPEC-1');
        $client = $this->fixtureClient($this->xml([
            $this->row('MSAN-SPEC-1', 'Osnovno', 10, 'Boja', ['Crna', 'Srebrna']),
            $this->row('MSAN-SPEC-1', 'Dimenzije', 20, 'Širina', ['59.5'], 'cm', true),
            $this->row('IGNORED-1', 'Osnovno', 10, 'Boja', ['Bijela']),
        ]));
        $run = $this->syncRun();
        $service = $this->service($client);

        $service->sync($run);

        $this->assertSame(MsanSyncRun::STATUS_COMPLETED, $run->fresh()->status);
        $this->assertSame(2, $product->technicalSpecificationRows()->count());
        $this->assertDatabaseHas('catalog_product_specifications', [
            'product_id' => $product->id,
            'group_name' => 'Osnovno',
            'item_name' => 'Boja',
            'measure' => null,
        ]);
        $this->assertSame(['Crna', 'Srebrna'], $product->technicalSpecificationRows()
            ->where('item_name', 'Boja')
            ->firstOrFail()
            ->values);
        $this->assertSame(2, MsanSpecificationDefinition::query()->count());
        $this->assertSame(1, MsanSpecificationSnapshot::query()
            ->where('status', MsanSpecificationSnapshot::STATUS_ACTIVE)
            ->count());

        // Once activation succeeds, a queue retry republishes the same local
        // snapshot and does not spend the supplier's one-hour endpoint window.
        $run->forceFill(['status' => MsanSyncRun::STATUS_RUNNING])->save();
        $service->sync($run);
        $this->assertSame(1, $client->downloads);
        $this->assertSame(2, $source->fresh()->specifications()->count());
    }

    public function test_admin_opt_in_creates_filter_values_without_replacing_manual_attributes(): void
    {
        [$product] = $this->linkedProduct('MSAN-FILTER-1');
        $manual = Attribute::query()->create([
            'code' => 'manual-red',
            'group_code' => 'manual-color',
            'type' => Attribute::TYPE_SELECT,
            'is_active' => true,
            'sort_order' => 0,
            'payload' => ['source' => 'manual'],
        ]);
        $product->attributes()->attach($manual->id, ['sort_order' => 0]);
        $service = $this->service($this->fixtureClient($this->xml([
            $this->row('MSAN-FILTER-1', 'Osnovno', 10, 'Boja kućišta', ['Crna']),
        ])));
        $run = $this->syncRun();
        $service->sync($run);

        $definition = MsanSpecificationDefinition::query()->firstOrFail();
        $definition->forceFill([
            'use_as_filter' => true,
            'display_group_name' => 'Izgled',
            'display_item_name' => 'Boja uređaja',
            'display_measure' => 'oznaka',
        ])->save();
        (new RepublishMsanSpecificationDefinitionJob((int) $definition->id))
            ->handle(app(MsanSpecificationPublisher::class));

        $product->refresh();
        $this->assertTrue($product->attributes()->whereKey($manual->id)->exists());
        $msanAttribute = $product->attributes()
            ->where('payload->source', 'msan_specification')
            ->firstOrFail();
        $this->assertNotNull($msanAttribute->attribute_group_id);
        $this->assertDatabaseHas('catalog_attribute_groups', [
            'id' => $msanAttribute->attribute_group_id,
            'code' => $msanAttribute->group_code,
        ]);
        $this->assertDatabaseHas('catalog_attribute_group_translations', [
            'attribute_group_id' => $msanAttribute->attribute_group_id,
            'locale' => 'hr',
            'name' => 'Boja uređaja',
        ]);
        $this->assertDatabaseHas('catalog_product_specifications', [
            'product_id' => $product->id,
            'group_name' => 'Izgled',
            'item_name' => 'Boja uređaja',
            'measure' => 'oznaka',
        ]);

        $msanAttribute->forceFill([
            'payload' => [
                'source' => 'manual',
                'source_key' => 'tampered',
            ],
        ])->save();
        (new RepublishMsanSpecificationDefinitionJob((int) $definition->id))
            ->handle(app(MsanSpecificationPublisher::class));

        $msanAttribute->refresh();
        $this->assertSame(
            Attribute::SOURCE_MSAN_SPECIFICATION,
            data_get($msanAttribute->payload, 'source'),
        );
        $this->assertSame($definition->source_key, data_get($msanAttribute->payload, 'source_key'));
    }

    public function test_coordinator_queues_selected_specifications_on_the_integrations_queue(): void
    {
        Queue::fake();
        $this->linkedProduct('MSAN-QUEUE-SPEC');
        app(\App\Services\Integrations\Msan\MsanSettingsService::class)->saveAdminValues([
            'msan_enabled' => true,
            'msan_import_specifications' => true,
            'msan_specifications_selected_only' => true,
        ]);

        $run = app(MsanCatalogSyncCoordinator::class)->queueSpecifications();

        $this->assertSame(MsanSyncRun::KIND_SPECIFICATIONS, $run->kind);
        $this->assertSame(MsanSyncRun::STATUS_PENDING, $run->status);
        Queue::assertPushed(
            SyncMsanSpecificationsJob::class,
            fn (SyncMsanSpecificationsJob $job): bool => $job->queue === 'integrations',
        );
    }

    public function test_new_snapshot_clears_supplier_specifications_missing_for_a_linked_product(): void
    {
        [$firstProduct] = $this->linkedProduct('MSAN-OLD-SPEC');
        [$secondProduct] = $this->linkedProduct('MSAN-NEW-SPEC');
        $this->service($this->fixtureClient($this->xml([
            $this->row('MSAN-OLD-SPEC', 'Osnovno', 10, 'Boja', ['Crna']),
            $this->row('MSAN-NEW-SPEC', 'Osnovno', 10, 'Boja', ['Bijela']),
        ])))->sync($this->syncRun());
        $this->assertSame(1, $firstProduct->technicalSpecificationRows()->count());

        $this->service($this->fixtureClient($this->xml([
            $this->row('MSAN-NEW-SPEC', 'Osnovno', 10, 'Boja', ['Srebrna']),
        ])))->sync($this->syncRun());

        $this->assertSame(0, $firstProduct->technicalSpecificationRows()->count());
        $this->assertSame(
            ['Srebrna'],
            $secondProduct->technicalSpecificationRows()->firstOrFail()->values,
        );
    }

    public function test_manual_primary_energy_declaration_has_priority_over_msan_detection(): void
    {
        [$product] = $this->linkedProduct('MSAN-ENERGY-1');
        $product->forceFill([
            'energy_label_required' => true,
            'energy_efficiency_class' => 'B',
            'energy_efficiency_scale' => 'A-G',
        ])->save();
        ProductEnergyDeclaration::query()->create([
            'product_id' => $product->id,
            'context_code' => 'manual-primary',
            'label' => 'Grijanje prostora',
            'energy_class' => 'B',
            'scale_min' => 'A',
            'scale_max' => 'G',
            'is_primary' => true,
            'source' => ProductEnergyDeclaration::SOURCE_MANUAL,
        ]);
        $service = $this->service($this->fixtureClient($this->xml([
            $this->row('MSAN-ENERGY-1', 'Energetski podaci', 10, 'Energetski razred', ['A']),
            $this->row('MSAN-ENERGY-1', 'Energetski podaci', 20, 'Raspon energetske ljestvice', ['A-G']),
            $this->row('MSAN-ENERGY-1', 'Energetski podaci', 30, 'EPREL registracijski broj', ['1234567']),
        ])));

        $service->sync($this->syncRun());

        $product->refresh();
        $this->assertSame('B', $product->energy_efficiency_class);
        $this->assertDatabaseHas('product_energy_declarations', [
            'product_id' => $product->id,
            'source' => ProductEnergyDeclaration::SOURCE_MANUAL,
            'energy_class' => 'B',
            'is_primary' => true,
        ]);
        $this->assertDatabaseHas('product_energy_declarations', [
            'product_id' => $product->id,
            'source' => ProductEnergyDeclaration::SOURCE_MSAN,
            'energy_class' => 'A',
            'eprel_registration_number' => '1234567',
            'is_primary' => false,
        ]);
    }

    public function test_first_valid_msan_energy_declaration_becomes_primary_when_an_earlier_value_is_invalid(): void
    {
        [$product, $source] = $this->linkedProduct('MSAN-ENERGY-VALID');
        $this->service($this->fixtureClient($this->xml([
            $this->row('MSAN-ENERGY-VALID', 'Energetski podaci', 10, 'Nevaljani razred', ['Nepoznato']),
            $this->row('MSAN-ENERGY-VALID', 'Energetski podaci', 20, 'Valjani razred', ['C']),
            $this->row('MSAN-ENERGY-VALID', 'Energetski podaci', 30, 'Raspon', ['A-G']),
        ])))->sync($this->syncRun());

        MsanSpecificationDefinition::query()
            ->whereIn('item_name', ['Nevaljani razred', 'Valjani razred'])
            ->update(['data_role' => MsanSpecificationDefinition::ROLE_ENERGY_CLASS]);
        MsanSpecificationDefinition::query()
            ->where('item_name', 'Raspon')
            ->update(['data_role' => MsanSpecificationDefinition::ROLE_ENERGY_SCALE]);

        app(MsanSpecificationPublisher::class)->publishProductFromActiveSnapshot($source->refresh());

        $this->assertDatabaseCount('product_energy_declarations', 1);
        $this->assertDatabaseHas('product_energy_declarations', [
            'product_id' => $product->id,
            'source' => ProductEnergyDeclaration::SOURCE_MSAN,
            'energy_class' => 'C',
            'is_primary' => true,
        ]);
        $this->assertSame('C', $product->refresh()->energy_efficiency_class);
    }

    public function test_conflicting_category_energy_rules_never_depend_on_relation_order(): void
    {
        [$product, $source] = $this->linkedProduct('MSAN-ENERGY-CONFLICT');
        $notApplicable = MsanCategory::query()->create([
            'external_id' => 'ENERGY-NOT-APPLICABLE',
            'name' => 'Nije primjenjivo',
            'is_stale' => false,
        ]);
        $required = MsanCategory::query()->create([
            'external_id' => 'ENERGY-REQUIRED',
            'name' => 'Obavezno',
            'is_stale' => false,
        ]);
        MsanCategoryMapping::query()->create([
            'msan_category_id' => $notApplicable->id,
            'status' => MsanCategoryMapping::STATUS_MAPPED,
            'eprel_product_group' => 'airconditioners',
            'energy_requirement' => MsanCategoryMapping::ENERGY_REQUIREMENT_NOT_APPLICABLE,
        ]);
        MsanCategoryMapping::query()->create([
            'msan_category_id' => $required->id,
            'status' => MsanCategoryMapping::STATUS_MAPPED,
            'eprel_product_group' => 'refrigeratingappliances2019',
            'energy_requirement' => MsanCategoryMapping::ENERGY_REQUIREMENT_REQUIRED,
        ]);
        // Attach the not-applicable category first to prove pivot/relation order
        // cannot suppress the stricter requirement or select its EPREL group.
        $source->categories()->attach($notApplicable->id, ['last_seen_at' => now()]);
        $source->categories()->attach($required->id, ['last_seen_at' => now()]);

        $this->service($this->fixtureClient($this->xml([
            $this->row('MSAN-ENERGY-CONFLICT', 'Energetski podaci', 10, 'Energetski razred', ['A']),
        ])))->sync($this->syncRun());

        $product->refresh();
        $declaration = $product->energyDeclarations()->firstOrFail();
        $this->assertTrue($product->energy_label_required);
        $this->assertSame('A', $declaration->energy_class);
        $this->assertNull($declaration->eprel_product_group);
        $this->assertNull($product->eprel_product_group);
    }

    public function test_nested_specification_values_reject_xml_entities(): void
    {
        $parser = app(MsanSpecificationValuesParser::class);

        $this->assertSame(['Crna', 'Srebrna'], $parser->parse(
            '<Values><Value> Crna </Value><Value>Srebrna</Value><Value>Crna</Value></Values>',
        ));

        $this->expectException(RuntimeException::class);
        $parser->parse('<!DOCTYPE x [<!ENTITY leak SYSTEM "file:///etc/passwd">]><Values><Value>&leak;</Value></Values>');
    }

    public function test_candidate_with_too_many_specifications_for_one_product_never_replaces_active_snapshot(): void
    {
        [$product] = $this->linkedProduct('MSAN-SPEC-BOUNDED');
        $this->service($this->fixtureClient($this->xml([
            $this->row('MSAN-SPEC-BOUNDED', 'Osnovno', 1, 'Postojeća vrijednost', ['Stara']),
        ])))->sync($this->syncRun());
        $activeId = MsanSpecificationSnapshot::query()
            ->where('status', MsanSpecificationSnapshot::STATUS_ACTIVE)
            ->value('id');
        $existingDefinition = MsanSpecificationDefinition::query()
            ->where('item_name', 'Postojeća vrijednost')
            ->firstOrFail();
        $existingLastSeenAt = $existingDefinition->last_seen_at;
        $existingSampleValues = $existingDefinition->sample_values;

        $rows = [];
        foreach (range(1, 2001) as $index) {
            $rows[] = $this->row(
                'MSAN-SPEC-BOUNDED',
                'Prevelika grupa',
                $index,
                'Stavka '.$index,
                ['Vrijednost '.$index],
            );
        }
        $run = $this->syncRun();

        try {
            $this->service($this->fixtureClient($this->xml($rows)))->sync($run);
            $this->fail('Prevelik kandidat mora biti odbijen prije aktivacije.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('previše specifikacija', $exception->getMessage());
        }

        $this->assertSame($activeId, MsanSpecificationSnapshot::query()
            ->where('status', MsanSpecificationSnapshot::STATUS_ACTIVE)
            ->value('id'));
        $this->assertFalse(MsanSpecificationSnapshot::query()
            ->where('msan_sync_run_id', $run->id)
            ->exists());
        $this->assertFalse(MsanSpecificationDefinition::query()
            ->where('item_name', 'Stavka 2001')
            ->exists());
        $existingDefinition->refresh();
        $this->assertTrue($existingDefinition->last_seen_at->equalTo($existingLastSeenAt));
        $this->assertSame($existingSampleValues, $existingDefinition->sample_values);
        $this->assertSame(['Stara'], $product->technicalSpecificationRows()->firstOrFail()->values);
    }

    public function test_publish_failure_restores_previous_snapshot_and_projection(): void
    {
        [$product] = $this->linkedProduct('MSAN-SPEC-ROLLBACK');
        $this->service($this->fixtureClient($this->xml([
            $this->row('MSAN-SPEC-ROLLBACK', 'Osnovno', 1, 'Boja', ['Stara']),
        ])))->sync($this->syncRun());
        $active = MsanSpecificationSnapshot::query()
            ->where('status', MsanSpecificationSnapshot::STATUS_ACTIVE)
            ->firstOrFail();
        $publisher = new class((int) $active->id) extends MsanSpecificationPublisher
        {
            public function __construct(private readonly int $allowedSnapshotId) {}

            public function publishSnapshot(MsanSpecificationSnapshot $snapshot): array
            {
                if ((int) $snapshot->id !== $this->allowedSnapshotId) {
                    throw new RuntimeException('Namjerni kvar objave kandidata.');
                }

                return parent::publishSnapshot($snapshot);
            }
        };
        $service = new MsanSpecificationSyncService(
            $this->fixtureClient($this->xml([
                $this->row('MSAN-SPEC-ROLLBACK', 'Osnovno', 1, 'Boja', ['Nova']),
            ])),
            app(\App\Services\Integrations\Msan\MsanXmlStreamReader::class),
            app(MsanSpecificationValuesParser::class),
            app(\App\Services\Integrations\Msan\MsanSettingsService::class),
            $publisher,
        );
        $run = $this->syncRun();

        try {
            $service->sync($run);
            $this->fail('Kvar objave mora prekinuti sinkronizaciju.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Namjerni kvar objave kandidata.', $exception->getMessage());
        }

        $this->assertSame($active->id, MsanSpecificationSnapshot::query()
            ->where('status', MsanSpecificationSnapshot::STATUS_ACTIVE)
            ->value('id'));
        $this->assertFalse(MsanSpecificationSnapshot::query()
            ->where('msan_sync_run_id', $run->id)
            ->exists());
        $this->assertSame(['Stara'], $product->technicalSpecificationRows()->firstOrFail()->values);
    }

    public function test_sync_and_republish_jobs_share_the_same_cross_job_lock(): void
    {
        $lock = Cache::lock(
            MsanSpecificationPublisher::PUBLISH_LOCK_KEY,
            MsanSpecificationPublisher::PUBLISH_LOCK_SECONDS,
        );
        $this->assertTrue($lock->get());

        try {
            $syncRun = $this->syncRun();
            $syncJob = (new SyncMsanSpecificationsJob((int) $syncRun->id))->withFakeQueueInteractions();
            $syncJob->handle($this->service($this->fixtureClient($this->xml([]))));
            $syncJob->assertReleased(120);

            $republishJob = (new RepublishMsanSpecificationDefinitionJob(123))->withFakeQueueInteractions();
            $republishJob->handle(app(MsanSpecificationPublisher::class));
            $republishJob->assertReleased(120);
        } finally {
            $lock->release();
        }
    }

    public function test_terminal_failure_removes_candidate_snapshot_and_run_temp_directories(): void
    {
        Storage::fake('local');
        $run = $this->syncRun();
        MsanSpecificationSnapshot::query()->create([
            'msan_sync_run_id' => $run->id,
            'status' => MsanSpecificationSnapshot::STATUS_CANDIDATE,
            'source' => 'standard',
        ]);
        $directory = 'integrations/msan/specifications/'.$run->id.'-orphaned';
        Storage::disk('local')->put($directory.'/specifications.xml', '<NewDataSet/>');

        (new SyncMsanSpecificationsJob((int) $run->id))->failed(new RuntimeException('terminal'));

        $this->assertFalse(MsanSpecificationSnapshot::query()
            ->where('msan_sync_run_id', $run->id)
            ->exists());
        Storage::disk('local')->assertMissing($directory);
        $this->assertSame(MsanSyncRun::STATUS_FAILED, $run->fresh()->status);
    }

    public function test_terminal_failure_after_activation_restores_the_previous_snapshot(): void
    {
        [$product] = $this->linkedProduct('MSAN-SPEC-TIMEOUT-ROLLBACK');
        $this->service($this->fixtureClient($this->xml([
            $this->row('MSAN-SPEC-TIMEOUT-ROLLBACK', 'Osnovno', 1, 'Boja', ['Stara']),
        ])))->sync($this->syncRun());
        $previousId = MsanSpecificationSnapshot::query()
            ->where('status', MsanSpecificationSnapshot::STATUS_ACTIVE)
            ->value('id');
        $failedRun = $this->syncRun();
        $this->service($this->fixtureClient($this->xml([
            $this->row('MSAN-SPEC-TIMEOUT-ROLLBACK', 'Osnovno', 1, 'Boja', ['Nova']),
        ])))->sync($failedRun);
        $failedSnapshotId = MsanSpecificationSnapshot::query()
            ->where('msan_sync_run_id', $failedRun->id)
            ->value('id');
        $failedRun->forceFill([
            'status' => MsanSyncRun::STATUS_RUNNING,
            'completed_at' => null,
        ])->save();

        (new SyncMsanSpecificationsJob((int) $failedRun->id))
            ->failed(new RuntimeException('terminal timeout'));

        $this->assertSame($previousId, MsanSpecificationSnapshot::query()
            ->where('status', MsanSpecificationSnapshot::STATUS_ACTIVE)
            ->value('id'));
        $this->assertDatabaseMissing('msan_specification_snapshots', ['id' => $failedSnapshotId]);
        $this->assertSame(['Stara'], $product->technicalSpecificationRows()->firstOrFail()->values);
        $this->assertSame(MsanSyncRun::STATUS_FAILED, $failedRun->fresh()->status);
    }

    public function test_terminal_failure_of_the_first_active_snapshot_clears_partial_projection(): void
    {
        [$product] = $this->linkedProduct('MSAN-SPEC-FIRST-TIMEOUT');
        $failedRun = $this->syncRun();
        $this->service($this->fixtureClient($this->xml([
            $this->row('MSAN-SPEC-FIRST-TIMEOUT', 'Osnovno', 1, 'Boja', ['Privremena']),
        ])))->sync($failedRun);
        $failedRun->forceFill([
            'status' => MsanSyncRun::STATUS_RUNNING,
            'completed_at' => null,
        ])->save();

        (new SyncMsanSpecificationsJob((int) $failedRun->id))
            ->failed(new RuntimeException('terminal timeout'));

        $this->assertDatabaseCount('msan_specification_snapshots', 0);
        $this->assertSame(0, $product->technicalSpecificationRows()->count());
        $this->assertNull($product->fresh()->energy_efficiency_class);
        $this->assertSame(MsanSyncRun::STATUS_FAILED, $failedRun->fresh()->status);
    }

    public function test_retry_start_removes_same_run_candidate_and_temp_directory_before_rebuild(): void
    {
        Storage::fake('local');
        $this->linkedProduct('MSAN-SPEC-RETRY-CLEANUP');
        $run = $this->syncRun();
        $candidate = MsanSpecificationSnapshot::query()->create([
            'msan_sync_run_id' => $run->id,
            'status' => MsanSpecificationSnapshot::STATUS_CANDIDATE,
            'source' => 'standard',
        ]);
        $directory = 'integrations/msan/specifications/'.$run->id.'-orphaned';
        Storage::disk('local')->put($directory.'/specifications.xml', '<NewDataSet/>');

        $this->service($this->fixtureClient($this->xml([
            $this->row('MSAN-SPEC-RETRY-CLEANUP', 'Osnovno', 1, 'Boja', ['Nova']),
        ])))->sync($run);

        $this->assertDatabaseMissing('msan_specification_snapshots', ['id' => $candidate->id]);
        $this->assertDatabaseHas('msan_specification_snapshots', [
            'msan_sync_run_id' => $run->id,
            'status' => MsanSpecificationSnapshot::STATUS_ACTIVE,
        ]);
        Storage::disk('local')->assertMissing($directory);
    }

    /** @return array{Product, MsanProduct} */
    private function linkedProduct(string $code): array
    {
        $product = Product::query()->create([
            'code' => $code,
            'sku' => $code,
            'is_active' => true,
            'base_price' => 100,
            'stock_qty' => 1,
            'payload' => ['catalog_origin' => 'msan'],
        ]);
        $source = MsanProduct::query()->create([
            'external_code' => $code,
            'name' => 'Test '.$code,
            'selected' => true,
            'is_stale' => false,
            'local_product_id' => $product->id,
            'last_seen_at' => now(),
        ]);

        return [$product, $source];
    }

    private function syncRun(): MsanSyncRun
    {
        return MsanSyncRun::query()->create([
            'kind' => MsanSyncRun::KIND_SPECIFICATIONS,
            'status' => MsanSyncRun::STATUS_PENDING,
        ]);
    }

    private function service(MsanClient $client): MsanSpecificationSyncService
    {
        return new MsanSpecificationSyncService(
            $client,
            app(\App\Services\Integrations\Msan\MsanXmlStreamReader::class),
            app(MsanSpecificationValuesParser::class),
            app(\App\Services\Integrations\Msan\MsanSettingsService::class),
            app(MsanSpecificationPublisher::class),
        );
    }

    private function fixtureClient(string $xml): MsanClient
    {
        return new class($xml) extends MsanClient
        {
            public int $downloads = 0;

            public function __construct(private readonly string $xml) {}

            public function downloadDataset(string $dataset, string $destinationPath): void
            {
                $this->downloads++;
                if (! in_array($dataset, ['specifications', 'specifications_icecat'], true)) {
                    throw new RuntimeException('Neočekivani dataset '.$dataset);
                }
                if (! is_dir(dirname($destinationPath))) {
                    mkdir(dirname($destinationPath), 0750, true);
                }
                file_put_contents($destinationPath, $this->xml);
            }
        };
    }

    /**
     * @param  list<string>  $values
     * @return array<string, scalar|null>
     */
    private function row(
        string $code,
        string $group,
        int $order,
        string $name,
        array $values,
        ?string $measure = null,
        bool $forFilter = false,
    ): array {
        $nested = '<Values>'.implode('', array_map(
            static fn (string $value): string => '<Value>'.htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8').'</Value>',
            $values,
        )).'</Values>';

        return [
            'ProductCode' => $code,
            'SpecificationGroup' => $group,
            'SpecificationItemNo' => $order,
            'SpecificationItemName' => $name,
            'SpecificationItemValues' => $nested,
            'SpecificationItemMeasure' => $measure,
            'SpecificationItemForFilter' => $forFilter ? 'true' : 'false',
        ];
    }

    /** @param list<array<string, scalar|null>> $rows */
    private function xml(array $rows): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?><NewDataSet>';
        foreach ($rows as $row) {
            $xml .= '<Table>';
            foreach ($row as $field => $value) {
                $xml .= '<'.$field.'>'.htmlspecialchars((string) $value, ENT_XML1 | ENT_QUOTES, 'UTF-8').'</'.$field.'>';
            }
            $xml .= '</Table>';
        }

        return $xml.'</NewDataSet>';
    }
}
