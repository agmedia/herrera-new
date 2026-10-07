<?php

namespace Tests\Feature\Front;

use App\Models\Sales\Order\Order;
use App\Models\User;
use App\Models\User\B2BAccount;
use App\Models\User\CustomerGroup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class B2BAccountNavigationFeatureTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['commerce.b2b_only' => true]);
    }

    #[DataProvider('devices')]
    public function test_approved_buyer_has_the_same_b2b_navigation_on_every_account_page(string $userAgent): void
    {
        [$user] = $this->buyer();
        $order = $this->order($user);
        $this->actingAs($user)->withHeaders(['User-Agent' => $userAgent]);

        $pages = [
            'account.dashboard' => [],
            'account.orders' => [],
            'account.profile' => [],
            'account.orders.show' => ['orderNumber' => $order->order_number],
            'account.b2b.quick-order' => [],
            'account.b2b.frequent-products' => [],
            'account.b2b.favorite-products' => [],
        ];

        foreach ($pages as $route => $parameters) {
            $response = $this->get(route($route, $parameters))->assertOk();
            $response->assertDontSee('data-b2b-approval-notice', false);
            $this->assertB2BNavigation($response, true);
            if (str_starts_with($route, 'account.b2b.')) {
                $this->assertCurrentNavigation($response, route($route));
            }
        }

        $this->get(route('account.orders'))->assertSee(route('account.orders.reorder', ['orderNumber' => $order->order_number]), false);
        $this->get(route('account.orders.show', ['orderNumber' => $order->order_number]))
            ->assertSee(route('account.orders.reorder', ['orderNumber' => $order->order_number]), false);
    }

    #[DataProvider('ineligibleStates')]
    public function test_additional_groups_or_ineligible_accounts_do_not_expose_b2b_links_or_routes(string $state): void
    {
        [$user, $group, $account] = $this->buyer();
        match ($state) {
            'additional_group_only' => $account->delete(),
            'pending', 'rejected', 'suspended' => $account->update(['status' => $state]),
            'expired' => $account->update(['contract_ends_at' => now()->subDay()]),
            'future' => $account->update(['contract_starts_at' => now()->addDay()]),
            'inactive_primary_group' => $group->update(['is_active' => false]),
            'missing_primary_group' => $account->update(['customer_group_id' => null]),
        };
        if (in_array($state, ['inactive_primary_group', 'missing_primary_group'], true)) {
            $otherGroup = CustomerGroup::query()->create(['code' => 'other-active', 'name' => 'Other active group', 'is_active' => true]);
            $user->customerGroups()->attach($otherGroup);
        }
        $order = $this->order($user);
        $reorderUrl = route('account.orders.reorder', ['orderNumber' => $order->order_number]);
        $this->actingAs($user);

        foreach (self::devices() as [$userAgent]) {
            $this->withHeaders(['User-Agent' => $userAgent]);
            foreach ([route('account.dashboard'), route('account.orders'), route('account.profile'), route('account.orders.show', ['orderNumber' => $order->order_number])] as $url) {
                $response = $this->get($url)->assertOk()->assertDontSee($reorderUrl, false)
                    ->assertSee('data-b2b-approval-notice', false)
                    ->assertSee('Za brzu narudžbu potreban je odobren B2B poslovni račun. Sama grupa kupaca ne omogućuje pristup.');
                $this->assertB2BNavigation($response, false);
            }
        }

        foreach (['account.b2b.quick-order', 'account.b2b.frequent-products', 'account.b2b.favorite-products'] as $route) {
            $this->get(route($route))->assertForbidden();
        }
        $this->getJson(route('account.b2b.quick-order.search', ['q' => 'test']))->assertForbidden();
        $this->putJson(route('account.b2b.quick-order.draft'), ['items' => []])->assertForbidden();
        $this->post(route('account.b2b.quick-order.store'), ['items' => []])->assertForbidden();
        $this->post($reorderUrl)->assertForbidden();
    }

    public function test_retail_configuration_does_not_show_a_b2b_approval_warning_for_group_members(): void
    {
        config(['commerce.b2b_only' => false]);
        [$user, , $account] = $this->buyer();
        $account->delete();
        $this->actingAs($user);

        foreach (self::devices() as [$userAgent]) {
            $response = $this->withHeaders(['User-Agent' => $userAgent])->get(route('account.orders'))->assertOk()
                ->assertDontSee('data-b2b-approval-notice', false);
            $this->assertB2BNavigation($response, false);
        }
    }

    public static function devices(): array
    {
        return [
            'desktop' => ['Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 Chrome/126.0.0.0 Safari/537.36'],
            'mobile' => ['Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 Version/17.0 Mobile/15E148 Safari/604.1'],
        ];
    }

    public static function ineligibleStates(): array
    {
        return array_map(static fn (string $state): array => [$state], [
            'additional_group_only', 'pending', 'rejected', 'suspended', 'expired', 'future', 'inactive_primary_group', 'missing_primary_group',
        ]);
    }

    private function assertB2BNavigation(TestResponse $response, bool $available): void
    {
        $links = $this->navigationLinks($response);
        foreach ([
            'account.b2b.quick-order' => 'Brza narudžba',
            'account.b2b.frequent-products' => 'Često naručivani artikli',
            'account.b2b.favorite-products' => 'Favoriti',
        ] as $route => $label) {
            $url = route($route);
            if ($available) {
                $this->assertArrayHasKey($url, $links);
                $this->assertSame($label, trim($links[$url]->textContent, " \n\r\t•"));
            } else {
                $this->assertArrayNotHasKey($url, $links);
            }
        }
    }

    private function assertCurrentNavigation(TestResponse $response, string $url): void
    {
        $this->assertSame('page', $this->navigationLinks($response)[$url]->getAttribute('aria-current'));
    }

    private function navigationLinks(TestResponse $response): array
    {
        $document = new \DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8">'.$response->getContent());
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $nodes = (new \DOMXPath($document))->query('//aside[contains(concat(" ", normalize-space(@class), " "), " commerce-account-nav ")]//nav//a');
        $this->assertGreaterThan(0, $nodes->length);

        $links = [];
        foreach ($nodes as $node) {
            $links[$node->getAttribute('href')] = $node;
        }

        return $links;
    }

    private function buyer(): array
    {
        $user = User::factory()->create();
        $group = CustomerGroup::query()->create(['code' => 'b2b40', 'name' => 'B2B40', 'is_active' => true]);
        $user->customerGroups()->attach($group);
        $account = B2BAccount::query()->create([
            'user_id' => $user->id,
            'status' => B2BAccount::STATUS_APPROVED,
            'company_name' => 'Navigation Test d.o.o.',
            'oib' => '12345678901',
            'customer_group_id' => $group->id,
        ]);

        return [$user, $group, $account];
    }

    private function order(User $user): Order
    {
        return Order::query()->create([
            'order_number' => 'B2B-NAV-1',
            'user_id' => $user->id,
            'source' => 'web',
            'locale' => 'hr',
            'currency_code' => 'EUR',
            'currency_rate' => 1,
            'customer_name' => $user->name,
            'customer_email' => $user->email,
        ]);
    }
}
