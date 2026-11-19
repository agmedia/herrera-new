<?php

namespace Tests\Feature\Admin;

use App\Models\Sales\Order\Order;
use App\Models\User;
use App\Models\User\UserTrackingEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Silber\Bouncer\BouncerFacade as Bouncer;
use Tests\TestCase;

class RepresentativeStatisticsTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_selects_rep_and_exports_the_whole_monthly_portfolio_with_separate_login_evidence(): void
    {
        $owner = $this->staff('superadmin', 'Vlasnik');
        $rep = $this->staff('order_manager', 'Ana Primjer');
        $other = $this->staff('order_manager', 'Marko Primjer');
        $buyer = User::factory()->create(['name' => '=TEST()']);
        $outside = User::factory()->create(['name' => 'Drugi kupac']);
        $this->assign($rep, $buyer);
        $this->assign($other, $buyer);
        $this->assign($other, $outside);
        for ($i = 0; $i < 23; $i++) {
            $this->order($buyer, $i === 22 ? '2026-03-15' : '2026-01-20');
        }
        $this->order($outside, '2026-03-15');
        $this->order($buyer, '2025-12-31');
        $this->order($buyer, '2026-02-01', 'USD');
        $this->order($buyer, '2026-02-01', 'EUR', 'B2C');
        $draft = $this->order($buyer, '2026-02-01');
        $draft->forceFill(['source' => 'opencart_import', 'status_id' => null, 'payload' => ['opencart' => ['order_status_id' => '0']]])->save();
        foreach (['admin', 'storefront', null] as $i => $surface) {
            UserTrackingEvent::create(['user_id' => $rep->id, 'event' => 'staff_login', 'occurred_at' => '2026-03-10 10:0'.$i,
                'payload' => ['login_surface' => $surface, 'location' => ['latitude' => 45.812345, 'longitude' => 15.977777, 'accuracy' => 8]]]);
        }
        $this->actingAs($owner);
        $this->get('/admin/users/sales-reports')->assertRedirectToRoute('admin.users.rep-statistics');
        $this->get(route('admin.dashboard'))->assertOk()->assertSee(route('admin.users.rep-statistics'), false)
            ->assertDontSee('/admin/users/sales-reports', false)->assertDontSee('Izvještaji po kupcima i komercijalistima');
        $this->get(route('admin.users.rep-statistics'))->assertOk()->assertSee('Ana Primjer')->assertDontSee('Izvezi u Excel');
        $admin = $this->staff('admin', 'Tehnička podrška');
        $this->assign($admin, $buyer);
        $this->get(route('admin.users.rep-statistics'))->assertViewHas('reps', fn ($list) => $list->pluck('id')->all() === [$rep->id, $other->id]);
        foreach ([$owner, $admin] as $staff) {
            $this->get(route('admin.users.rep-statistics', ['rep' => $staff->id]))->assertNotFound();
            $this->get(route('admin.users.rep-statistics.export', ['rep' => $staff->id]))->assertNotFound();
        }
        $this->get(route('admin.users.rep-statistics', ['q' => 'Ana']))->assertOk()->assertSee('Ana Primjer')->assertDontSee('Marko Primjer');
        $filters = ['rep' => $rep->id, 'from' => '2026-01', 'to' => '2026-03', 'currency' => 'EUR', 'channel' => 'B2B'];
        $response = $this->get(route('admin.users.rep-statistics', $filters))->assertOk()->assertSee('data-dashboard-chart', false)
            ->assertSee('2.300,00')->assertSee('45.812345')->assertDontSee('Drugi kupac')->assertSee('Izvezi u Excel');
        $overview = $response->viewData('overview');
        $this->assertSame([22, 0, 1], $overview['months']->pluck('portfolio_orders')->all());
        $this->assertEquals([2200, 0, 100], $overview['months']->pluck('portfolio')->all());
        $this->assertEquals(0, $overview['months']->sum('personal'));
        $this->assertSame(1, $overview['active']);
        $this->assertSame(1, $overview['adminLogins']);
        $this->get(route('admin.users.rep-statistics', $filters + ['section' => 'staff']))->assertOk()
            ->assertSee('Administracija')->assertSee('Webshop')->assertSee('Izvor nije zabilježen');
        foreach (['visits', 'sessions', 'products', 'buyers', 'personal'] as $section) {
            $this->get(route('admin.users.rep-statistics', $filters + ['section' => $section]))->assertOk();
        }
        $this->get(route('admin.users.rep-statistics', array_replace($filters, ['buyer' => $buyer->id, 'from' => '2026-03', 'section' => 'orders'])))
            ->assertOk()->assertViewHas('page', fn ($page) => $page->total() === 1);
        $this->get(route('admin.users.rep-statistics.export', $filters + ['buyer' => $outside->id]))->assertNotFound();
        $response = $this->get(route('admin.users.rep-statistics.export', $filters + ['section' => 'orders', 'page' => 2]))
            ->assertOk()->assertDownload()->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $file = $response->baseResponse->getFile()->getPathname();
        try {
            $book = IOFactory::load($file);
            $this->assertSame(24, $book->getSheetByName('Narudžbe kupaca')->getHighestDataRow());
            $sheet = $book->getSheetByName('Kupnja po kupcu');
            $this->assertSame(3, $sheet->getHighestDataRow());
            $this->assertSame('=TEST()', (string) $sheet->getCell('B2')->getValue());
            $this->assertNotSame(DataType::TYPE_FORMULA, $sheet->getCell('B2')->getDataType());
            $this->assertSame(DataType::TYPE_NUMERIC, $sheet->getCell('D2')->getDataType());
            $this->assertEquals(2200, $sheet->getCell('D3')->getValue());
            $this->assertSame('2026-01', $sheet->getCell('A3')->getFormattedValue());
            $this->assertEquals(45.812345, $book->getSheetByName('Prijave komercijalista')->getCell('C2')->getValue());
            $this->assertEquals(0, $book->getSheetByName('Mjesečni pregled')->getCell('B3')->getValue());
        } finally {
            @unlink($file);
        }
    }

    public function test_html_and_excel_enforce_the_selected_identity_and_activity_permissions(): void
    {
        $rep = $this->staff('order_manager', 'Moji kupci');
        $other = $this->staff('order_manager', 'Tuđi kupci');
        $this->actingAs($rep);
        foreach (['admin.users.rep-statistics', 'admin.users.rep-statistics.export'] as $route) {
            $this->get(route($route, ['rep' => $other->id]))->assertNotFound();
        }
        Bouncer::forbid($rep)->to('users.activity.view');
        Bouncer::forbid($rep)->to('sales.orders.view');
        Bouncer::refreshFor($rep);
        $this->get(route('admin.users.rep-statistics', ['rep' => $rep->id]))->assertOk()->assertViewHas('section', 'visits')->assertDontSee('Zadnja potvrđena prijava');
        $this->get(route('admin.users.rep-statistics', ['rep' => $rep->id, 'section' => 'staff']))->assertForbidden();
        $result = $this->get(route('admin.users.rep-statistics.export', ['rep' => $rep->id]))->assertOk()->assertDownload();
        $file = $result->baseResponse->getFile()->getPathname();
        try {
            $book = IOFactory::load($file);
            $this->assertNull($book->getSheetByName('Prijave komercijalista'));
            $this->assertNull($book->getSheetByName('Narudžbe kupaca'));
            $this->assertNull($book->getSheetByName('Kupnja po kupcu'));
        } finally {
            @unlink($file);
        }
    }

    private function staff(string $role, string $name): User
    {
        $user = User::factory()->create(['account_type' => 'staff', 'name' => $name, 'email' => \Illuminate\Support\Str::slug($name).'@rep.test', 'admin_login_enabled' => true]);
        Bouncer::assign($role)->to($user);

        return $user;
    }

    private function assign(User $rep, User $buyer): void
    {
        DB::table('admin_customer_assignments')->insert(['admin_user_id' => $rep->id, 'customer_user_id' => $buyer->id]);
    }

    private function order(User $user, string $date, string $currency = 'EUR', string $channel = 'B2B'): Order
    {
        return Order::create(['order_number' => uniqid('R-'), 'user_id' => $user->id, 'placed_at' => $date, 'customer_type' => $channel,
            'customer_name' => $user->name, 'customer_email' => $user->email, 'grand_total' => 125, 'tax_total' => 25, 'currency_code' => $currency]);
    }
}
