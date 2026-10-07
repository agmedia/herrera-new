<?php

namespace Tests\Feature\Integrations;

use App\Models\Integrations\Eracuni\EracuniCatalogRun;
use App\Services\Integrations\Eracuni\EracuniCatalogService;
use App\Services\Integrations\Stock\StockSyncSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class EracuniAttributeCronFeatureTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    public function test_attributes_cron_rejects_wrong_token_and_never_executes_head(): void
    {
        $settings = $this->configure();
        $this->mock(EracuniCatalogService::class)->shouldNotReceive('syncAttributes');

        $this->getJson('/integrations/eracuni/attributes')->assertForbidden();
        $this->getJson('/integrations/eracuni/attributes?token=wrong')->assertForbidden();
        $this->call('HEAD', $settings->attributesCronUrl())->assertOk()->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_attributes_have_a_separate_automatic_switch_from_warehouse_stock(): void
    {
        $settings = $this->configure();
        $this->mock(EracuniCatalogService::class)->shouldNotReceive('syncAttributes');

        $this->assertTrue($settings->enabled('eracuni'));
        $this->getJson($settings->attributesCronUrl())->assertStatus(409)->assertJsonPath('status', 'disabled');
        $settings->saveAttributesEnabled(true);
        $this->assertTrue($settings->attributesEnabled());
    }

    public function test_attributes_cron_reports_saved_counts_and_passes_only_validated_range(): void
    {
        $settings = $this->configure();
        $settings->saveAttributesEnabled(true);
        $run = EracuniCatalogRun::query()->create([
            'kind' => 'attributes', 'status' => 'completed', 'fetched_count' => 4,
            'updated_count' => 2, 'unchanged_count' => 1, 'skipped_count' => 1,
            'summary' => ['trigger' => 'cron', 'scope' => 'range', 'limit_reached' => false],
            'started_at' => now(), 'completed_at' => now(),
        ]);
        $this->mock(EracuniCatalogService::class)->shouldReceive('syncAttributes')->once()
            ->with(null, '00001', '00099', 'cron')->andReturn($run);

        $this->getJson($settings->attributesCronUrl('00001', '00099'))->assertOk()
            ->assertJsonPath('run_id', $run->id)->assertJsonPath('updated', 2)->assertJsonPath('scope', 'range');
    }

    public function test_failed_attribute_report_returns_error_for_easycron_and_invalid_range_is_rejected(): void
    {
        $settings = $this->configure();
        $settings->saveAttributesEnabled(true);
        $run = EracuniCatalogRun::query()->create([
            'kind' => 'attributes', 'status' => 'failed', 'summary' => ['limit_reached' => true],
            'error_message' => 'Suzite raspon šifri.', 'started_at' => now(), 'completed_at' => now(),
        ]);
        $this->mock(EracuniCatalogService::class)->shouldReceive('syncAttributes')->once()
            ->with(null, null, null, 'cron')->andReturn($run);

        $this->getJson($settings->attributesCronUrl('bad"code'))->assertUnprocessable();
        $this->getJson($settings->attributesCronUrl())->assertStatus(502)->assertJsonPath('limit_reached', true);
    }

    private function configure(): StockSyncSettingsService
    {
        $settings = app(StockSyncSettingsService::class);
        $settings->saveConnection('eracuni', ['url' => 'https://erp.example.test/api/v1', 'username' => 'test', 'password' => 'password', 'token' => 'token']);

        return $settings;
    }
}
