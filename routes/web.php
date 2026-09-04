<?php

use App\Http\Controllers\Admin\AdminAiController;
use App\Http\Controllers\Admin\ContractWithdrawalController;
use App\Http\Controllers\Admin\MsanProductImageController;
use App\Http\Controllers\Admin\OrderGlsController;
use App\Http\Controllers\Admin\SystemToolsController;
use App\Http\Controllers\Feed\NabavaNetFeedController;
use App\Http\Controllers\Front\AccountController;
use App\Http\Controllers\Front\AuthController;
use App\Http\Controllers\Front\B2BController;
use App\Http\Controllers\Front\BlogController;
use App\Http\Controllers\Front\CartController;
use App\Http\Controllers\Front\CatalogController;
use App\Http\Controllers\Front\CheckoutController;
use App\Http\Controllers\Front\ContactController;
use App\Http\Controllers\Front\FaqController;
use App\Http\Controllers\Front\ManufacturerController;
use App\Http\Controllers\Front\NewsletterController;
use App\Http\Controllers\Front\PageController;
use App\Http\Controllers\Front\ProductController;
use App\Http\Controllers\Front\ReturnRequestController;
use App\Http\Controllers\Front\StorefrontController;
use App\Http\Controllers\Front\StorefrontStylesController;
use App\Http\Controllers\Front\WishlistController;
use App\Models\Catalog\Action\CatalogAction;
use App\Models\Catalog\Attribute\Attribute as CatalogAttribute;
use App\Models\Catalog\Attribute\AttributeGroup as CatalogAttributeGroup;
use App\Models\Catalog\Category\Category;
use App\Models\Catalog\Manufacturer\Manufacturer;
use App\Models\Catalog\Option\Option;
use App\Models\Catalog\Option\OptionValue;
use App\Models\Catalog\Pricing\B2BPriceRule;
use App\Models\Catalog\Product\Product;
use App\Models\Content\Blog\BlogPost;
use App\Models\Content\ContentBlock;
use App\Models\Content\ContentBlockSlot;
use App\Models\Content\Page\InfoPage;
use App\Models\Content\Support\Comment;
use App\Models\Content\Support\Faq;
use App\Models\Integrations\Msan\MsanSpecificationDefinition;
use App\Models\Sales\Order\Order as SalesOrder;
use App\Models\Settings\Local\Language;
use App\Models\Settings\Local\ShippingMethod;
use App\Models\User;
use App\Models\User\CustomerGroup;
use App\Models\User\LoyaltyTransaction;
use App\Models\User\UserTrackingEvent;
use App\Services\Loyalty\LoyaltyService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Spatie\Activitylog\Models\Activity;

Route::get('storefront-settings.css', StorefrontStylesController::class)
    ->name('front.storefront.styles');

Route::get('feeds/nabava.xml', NabavaNetFeedController::class)
    ->middleware('throttle:10,1')
    ->name('feeds.nabava');

Route::middleware(['front.locale', 'front.device'])
    ->group(function (): void {
        Route::get('locale/{code}', function (string $code, Request $request) {
            $fallback = strtolower((string) config('app.locale', 'en'));
            $target = strtolower(trim($code));

            try {
                $available = Language::query()
                    ->where('is_active', true)
                    ->pluck('code')
                    ->map(static fn ($item) => strtolower((string) $item))
                    ->values()
                    ->all();
            } catch (\Throwable) {
                $available = [$fallback];
            }

            if (! in_array($target, $available, true)) {
                $target = in_array($fallback, $available, true) ? $fallback : (string) ($available[0] ?? $fallback);
            }

            $request->session()->put('front_locale', $target);

            return redirect()->back();
        })->name('front.locale.switch');

        Route::get('/', [StorefrontController::class, 'home'])->name('home');

        Route::get('search/autocomplete', [CatalogController::class, 'autocomplete'])->name('search.autocomplete');
        Route::get('shop', [CatalogController::class, 'index'])->name('shop.index');
        Route::get('categories', [CatalogController::class, 'categories'])->name('categories.index');
        Route::get('category/{slug}', [CatalogController::class, 'showCategory'])->name('categories.show');
        Route::get('product/{slug}', [ProductController::class, 'show'])->name('products.show');
        Route::post('product/{slug}/comments', [ProductController::class, 'storeComment'])->name('products.comments.store');
        Route::post('product/fit-finder/preferences', [ProductController::class, 'storeFitFinderPreferences'])->name('products.fit_finder.preferences');

        Route::get('brendovi', [ManufacturerController::class, 'index'])->name('manufacturers.index');
        Route::redirect('brandovi', '/brendovi', 301);
        Route::redirect('manufacturers', '/brendovi', 301);
        Route::get('manufacturer/{slug}', [ManufacturerController::class, 'show'])->name('manufacturers.show');

        Route::get('blog', [BlogController::class, 'index'])->name('blog.index');
        Route::get('blog/{slug}', [BlogController::class, 'show'])->name('blog.show');
        Route::get('faq', [FaqController::class, 'index'])->name('faq.index');

        Route::get('pages/category/{slug}', [PageController::class, 'category'])->name('pages.category');
        Route::get('page/{slug}', [PageController::class, 'show'])->name('pages.show');

        Route::get('contact', [ContactController::class, 'create'])->name('contact.create');
        Route::post('contact', [ContactController::class, 'store'])->name('contact.store');
        Route::get('{returnRequestSlug}', [ReturnRequestController::class, 'create'])
            ->where('returnRequestSlug', 'forma-za-povrat-i-reklamacije|returns-and-claims|rucksendungen-und-reklamationen')
            ->name('returns.create');
        Route::post('{returnRequestSlug}', [ReturnRequestController::class, 'review'])
            ->middleware('throttle:10,1')
            ->where('returnRequestSlug', 'forma-za-povrat-i-reklamacije|returns-and-claims|rucksendungen-und-reklamationen')
            ->name('returns.review');
        Route::post('{returnRequestSlug}/confirm', [ReturnRequestController::class, 'store'])
            ->middleware('throttle:10,1')
            ->where('returnRequestSlug', 'forma-za-povrat-i-reklamacije|returns-and-claims|rucksendungen-und-reklamationen')
            ->name('returns.store');
        Route::post('newsletter/subscribe', [NewsletterController::class, 'store'])->name('newsletter.subscribe');

        Route::get('cart/preview', [CartController::class, 'preview'])->name('cart.preview');
        Route::get('cart', [CartController::class, 'index'])->name('cart.index');
        Route::post('cart/items', [CartController::class, 'store'])->name('cart.items.store');
        Route::patch('cart/items/{product}', [CartController::class, 'update'])->name('cart.items.update');
        Route::delete('cart/items/{product}', [CartController::class, 'destroy'])->name('cart.items.destroy');
        Route::post('cart/coupon', [CartController::class, 'applyCoupon'])->name('cart.coupon.apply');
        Route::delete('cart/coupon', [CartController::class, 'removeCoupon'])->name('cart.coupon.remove');
        Route::delete('cart', [CartController::class, 'clear'])->name('cart.clear');

        Route::get('wishlist', [WishlistController::class, 'index'])->name('wishlist.index');
        Route::post('wishlist/toggle/{product}', [WishlistController::class, 'toggle'])->name('wishlist.items.toggle');
        Route::post('wishlist/items/{product}', [WishlistController::class, 'store'])->name('wishlist.items.store');
        Route::delete('wishlist/items/{product}', [WishlistController::class, 'destroy'])->name('wishlist.items.destroy');

        Route::get('checkout', [CheckoutController::class, 'create'])->name('checkout.create');
        Route::get('checkout/options', [CheckoutController::class, 'options'])->name('checkout.options');
        Route::post('checkout/login', [CheckoutController::class, 'login'])->middleware('guest')->name('checkout.login');
        Route::post('checkout', [CheckoutController::class, 'store'])->name('checkout.store');
        Route::get('checkout/wspay/{orderNumber}', [CheckoutController::class, 'wspayStart'])
            ->where('orderNumber', '[A-Za-z0-9\-]+')
            ->name('checkout.wspay.start');
        Route::match(['GET', 'POST'], 'checkout/wspay/return/{orderNumber}', [CheckoutController::class, 'wspayReturn'])
            ->where('orderNumber', '[A-Za-z0-9\-]+')
            ->withoutMiddleware([\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class])
            ->name('checkout.wspay.return');
        Route::match(['GET', 'POST'], 'checkout/wspay/error/{orderNumber}', [CheckoutController::class, 'wspayError'])
            ->where('orderNumber', '[A-Za-z0-9\-]+')
            ->withoutMiddleware([\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class])
            ->name('checkout.wspay.error');
        Route::match(['GET', 'POST'], 'checkout/wspay/cancel/{orderNumber}', [CheckoutController::class, 'wspayCancel'])
            ->where('orderNumber', '[A-Za-z0-9\-]+')
            ->withoutMiddleware([\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class])
            ->name('checkout.wspay.cancel');
        Route::match(['GET', 'POST'], 'checkout/corvus/success', [CheckoutController::class, 'corvusSuccessStatic'])
            ->middleware('throttle:30,1')
            ->withoutMiddleware([\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class])
            ->name('checkout.corvus.success.static');
        Route::match(['GET', 'POST'], 'checkout/corvus/cancel', [CheckoutController::class, 'corvusCancelStatic'])
            ->middleware('throttle:30,1')
            ->withoutMiddleware([\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class])
            ->name('checkout.corvus.cancel.static');
        Route::match(['GET', 'POST'], 'checkout/corvus/success/{orderNumber}', [CheckoutController::class, 'corvusSuccess'])
            ->where('orderNumber', '[A-Za-z0-9\-]+')
            ->middleware('throttle:30,1')
            ->withoutMiddleware([\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class])
            ->name('checkout.corvus.success');
        Route::match(['GET', 'POST'], 'checkout/corvus/cancel/{orderNumber}', [CheckoutController::class, 'corvusCancel'])
            ->where('orderNumber', '[A-Za-z0-9\-]+')
            ->middleware('throttle:30,1')
            ->withoutMiddleware([\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class])
            ->name('checkout.corvus.cancel');
        Route::get('checkout/corvus/{orderNumber}', [CheckoutController::class, 'corvusStart'])
            ->where('orderNumber', '[A-Za-z0-9\-]+')
            ->name('checkout.corvus.start');
        Route::get('checkout/keks/{orderNumber}', [CheckoutController::class, 'keksStart'])
            ->where('orderNumber', '[A-Za-z0-9\-]+')
            ->name('checkout.keks.start');
        Route::match(['GET', 'POST'], 'checkout/keks/success', [CheckoutController::class, 'keksSuccess'])
            ->withoutMiddleware([\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class])
            ->name('checkout.keks.success');
        Route::match(['GET', 'POST'], 'checkout/keks/fail', [CheckoutController::class, 'keksFail'])
            ->withoutMiddleware([\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class])
            ->name('checkout.keks.fail');
        Route::match(['GET', 'POST'], 'checkout/keks/advice', [CheckoutController::class, 'keksAdvice'])
            ->withoutMiddleware([\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class])
            ->name('checkout.keks.advice');
        Route::get('checkout/success', [CheckoutController::class, 'successLatest'])->name('checkout.success.latest');
        Route::get('checkout/success/{orderNumber}', [CheckoutController::class, 'success'])
            ->where('orderNumber', '[A-Za-z0-9\-]+')
            ->name('checkout.success');

        Route::middleware('guest')->prefix('auth')->as('front.auth.')->group(function (): void {
            Route::get('login', [AuthController::class, 'showLogin'])->name('login');
            Route::post('login', [AuthController::class, 'login'])->name('login.store');
            Route::get('forgot-password', [AuthController::class, 'showForgotPassword'])->name('password.request');
            Route::post('forgot-password', [AuthController::class, 'sendPasswordResetLink'])
                ->middleware('throttle:5,1')
                ->name('password.email');
            Route::get('reset-password/{token}', [AuthController::class, 'showResetPassword'])->name('password.reset');
            Route::post('reset-password', [AuthController::class, 'resetPassword'])
                ->middleware('throttle:10,1')
                ->name('password.update');
            Route::get('register', [AuthController::class, 'showRegister'])->name('register');
            Route::post('register', [AuthController::class, 'register'])->name('register.store');
            Route::get('b2b-register', [AuthController::class, 'showB2BRegister'])->name('b2b-register');
            Route::post('b2b-register', [AuthController::class, 'registerB2B'])->name('b2b-register.store');
        });

        Route::middleware(['auth', 'verified'])
            ->prefix('account')
            ->as('account.')
            ->group(function (): void {
                Route::get('/', [AccountController::class, 'dashboard'])->name('dashboard');
                Route::get('orders', [AccountController::class, 'orders'])->name('orders');
                Route::get('orders/{orderNumber}', [AccountController::class, 'showOrder'])
                    ->where('orderNumber', '[A-Za-z0-9\-]+')
                    ->name('orders.show');
                Route::post('orders/{orderNumber}/reorder', [B2BController::class, 'reorder'])
                    ->where('orderNumber', '[A-Za-z0-9\-]+')
                    ->name('orders.reorder');
                Route::get('b2b/quick-order', [B2BController::class, 'quickOrder'])->name('b2b.quick-order');
                Route::get('b2b/frequent-products', [B2BController::class, 'frequentProducts'])
                    ->name('b2b.frequent-products');
                Route::get('b2b/favorite-products', [B2BController::class, 'favoriteProducts'])
                    ->name('b2b.favorite-products');
                Route::get('b2b/quick-order/search', [B2BController::class, 'searchQuickOrder'])
                    ->middleware('throttle:60,1')
                    ->name('b2b.quick-order.search');
                Route::put('b2b/quick-order/draft', [B2BController::class, 'syncQuickOrder'])
                    ->name('b2b.quick-order.draft');
                Route::post('b2b/quick-order', [B2BController::class, 'storeQuickOrder'])->name('b2b.quick-order.store');
                Route::get('loyalty', [AccountController::class, 'loyalty'])->name('loyalty');

                Route::get('profile', [AccountController::class, 'profile'])->name('profile');
                Route::put('profile', [AccountController::class, 'updateProfile'])->name('profile.update');
                Route::put('preferences', [AccountController::class, 'updatePreferences'])->name('preferences.update');
                Route::put('addresses/{type}', [AccountController::class, 'updateAddress'])
                    ->where('type', 'billing|shipping')
                    ->name('addresses.update');
            });
    });

Route::get('dashboard', function (Request $request) {
    $user = $request->user();

    if ($user && $user->b2bAccount()->exists()) {
        return redirect()->route('account.dashboard');
    }

    if ($user && $user->isA('customer')) {
        return redirect('/');
    }

    return redirect()->route('admin.dashboard');
})
    ->middleware(['auth', 'verified'])
    ->name('dashboard');

Route::redirect('admin/login', '/login');

Route::middleware(['admin.locale', 'auth', 'verified', 'admin.access', 'admin.maintenance-bypass', 'admin.ability'])
    ->prefix('admin')
    ->as('admin.')
    ->group(function (): void {
        Route::redirect('/', '/admin/dashboard');
        Route::view('dashboard', 'admin.dashboard')->name('dashboard');
        Route::view('help', 'admin.help.index')->name('help.index');
        Route::view('categories', 'admin.categories')->name('categories');
        Route::view('categories/create', 'admin.categories.create')->name('categories.create');
        Route::get('categories/{category}/edit', function (Category $category) {
            return view('admin.categories.edit', compact('category'));
        })->name('categories.edit');
        Route::view('products', 'admin.products')->name('products');
        Route::view('products/create', 'admin.products.create')->name('products.create');
        Route::get('products/{product}/edit', function (Product $product) {
            return view('admin.products.edit', compact('product'));
        })->name('products.edit');
        Route::view('orders', 'admin.orders.index')->name('orders');
        Route::get('withdrawals', [ContractWithdrawalController::class, 'index'])->name('withdrawals.index');
        Route::get('withdrawals/{withdrawal}', [ContractWithdrawalController::class, 'show'])->name('withdrawals.show');
        Route::patch('withdrawals/{withdrawal}', [ContractWithdrawalController::class, 'update'])->name('withdrawals.update');
        Route::post('withdrawals/{withdrawal}/resend', [ContractWithdrawalController::class, 'resend'])->name('withdrawals.resend');
        Route::get('shipping', function (Request $request) {
            $legacyEditId = (int) $request->query('edit', 0);

            if ($legacyEditId > 0) {
                $editParameters = array_filter([
                    'shippingMethod' => $legacyEditId,
                    'search' => trim((string) $request->query('search', '')) ?: null,
                    'page' => (int) $request->query('page', 1) > 1 ? (int) $request->query('page') : null,
                ], static fn (int|string|null $value): bool => $value !== null);

                return redirect()->route('admin.shipping.edit', $editParameters);
            }

            return view('admin.shipping.index');
        })->name('shipping.index');
        Route::view('shipping/create', 'admin.shipping.create')->name('shipping.create');
        Route::get('shipping/{shippingMethod}/edit', function (ShippingMethod $shippingMethod) {
            return view('admin.shipping.edit', compact('shippingMethod'));
        })->name('shipping.edit');
        Route::post('orders/{order}/gls/send', [OrderGlsController::class, 'send'])->name('orders.gls.send');
        Route::get('orders/{order}/gls/label', [OrderGlsController::class, 'label'])->name('orders.gls.label');
        Route::get('orders/{order}/show', function (SalesOrder $order) {
            return view('admin.orders.show', compact('order'));
        })->name('orders.show');
        Route::get('orders/{order}/invoice', function (SalesOrder $order) {
            $order->load([
                'status:id,code,name,color',
                'items',
                'totals',
            ]);

            return view('admin.orders.invoice', compact('order'));
        })->name('orders.invoice');
        Route::middleware('catalog.feature:catalog_use_options')->group(function (): void {
            Route::get('products/{product}/options', function (Product $product) {
                return view('admin.products.options', compact('product'));
            })->name('products.options');
        });
        Route::middleware('catalog.feature:catalog_use_options')->group(function (): void {
            Route::view('options', 'admin.options')->name('options');
            Route::view('options/create', 'admin.options.create')->name('options.create');
            Route::get('options/{option}/edit', function (Option $option) {
                return view('admin.options.edit', compact('option'));
            })->name('options.edit');
            Route::get('options/{option}/values', function (Option $option) {
                return view('admin.options.values', compact('option'));
            })->name('options.values');
            Route::get('options/{option}/values/create', function (Option $option) {
                return view('admin.options.value-create', compact('option'));
            })->name('options.values.create');
            Route::get('options/{option}/values/{value}/edit', function (Option $option, OptionValue $value) {
                abort_unless((int) $value->option_id === (int) $option->id, 404);

                return view('admin.options.value-edit', compact('option', 'value'));
            })->name('options.values.edit');
        });
        Route::middleware('catalog.feature:catalog_use_attributes')->group(function (): void {
            Route::view('attributes', 'admin.attributes')->name('attributes');

            Route::view('attributes/groups/create', 'admin.attributes.group-create')
                ->name('attributes.groups.create');
            Route::get('attributes/groups/{attributeGroup}', function (CatalogAttributeGroup $attributeGroup) {
                return view('admin.attributes.group-show', compact('attributeGroup'));
            })->name('attributes.groups.show');
            Route::get('attributes/groups/{attributeGroup}/edit', function (CatalogAttributeGroup $attributeGroup) {
                return view('admin.attributes.group-edit', compact('attributeGroup'));
            })->name('attributes.groups.edit');
            Route::get('attributes/groups/{attributeGroup}/attributes/create', function (CatalogAttributeGroup $attributeGroup) {
                return view('admin.attributes.attribute-create', compact('attributeGroup'));
            })->name('attributes.groups.attributes.create');
            Route::get('attributes/groups/{attributeGroup}/attributes/{attribute}/edit', function (
                CatalogAttributeGroup $attributeGroup,
                CatalogAttribute $attribute,
            ) {
                abort_unless((int) $attribute->attribute_group_id === (int) $attributeGroup->id, 404);

                return view('admin.attributes.attribute-edit', compact('attributeGroup', 'attribute'));
            })->name('attributes.groups.attributes.edit');

            Route::get('attributes/create', function (Request $request) {
                return redirect()->route('admin.attributes.groups.create', $request->only('locale'));
            })->name('attributes.create');
            Route::get('attributes/{attribute}/edit', function (CatalogAttribute $attribute) {
                $group = $attribute->group
                    ?? CatalogAttributeGroup::query()->where('code', $attribute->group_code)->firstOrFail();

                return redirect()->route('admin.attributes.groups.attributes.edit', [
                    'attributeGroup' => $group,
                    'attribute' => $attribute,
                    'locale' => request()->query('locale'),
                ]);
            })->name('attributes.edit');
        });
        Route::middleware('catalog.feature:catalog_use_manufacturers')->group(function (): void {
            Route::view('manufacturers', 'admin.manufacturers')->name('manufacturers');
            Route::view('manufacturers/create', 'admin.manufacturers.create')->name('manufacturers.create');
            Route::get('manufacturers/{manufacturer}/edit', function (Manufacturer $manufacturer) {
                return view('admin.manufacturers.edit', compact('manufacturer'));
            })->name('manufacturers.edit');
        });
        Route::middleware('catalog.feature:catalog_use_actions')->group(function (): void {
            Route::view('actions', 'admin.actions')->name('actions');
            Route::view('actions/create', 'admin.actions.create')->name('actions.create');
            Route::get('actions/{action}/edit', function (CatalogAction $action) {
                return view('admin.actions.edit', compact('action'));
            })->name('actions.edit');
        });
        Route::view('b2b-prices', 'admin.b2b-prices')->name('b2b-prices');
        Route::view('b2b-prices/create', 'admin.b2b-prices.create')->name('b2b-prices.create');
        Route::get('b2b-prices/{rule}/edit', function (B2BPriceRule $rule) {
            return view('admin.b2b-prices.edit', compact('rule'));
        })->name('b2b-prices.edit');
        Route::view('users', 'admin.users.index')->name('users');
        Route::view('users/b2b', 'admin.users.b2b')->name('users.b2b');
        Route::view('users/newsletter', 'admin.users.newsletter')->name('users.newsletter');
        Route::view('users/groups', 'admin.users.groups')->name('users.groups');
        Route::view('users/groups/create', 'admin.users.group-create')->name('users.groups.create');
        Route::get('users/groups/{customerGroup}/edit', function (CustomerGroup $customerGroup) {
            return view('admin.users.group-edit', compact('customerGroup'));
        })->name('users.groups.edit');
        Route::view('users/access', 'admin.users.access')->name('users.access');
        Route::view('users/activity', 'admin.users.activity')->name('users.activity');
        Route::middleware('user.feature:user_loyalty_enabled')->group(function (): void {
            Route::view('users/loyalty', 'admin.users.loyalty')->name('users.loyalty');
        });
        Route::get('users/{user}/show', function (User $user) {
            $current = auth()->user();
            abort_unless($current && ($current->isA('superadmin') || $current->can('users.list.view')), 403);

            $user->load([
                'roles:id,name,title',
                'profile',
                'addresses',
                'customerGroups:id,name,is_active',
                'b2bAccount.customerGroup:id,name,code',
            ]);
            $adminActivity = Activity::query()
                ->where('subject_type', User::class)
                ->where('subject_id', $user->id)
                ->latest('id')
                ->limit(12)
                ->get();
            $trackingEvents = UserTrackingEvent::query()
                ->where('user_id', $user->id)
                ->latest('occurred_at')
                ->limit(12)
                ->get();

            $loyaltyEnabled = app(LoyaltyService::class)->availableForUser($user);

            $loyaltyStats = [
                'balance' => 0,
                'entries' => 0,
                'earned' => 0,
                'spent' => 0,
            ];
            $loyaltyEntries = collect();
            $recentOrders = collect();

            if ($loyaltyEnabled) {
                $loyaltyQuery = LoyaltyTransaction::query()->where('user_id', $user->id);
                $loyaltyStats = [
                    'balance' => (int) (clone $loyaltyQuery)->sum('points'),
                    'entries' => (int) (clone $loyaltyQuery)->count(),
                    'earned' => (int) (clone $loyaltyQuery)->where('points', '>', 0)->sum('points'),
                    'spent' => abs((int) (clone $loyaltyQuery)->where('points', '<', 0)->sum('points')),
                ];
                $loyaltyEntries = (clone $loyaltyQuery)
                    ->with(['order:id,order_number,grand_total,currency_code', 'creator:id,name'])
                    ->latest('id')
                    ->limit(10)
                    ->get();
            }

            $recentOrders = SalesOrder::query()
                ->where('user_id', $user->id)
                ->with('status:id,name,color')
                ->latest('id')
                ->limit(10)
                ->get(['id', 'order_number', 'status_id', 'grand_total', 'currency_code', 'created_at']);

            return view('admin.users.show', compact(
                'user',
                'adminActivity',
                'trackingEvents',
                'loyaltyEnabled',
                'loyaltyStats',
                'loyaltyEntries',
                'recentOrders'
            ));
        })->name('users.show');
        Route::get('users/{user}/edit', function (User $user) {
            return view('admin.users.edit', compact('user'));
        })->name('users.edit');
        Route::view('profile', 'profile')->name('profile');

        Route::prefix('integrations')
            ->as('integrations.')
            ->group(function (): void {
                Route::prefix('msan')
                    ->as('msan.')
                    ->group(function (): void {
                        Route::view('/', 'admin.integrations.msan.overview')->name('overview');
                        Route::view('settings', 'admin.integrations.msan.settings')->name('settings');
                        Route::view('categories', 'admin.integrations.msan.categories')->name('categories');
                        Route::view('specifications', 'admin.integrations.msan.specifications')->name('specifications');
                        Route::get('specifications/{definition}/edit', function (MsanSpecificationDefinition $definition) {
                            return view('admin.integrations.msan.specification-edit', compact('definition'));
                        })->name('specifications.edit');
                        Route::get('products/{product}/image', MsanProductImageController::class)
                            ->whereNumber('product')
                            ->middleware('throttle:120,1')
                            ->name('products.image');
                        Route::view('products', 'admin.integrations.msan.products')->name('products');
                        Route::view('runs', 'admin.integrations.msan.runs')->name('runs');
                    });
            });

        Route::prefix('content')
            ->as('content.')
            ->group(function (): void {
                Route::redirect('/', '/admin/content/blocks')->name('index');
                Route::middleware('catalog.feature:catalog_use_blog')->group(function (): void {
                    Route::view('blog', 'admin.content.blog.index')->name('blog.index');
                    Route::view('blog/create', 'admin.content.blog.create')->name('blog.create');
                    Route::get('blog/{post}/edit', function (BlogPost $post) {
                        return view('admin.content.blog.edit', compact('post'));
                    })->name('blog.edit');
                });
                Route::view('pages', 'admin.content.pages.index')->name('pages.index');
                Route::view('pages/create', 'admin.content.pages.create')->name('pages.create');
                Route::get('pages/{page}/edit', function (InfoPage $page) {
                    return view('admin.content.pages.edit', compact('page'));
                })->name('pages.edit');
                Route::view('faqs', 'admin.content.faqs.index')->name('faqs.index');
                Route::view('faqs/create', 'admin.content.faqs.create')->name('faqs.create');
                Route::get('faqs/{faq}/edit', function (Faq $faq) {
                    return view('admin.content.faqs.edit', compact('faq'));
                })->name('faqs.edit');
                Route::view('comments', 'admin.content.comments.index')->name('comments.index');
                Route::get('comments/{comment}/edit', function (Comment $comment) {
                    abort_if($comment->trashed(), 404);

                    return view('admin.content.comments.edit', compact('comment'));
                })->name('comments.edit');
                Route::view('blocks', 'admin.content.blocks.index')->name('blocks');
                Route::view('blocks/create', 'admin.content.blocks.create')->name('blocks.create');
                Route::get('blocks/{block}/edit', function (ContentBlock $block) {
                    return view('admin.content.blocks.edit', compact('block'));
                })->name('blocks.edit');
                Route::view('navigation', 'admin.content.navigation.index')->name('navigation');

                Route::view('slots', 'admin.content.slots.index')->name('slots');
                Route::view('slots/create', 'admin.content.slots.create')->name('slots.create');
                Route::get('slots/{slot}/edit', function (ContentBlockSlot $slot) {
                    return view('admin.content.slots.edit', compact('slot'));
                })->name('slots.edit');
            });

        Route::prefix('settings')
            ->as('settings.')
            ->group(function (): void {
                Route::redirect('/', '/admin/settings/local/payment-methods')->name('index');
                Route::redirect('local/shipping-methods', '/admin/shipping')->name('local.shipping-redirect');

                Route::get('local/{resource}', function (string $resource) {
                    return view('admin.settings.local.resource', compact('resource'));
                })
                    ->where('resource', 'payment-methods|shipping-methods|geo-zones|geo-zone-countries|regions|currencies|tax-rates|order-statuses|languages')
                    ->name('local.resource');

                Route::get('local/{resource}/create', function (string $resource) {
                    return view('admin.settings.local.create', compact('resource'));
                })
                    ->where('resource', 'payment-methods|geo-zones|geo-zone-countries|regions|currencies|tax-rates|order-statuses|languages')
                    ->name('local.resource.create');

                Route::get('local/{resource}/{record}/edit', function (string $resource, string $record) {
                    return view('admin.settings.local.edit', [
                        'resource' => $resource,
                        'recordId' => (int) $record,
                    ]);
                })
                    ->where('resource', 'payment-methods|geo-zones|geo-zone-countries|regions|currencies|tax-rates|order-statuses|languages')
                    ->whereNumber('record')
                    ->name('local.resource.edit');

                Route::get('system/runtime', function () {
                    $current = auth()->user();
                    abort_unless(
                        $current && ($current->isA('superadmin') || $current->can('settings.system.runtime.manage')),
                        403
                    );

                    return view('admin.settings.system.runtime');
                })->name('system.runtime');
                Route::view('system/admin-appearance-controls', 'admin.settings.system.admin-appearance-controls')->name('system.admin-appearance-controls');
                Route::view('system/catalog-features', 'admin.settings.system.catalog-features')->name('system.catalog-features');
                Route::view('system/store-settings', 'admin.settings.system.store-settings')->name('system.store-settings');
                Route::view('system/withdrawal-settings', 'admin.settings.system.withdrawal-settings')->name('system.withdrawal-settings');
                Route::prefix('api')
                    ->as('api.')
                    ->group(function (): void {
                        Route::get('/', function () {
                            $current = auth()->user();
                            abort_unless(
                                $current && ($current->isA('superadmin') || $current->can('settings.api.manage')),
                                403
                            );

                            $features = app(\App\Services\Catalog\CatalogFeatureService::class);
                            $catalogUseApi = $features->useApi();

                            if ($catalogUseApi) {
                                return redirect()->route('admin.settings.api.wholesale');
                            }

                            return redirect()->route('admin.settings.system.catalog-features')->with('notify', [
                                'type' => 'warning',
                                'message' => 'Wholesale API is disabled in Catalog Features.',
                            ]);
                        })->name('index');

                        Route::middleware('catalog.feature:catalog_use_api')->get('wholesale', function () {
                            $current = auth()->user();
                            abort_unless(
                                $current && ($current->isA('superadmin') || $current->can('settings.api.manage')),
                                403
                            );

                            return view('admin.settings.api.wholesale');
                        })->name('wholesale');

                    });
                Route::view('user', 'admin.settings.user.index')->name('user.index');
            });

        Route::prefix('system')
            ->as('system.')
            ->group(function (): void {
                Route::post('cache/clear', [SystemToolsController::class, 'clearCache'])->name('cache.clear');
                Route::post('maintenance/on', [SystemToolsController::class, 'maintenanceOn'])->name('maintenance.on');
                Route::post('maintenance/off', [SystemToolsController::class, 'maintenanceOff'])->name('maintenance.off');
            });

        Route::prefix('ai')
            ->as('ai.')
            ->group(function (): void {
                Route::post('preview', [AdminAiController::class, 'preview'])->name('preview');
                Route::post('execute', [AdminAiController::class, 'execute'])->name('execute');
            });
    });

Route::redirect('profile', '/admin/profile')
    ->middleware(['auth', 'verified'])
    ->name('profile');

Route::post('logout', function (Request $request) {
    Auth::guard('web')->logout();

    $request->session()->invalidate();
    $request->session()->regenerateToken();

    return redirect('/');
})->middleware('auth')->name('logout');

require __DIR__.'/auth.php';
