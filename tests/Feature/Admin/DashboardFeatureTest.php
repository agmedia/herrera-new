<?php

namespace Tests\Feature\Admin;

use App\Livewire\Admin\Dashboard\Overview;
use App\Models\Sales\Order\Order;
use App\Models\Settings\Local\OrderStatus;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Silber\Bouncer\BouncerFacade as Bouncer;
use Tests\TestCase;

class DashboardFeatureTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_open_simplified_sales_dashboard(): void
    {
        $admin = $this->makeUserWithRole('admin');

        $this->actingAs($admin)
            ->get('/admin/dashboard')
            ->assertOk()
            ->assertSee(__('Sales Overview'))
            ->assertSee(__('Sales by Period'))
            ->assertSee('data-dashboard-period="today"', false)
            ->assertSee('data-dashboard-period="month"', false)
            ->assertSee('data-dashboard-period="year"', false)
            ->assertSee(__('Sales Statistics'))
            ->assertSee(__('Monthly Overview'))
            ->assertSee(__('Recent Orders'))
            ->assertSee('data-dashboard-empty-state', false)
            ->assertDontSee('data-dashboard-chart', false);
    }

    public function test_dashboard_never_renders_secondary_analytics_sections(): void
    {
        $admin = $this->makeUserWithRole('admin');

        $this->actingAs($admin)
            ->get('/admin/dashboard')
            ->assertOk()
            ->assertDontSee(__('Loyalty Net Points'))
            ->assertDontSee(__('Recent Loyalty Activity'))
            ->assertDontSee(__('Recent Tracking Events'))
            ->assertDontSee(__('Recent Admin Activity'))
            ->assertDontSee(__('New Users Trend'))
            ->assertDontSee(__('Catalog & Content Snapshot'));
    }

    public function test_dashboard_month_filter_keeps_calendar_order_in_the_enhanced_select(): void
    {
        $admin = $this->makeUserWithRole('admin');

        $component = Livewire::actingAs($admin)->test(Overview::class)
            ->assertViewHas(
                'monthOptions',
                fn (array $monthOptions): bool => array_keys($monthOptions) === range(1, 12)
            );

        self::assertMatchesRegularExpression(
            '/<select\b(?=[^>]*\bid="dashboard-statistics-month")(?=[^>]*\bdata-tom-preserve-order="1")[^>]*>/u',
            $component->html()
        );
    }

    public function test_dashboard_period_cards_and_statistics_use_the_expected_date_windows(): void
    {
        $now = CarbonImmutable::parse('2026-09-02 08:00:00');
        $this->travelTo($now);

        $admin = $this->makeUserWithRole('admin');
        $status = OrderStatus::query()->create([
            'code' => 'new',
            'name' => 'New',
            'color' => 'blue',
            'is_default' => true,
            'is_paid' => false,
            'is_cancelled' => false,
            'is_active' => true,
            'sort_order' => 1,
        ]);

        $this->createOrder($status, $admin, 'TERMOL-001', 100, 2, $now->subHour());
        $this->createOrder($status, $admin, 'TERMOL-002', 200, 3, $now->startOfMonth()->addHours(12));
        $this->createOrder($status, $admin, 'TERMOL-003', 300, 1, $now->startOfYear()->addMonth());
        $this->createOrder($status, $admin, 'TERMOL-004', 400, 4, $now->subYear()->startOfYear()->addMonth());

        Livewire::actingAs($admin)
            ->test(Overview::class)
            ->assertViewHas('periodCards', function (array $periodCards): bool {
                $periods = collect($periodCards)->keyBy('key');

                return $periods['today']['orders'] === 1
                    && $periods['today']['order_value'] === 100.0
                    && $periods['month']['orders'] === 2
                    && $periods['month']['order_value'] === 300.0
                    && $periods['year']['orders'] === 3
                    && $periods['year']['order_value'] === 600.0;
            })
            ->assertViewHas('statistics', fn (array $statistics): bool => $statistics['orders'] === 2
                && $statistics['items_sold'] === 5
                && $statistics['order_value'] === 300.0
                && $statistics['average_order'] === 150.0)
            ->call('selectStatisticsView', 'year')
            ->assertSet('statisticsView', 'year')
            ->assertViewHas('statistics', fn (array $statistics): bool => $statistics['orders'] === 3
                && $statistics['items_sold'] === 6
                && $statistics['order_value'] === 600.0
                && $statistics['average_order'] === 200.0);
    }

    private function makeUserWithRole(string $role): User
    {
        Bouncer::role()->firstOrCreate(['name' => 'superadmin']);
        Bouncer::role()->firstOrCreate(['name' => 'admin']);
        Bouncer::role()->firstOrCreate(['name' => 'editor']);
        Bouncer::role()->firstOrCreate(['name' => 'customer']);

        $user = User::factory()->create();
        Bouncer::assign($role)->to($user);

        return $user;
    }

    private function createOrder(
        OrderStatus $status,
        User $user,
        string $number,
        float $total,
        int $items,
        CarbonImmutable $placedAt
    ): Order {
        return Order::query()->create([
            'order_number' => $number,
            'status_id' => $status->id,
            'user_id' => $user->id,
            'source' => 'web',
            'locale' => 'hr',
            'currency_code' => 'EUR',
            'currency_rate' => 1,
            'customer_name' => 'Dashboard Customer',
            'customer_email' => strtolower($number).'@example.test',
            'item_qty' => $items,
            'subtotal' => $total,
            'grand_total' => $total,
            'placed_at' => $placedAt,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);
    }
}
