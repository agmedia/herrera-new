<?php

namespace App\Http\Controllers\Front;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Front\Concerns\ResolvesFrontendView;
use App\Models\Sales\Order\Order;
use App\Models\Settings\Local\OrderStatus;
use App\Models\User\LoyaltyTransaction;
use App\Models\User\UserAddress;
use App\Models\User\UserProfile;
use App\Services\Front\AddressDirectoryService;
use App\Services\Loyalty\LoyaltyService;
use App\Services\Settings\SystemSettingsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class AccountController extends Controller
{
    use ResolvesFrontendView;

    public function dashboard(Request $request): View
    {
        $user = $request->user();
        $user->loadMissing(['profile', 'addresses', 'b2bAccount.customerGroup']);

        $orders = Order::query()
            ->where('user_id', $user->id)
            ->with('status:id,name,color')
            ->latest('id')
            ->limit(6)
            ->get(['id', 'order_number', 'status_id', 'grand_total', 'currency_code', 'placed_at', 'created_at']);

        $loyaltyService = app(LoyaltyService::class);
        $loyaltyEnabled = $loyaltyService->availableForUser($user);

        $loyaltyBalance = 0;
        $loyaltyRecent = collect();

        if ($loyaltyEnabled) {
            $loyaltyBalance = $loyaltyService->pointsBalanceForUser((int) $user->id);

            $loyaltyRecent = LoyaltyTransaction::query()
                ->where('user_id', $user->id)
                ->with('order:id,order_number')
                ->latest('id')
                ->limit(6)
                ->get();
        }

        return view($this->frontendView($request, 'account.dashboard'), [
            'user' => $user,
            'orders' => $orders,
            'loyaltyEnabled' => $loyaltyEnabled,
            'loyaltyBalance' => $loyaltyBalance,
            'loyaltyRecent' => $loyaltyRecent,
            'b2bAccount' => $user->b2bAccount,
        ]);
    }

    public function orders(Request $request): View
    {
        $request->user()->loadMissing('b2bAccount');

        $orders = Order::query()
            ->where('user_id', $request->user()->id)
            ->with('status:id,name,color')
            ->latest('id')
            ->paginate(15);

        return view($this->frontendView($request, 'account.orders'), [
            'orders' => $orders,
            'b2bAccount' => $request->user()->b2bAccount,
        ]);
    }

    public function showOrder(Request $request, string $orderNumber): View
    {
        $order = Order::query()
            ->where('user_id', $request->user()->id)
            ->where('order_number', $orderNumber)
            ->with([
                'status:id,name,color,sort_order,is_cancelled',
                'items.product:id,code,is_active',
                'items.product.media' => static function ($query): void {
                    $query->whereIn('collection_name', ['product_main', 'product_gallery'])
                        ->orderBy('order_column')
                        ->orderBy('id');
                },
                'items.product.translations:id,product_id,locale,slug,name',
                'items.productOptionValue.optionValue.translations:id,option_value_id,locale,name',
                'items.productOptionValue.parentOptionValue.translations:id,option_value_id,locale,name',
                'totals',
                'history.toStatus:id,name,color',
            ])
            ->firstOrFail();

        $statusSteps = OrderStatus::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get(['id', 'name', 'sort_order']);

        return view($this->frontendView($request, 'account.order-show'), [
            'order' => $order,
            'statusSteps' => $statusSteps,
            'b2bAccount' => $request->user()->loadMissing('b2bAccount')->b2bAccount,
        ]);
    }

    public function loyalty(Request $request): View
    {
        $user = $request->user();
        $settings = app(SystemSettingsService::class);

        abort_unless(app(LoyaltyService::class)->availableForUser($user), 404);

        $transactions = LoyaltyTransaction::query()
            ->where('user_id', $user->id)
            ->with('order:id,order_number')
            ->latest('id')
            ->paginate(20);

        $balance = (int) LoyaltyTransaction::query()
            ->where('user_id', $user->id)
            ->sum('points');

        $earned = (int) LoyaltyTransaction::query()
            ->where('user_id', $user->id)
            ->where('points', '>', 0)
            ->sum('points');

        $spent = abs((int) LoyaltyTransaction::query()
            ->where('user_id', $user->id)
            ->where('points', '<', 0)
            ->sum('points'));

        $minOrderTotal = (float) $settings->get(
            'loyalty_min_order_total',
            (float) config('user_features.loyalty.min_order_total', 0.0)
        );

        return view($this->frontendView($request, 'account.loyalty'), [
            'transactions' => $transactions,
            'balance' => $balance,
            'earned' => $earned,
            'spent' => $spent,
            'minOrderTotal' => $minOrderTotal,
        ]);
    }

    public function profile(Request $request): View
    {
        $user = $request->user();
        $user->loadMissing(['profile', 'addresses']);

        $billing = $user->addresses->firstWhere('type', UserAddress::TYPE_BILLING);
        $shipping = $user->addresses->firstWhere('type', UserAddress::TYPE_SHIPPING);
        $payload = is_array($user->profile?->payload) ? $user->profile->payload : [];
        $addressDirectory = app(AddressDirectoryService::class);

        return view($this->frontendView($request, 'account.profile'), [
            'user' => $user,
            'billing' => $billing,
            'shipping' => $shipping,
            'preferencePayload' => $payload,
            'countryOptions' => $addressDirectory->countries((string) app()->getLocale()),
            'placesAssetUrl' => $addressDirectory->placesAssetUrl(),
        ]);
    }

    public function updateProfile(Request $request): RedirectResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:191'],
            'email' => ['required', 'email', 'max:191', Rule::unique('users', 'email')->ignore($user->id)],
            'first_name' => ['nullable', 'string', 'max:120'],
            'last_name' => ['nullable', 'string', 'max:120'],
            'phone' => ['nullable', 'string', 'max:80'],
            'company' => ['nullable', 'string', 'max:191'],
            'oib' => ['nullable', 'string', 'max:60'],
            'birthday' => ['nullable', 'date'],
            'gender' => ['nullable', Rule::in(['male', 'female'])],
        ]);

        $user->forceFill([
            'name' => $validated['name'],
            'email' => $validated['email'],
        ])->save();

        UserProfile::query()->updateOrCreate(
            ['user_id' => $user->id],
            [
                'first_name' => $validated['first_name'] ?? null,
                'last_name' => $validated['last_name'] ?? null,
                'phone' => $validated['phone'] ?? null,
                'company' => $validated['company'] ?? null,
                'oib' => $validated['oib'] ?? null,
                'birthday' => $validated['birthday'] ?? null,
                'gender' => $validated['gender'] ?? null,
            ]
        );

        return back()->with('status', __('ui.account.status.profile_updated'));
    }

    public function updatePreferences(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'newsletter_opt_in' => ['nullable', 'boolean'],
            'gdpr_marketing_opt_in' => ['nullable', 'boolean'],
            'gdpr_personalization_opt_in' => ['nullable', 'boolean'],
        ]);

        $user = $request->user();
        $profile = UserProfile::query()->firstOrCreate(['user_id' => $user->id]);

        $payload = is_array($profile->payload) ? $profile->payload : [];
        $payload['gdpr_marketing_opt_in'] = (bool) ($validated['gdpr_marketing_opt_in'] ?? false);
        $payload['gdpr_personalization_opt_in'] = (bool) ($validated['gdpr_personalization_opt_in'] ?? false);

        $profile->forceFill([
            'newsletter_opt_in' => (bool) ($validated['newsletter_opt_in'] ?? false),
            'payload' => $payload,
        ])->save();

        return back()->with('status', __('ui.account.status.preferences_updated'));
    }

    public function updateAddress(Request $request, string $type): RedirectResponse
    {
        $validated = $request->validate([
            'first_name' => ['nullable', 'string', 'max:120'],
            'last_name' => ['nullable', 'string', 'max:120'],
            'company' => ['nullable', 'string', 'max:191'],
            'oib' => ['nullable', 'string', 'max:60'],
            'vat_id' => ['nullable', 'string', 'max:60'],
            'phone' => ['nullable', 'string', 'max:80'],
            'address_line_1' => ['required', 'string', 'max:191'],
            'address_line_2' => ['nullable', 'string', 'max:191'],
            'postal_code' => ['required', 'string', 'max:32'],
            'city' => ['required', 'string', 'max:120'],
            'country_code' => ['required', 'string', 'size:2'],
        ]);

        $userId = (int) $request->user()->id;
        $company = $type === UserAddress::TYPE_BILLING
            ? ($validated['company'] ?? null)
            : UserAddress::query()
                ->where('user_id', $userId)
                ->where('type', UserAddress::TYPE_BILLING)
                ->value('company');

        UserAddress::query()->updateOrCreate(
            ['user_id' => $userId, 'type' => $type],
            [
                'first_name' => $validated['first_name'] ?? null,
                'last_name' => $validated['last_name'] ?? null,
                'company' => $company,
                'oib' => $validated['oib'] ?? null,
                'vat_id' => $validated['vat_id'] ?? null,
                'phone' => $validated['phone'] ?? null,
                'address_line_1' => $validated['address_line_1'],
                'address_line_2' => $validated['address_line_2'] ?? null,
                'postal_code' => $validated['postal_code'],
                'city' => $validated['city'],
                'country_code' => strtoupper($validated['country_code']),
                'is_default' => true,
            ]
        );

        if ($type === UserAddress::TYPE_BILLING) {
            UserAddress::query()
                ->where('user_id', $userId)
                ->where('type', UserAddress::TYPE_SHIPPING)
                ->update(['company' => $company]);
        }

        return back()->with('status', __('ui.account.status.address_updated', [
            'type' => __('ui.account.address.types.'.$type),
        ]));
    }
}
