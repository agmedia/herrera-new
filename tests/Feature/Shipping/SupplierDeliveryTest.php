<?php

namespace Tests\Feature\Shipping;

use App\Livewire\Admin\Integrations\Delivery\Dashboard;
use App\Models\Catalog\Product\Product;
use App\Models\Integrations\SupplierDeliverySchedule;
use App\Models\User;
use App\Services\Shipping\SupplierDeliveryService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SupplierDeliveryTest extends TestCase
{
    use RefreshDatabase;

    public static function calendarExamples(): array
    {
        return [
            'wait for monthly order' => ['esperanza', '2026-11-09', '2026-11-20', '2026-12-06'],
            'order date inclusive' => ['esperanza', '2026-11-05', '2026-11-05', '2026-11-21'],
            'month rollover' => ['esperanza', '2026-11-21', '2026-12-05', '2026-12-21'],
            'master updated lead' => ['master', '2026-11-09', '2026-11-15', '2026-12-01'],
            'weekly Wednesday' => ['braytron', '2026-11-09', '2026-11-11', '2026-11-20'],
            'Vayox updated dates' => ['vayox', '2026-11-09', '2026-11-10', '2026-11-22'],
            'start holiday buffer' => ['dpm', '2026-12-20', '2026-12-20', '2027-01-10'],
            'last buffer day' => ['dpm', '2027-01-08', '2027-01-20', '2027-02-10'],
            'first normal day' => ['dpm', '2027-01-09', '2027-01-20', '2027-02-05'],
        ];
    }

    #[DataProvider('calendarExamples')]
    public function test_confirmed_schedule_uses_calendar_days(string $supplier, string $today, string $order, string $delivery): void
    {
        $product = $this->product($supplier);
        $estimate = app(SupplierDeliveryService::class)->estimate($product, at: CarbonImmutable::parse($today, 'Europe/Zagreb'));
        $this->assertSame($order, $estimate['supplier_order_date']);
        $this->assertSame($delivery, $estimate['delivery_date']);
        $this->assertSame('supplier', $estimate['status']);
    }

    public function test_requested_quantity_can_create_partial_deliveries_and_pickup_omits_customer_transport(): void
    {
        $product = $this->product('master');
        $product->update(['stock_qty' => 2]);
        $date = CarbonImmutable::parse('2026-11-09', 'Europe/Zagreb');
        $service = app(SupplierDeliveryService::class);
        $this->assertSame('2026-11-11', $service->estimate($product, 2, at: $date)['delivery_date']);
        $mixed = $service->estimate($product, 3, at: $date);
        $this->assertSame('mixed', $mixed['status']);
        $this->assertTrue($mixed['partial_shipments']);
        $this->assertSame('2026-11-11', $mixed['first_delivery_date']);
        $this->assertSame('2026-12-01', $mixed['delivery_date']);
        $this->assertSame('2026-11-29', $service->estimate($product, 3, at: $date, pickup: true)['delivery_date']);
    }

    public function test_manual_date_expires_and_no_unknown_supplier_date_is_invented(): void
    {
        $product = $this->product('nopallux');
        $schedule = SupplierDeliverySchedule::query()->where('supplier', 'nopallux')->sole();
        $schedule->update(['next_order_date' => '2026-11-12']);
        $service = new SupplierDeliveryService;
        $this->assertSame('2026-11-28', $service->estimate($product, at: CarbonImmutable::parse('2026-11-09'))['delivery_date']);
        $this->assertSame('inquiry', $service->estimate($product, at: CarbonImmutable::parse('2026-11-13'))['status']);
        $product->update(['payload' => ['supplier_catalog' => ['supplier' => 'unknown']]]);
        $this->assertNull($service->estimate($product)['delivery_date']);
    }

    public function test_customer_endpoint_contains_dates_without_exact_inventory_and_rejects_invalid_quantity(): void
    {
        config(['commerce.b2b_only' => false]);
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-09', 'Europe/Zagreb'));
        try {
            $product = $this->product('videx');
            $response = $this->getJson(route('products.delivery', $product).'?quantity=3')->assertOk()->assertJsonPath('delivery_date', '2026-11-22');
            $response->assertHeader('Cache-Control', 'no-store, private');
            $this->assertArrayNotHasKey('stock_qty', $response->json());
            $this->assertArrayNotHasKey('supplier_stock_qty', $response->json());
            $this->getJson(route('products.delivery', $product).'?quantity=0')->assertUnprocessable();
            $this->getJson(route('products.delivery', $product).'?quantity=999')->assertOk()->assertJsonPath('status', 'unavailable');
            $product->update(['is_active' => false]);
            $this->getJson(route('products.delivery', $product))->assertNotFound();
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_administrator_can_edit_schedule_and_invalid_month_days_are_rejected(): void
    {
        $admin = User::factory()->create();
        $admin->assign('superadmin');
        $schedule = SupplierDeliverySchedule::query()->where('supplier', 'dpm')->sole();
        Livewire::actingAs($admin)->test(Dashboard::class)
            ->set("forms.$schedule->id.days", '5, 20, 32')->call('save', $schedule->id)->assertHasErrors("forms.$schedule->id.days")
            ->set("forms.$schedule->id.days", '4, 18')->set("forms.$schedule->id.lead_days", 12)->call('save', $schedule->id)->assertHasNoErrors();
        $this->assertSame([4, 18], $schedule->fresh()->monthly_days);
        $this->actingAs(User::factory()->create())->get(route('admin.integrations.delivery.index'))->assertForbidden();
    }

    public function test_registered_catalogue_clearance_and_stock_schedules_are_valid_without_running_jobs(): void
    {
        \Illuminate\Support\Facades\Http::preventStrayRequests();
        $this->artisan('schedule:list', ['--no-ansi' => true])
            ->expectsOutputToContain('herrera:sync-supplier-catalog --import-drafts')
            ->expectsOutputToContain('herrera:sync-clearance-sales --apply --run-clearance')
            ->expectsOutputToContain('herrera:sync-stock esperanza --cron')
            ->assertExitCode(0);
        \Illuminate\Support\Facades\Http::assertNothingSent();
    }

    private function product(string $supplier): Product
    {
        return Product::query()->create(['code' => 'ETA-'.$supplier, 'sku' => 'ETA-'.$supplier, 'is_active' => true, 'base_price' => 10, 'stock_qty' => 0, 'supplier_stock_qty' => 100,
            'payload' => ['supplier_catalog' => ['supplier' => $supplier]]]);
    }
}
