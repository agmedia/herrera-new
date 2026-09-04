<?php

namespace App\Providers;

use App\Models\Catalog\Product\Product;
use App\Models\Catalog\Product\ProductGroupPrice;
use App\Models\Catalog\Product\ProductOptionValue;
use App\Models\Content\ContentBlock;
use App\Models\Content\ContentBlockSlot;
use App\Models\Content\ContentBlockTranslation;
use App\Models\Sales\Order\Order;
use App\Models\Settings\Local\Currency;
use App\Models\Settings\Local\GeoZone;
use App\Models\Settings\Local\GeoZoneCountry;
use App\Models\Settings\Local\Language;
use App\Models\Settings\Local\OrderStatus;
use App\Models\Settings\Local\PaymentMethod;
use App\Models\Settings\Local\ShippingMethod;
use App\Models\Settings\Local\TaxRate;
use App\Observers\Catalog\ProductGroupPriceObserver;
use App\Observers\Catalog\ProductOptionValuePriceObserver;
use App\Observers\Catalog\ProductPriceObserver;
use App\Observers\Content\ContentCacheObserver;
use App\Observers\Sales\OrderLoyaltyObserver;
use App\Observers\Settings\LocalSettingObserver;
use App\Services\Catalog\CatalogFeatureService;
use App\Services\Content\ContentBlockResolver;
use App\Services\Front\NavigationMenuService;
use App\Services\Front\StoreSettingsService;
use App\Services\Front\WishlistService;
use App\Services\Loyalty\LoyaltyService;
use App\Services\Settings\LocalSettingsService;
use App\Services\Settings\SystemSettingsService;
use App\Services\UserTracking\UserTrackingService;
use App\Support\AssetVersion;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(LocalSettingsService::class, fn () => new LocalSettingsService);
        $this->app->singleton(SystemSettingsService::class, fn () => new SystemSettingsService);
        $this->app->singleton(CatalogFeatureService::class, fn ($app) => new CatalogFeatureService($app->make(SystemSettingsService::class)));
        $this->app->singleton(ContentBlockResolver::class, fn () => new ContentBlockResolver);
        $this->app->singleton(NavigationMenuService::class, fn ($app) => new NavigationMenuService($app->make(SystemSettingsService::class)));
        $this->app->singleton(StoreSettingsService::class, fn ($app) => new StoreSettingsService($app->make(SystemSettingsService::class)));
        $this->app->singleton(UserTrackingService::class, fn ($app) => new UserTrackingService($app->make(SystemSettingsService::class)));
        $this->app->singleton(LoyaltyService::class, fn ($app) => new LoyaltyService($app->make(SystemSettingsService::class)));
        $this->app->scoped(AssetVersion::class, fn () => new AssetVersion);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Queue::before(static function (JobProcessing $event): void {
            app(SystemSettingsService::class)->clearRuntimeCache();
        });

        $this->syncAppLocaleFromLocalSettings();

        if ((bool) config('app.force_https', false)) {
            URL::forceScheme('https');
        }

        Vite::createAssetPathsUsing(static function (string $path, ?bool $secure = null): string {
            $url = asset($path, $secure);
            $separator = str_contains($url, '?') ? '&' : '?';

            return $url.$separator.'v='.rawurlencode(app(AssetVersion::class)->current());
        });

        Livewire::addPersistentMiddleware([
            \App\Http\Middleware\EnsureAdminAbility::class,
        ]);

        $this->applyDynamicStoreMailSettings();

        ResetPassword::createUrlUsing(static function (object $notifiable, string $token): string {
            return route('front.auth.password.reset', [
                'token' => $token,
                'email' => $notifiable->getEmailForPasswordReset(),
            ]);
        });

        RateLimiter::for('wholesale-api', static function (Request $request) {
            $perMinute = max(30, (int) env('WHOLESALE_API_RATE_LIMIT', 240));
            $key = (string) ($request->user()?->id ?: $request->ip());

            return [
                Limit::perMinute($perMinute)->by('wholesale:'.$key),
            ];
        });

        View::composer('livewire.admin.*', static function ($view): void {
            static $localeOptions = null;

            if ($localeOptions === null) {
                try {
                    $configuredLocale = strtolower(trim((string) config('app.locale', 'en')));
                    $databaseLocales = Language::query()
                        ->orderByDesc('is_default')
                        ->orderBy('sort_order')
                        ->orderBy('code')
                        ->pluck('code')
                        ->filter(fn ($code) => is_string($code) && trim($code) !== '')
                        ->map(fn ($code) => strtolower(trim((string) $code)))
                        ->unique()
                        ->values()
                        ->all();
                    $localeOptions = array_values(array_unique(array_filter([
                        $configuredLocale,
                        ...$databaseLocales,
                    ])));
                } catch (\Throwable) {
                    $localeOptions = [];
                }

                if ($localeOptions === []) {
                    $localeOptions = [strtolower((string) config('app.locale', 'en'))];
                }
            }

            $view->with('adminLocaleOptions', $localeOptions);
        });

        View::composer('front.*', static function ($view): void {
            static $shared = null;

            if ($shared !== null) {
                $view->with('wishlistProductMap', $shared['wishlistProductMap']);
                $view->with('wishlistSummary', $shared['wishlistSummary']);
                $view->with('storeSettings', $shared['storeSettings']);

                return;
            }

            try {
                $wishlist = app(WishlistService::class);
                $shared = [
                    'wishlistProductMap' => $wishlist->map(),
                    'wishlistSummary' => $wishlist->summary(),
                    'storeSettings' => app(StoreSettingsService::class)->all(),
                ];
            } catch (\Throwable) {
                $shared = [
                    'wishlistProductMap' => [],
                    'wishlistSummary' => ['item_count' => 0],
                    'storeSettings' => [
                        'announcement' => [
                            'enabled' => true,
                            'text' => (string) __('ui.front.desktop.promo_bar'),
                            'url' => '',
                            'new_tab' => false,
                            'scroll_enabled' => false,
                            'scroll_duration_seconds' => 18,
                            'background_color' => '#000000',
                            'text_color' => '#ffffff',
                        ],
                        'images' => [
                            'use_webp' => true,
                        ],
                        'cookies' => [
                            'enabled' => true,
                            'title' => 'Koristimo kolačiće',
                            'message' => 'Koristimo kolačiće za ispravan rad sajta i bolje korisničko iskustvo.',
                            'accept_label' => 'U redu',
                            'policy_label' => 'Politika kolačića',
                            'policy_url' => '',
                            'preferences_title' => 'Postavke kolačića',
                            'preferences_accept_all_label' => 'Prihvati sve',
                            'preferences_accept_necessary_label' => 'Samo nužni',
                            'preferences_save_label' => 'Spremi odabir',
                            'necessary_title' => 'Nužni kolačići',
                            'necessary_description' => 'Neki kolačići na ovoj internetskoj stranici neophodni su za pravilno funkcioniranje stranice stoga ih nije moguće onemogućiti.',
                            'analytics_title' => 'Analitički kolačići',
                            'analytics_description' => 'Analitički kolačići nam pomažu kako bismo poboljšali našu internetsku stranicu sakupljajući i analizirajući podatke o njenoj posjećenosti.',
                            'marketing_title' => 'Marketinški kolačići',
                            'marketing_description' => 'Marketinški kolačići služe za praćenje posjetitelja u korištenju internet stranice u svrhu omogućavanja prikazivanja relevantnih oglasa oglašivača trećih strana.',
                        ],
                        'branding' => [
                            'store_name' => (string) config('app.name', 'AG Shop'),
                            'logo_url' => null,
                            'favicon_url' => null,
                            'favicons' => [
                                'ico_url' => null,
                                '16_url' => null,
                                '32_url' => null,
                                '180_url' => null,
                                '192_url' => null,
                                '512_url' => null,
                            ],
                            'social' => [
                                'facebook' => ['url' => '', 'enabled' => true],
                                'instagram' => ['url' => '', 'enabled' => true],
                                'tiktok' => ['url' => '', 'enabled' => true],
                                'youtube' => ['url' => '', 'enabled' => true],
                            ],
                        ],
                        'footer' => [
                            'phone' => '',
                            'email_sales' => '',
                            'email_support' => '',
                            'hours' => '',
                            'link_columns' => [
                                ['title' => (string) __('ui.front.desktop.footer.shop'), 'links' => []],
                                ['title' => (string) __('ui.front.desktop.footer.help'), 'links' => []],
                                ['title' => (string) __('ui.front.desktop.footer.info'), 'links' => []],
                            ],
                            'bottom_links' => [],
                            'bottom_copyright_text' => '',
                        ],
                        'newsletter' => ['provider' => 'none'],
                        'captcha' => [
                            'recaptcha_v3_enabled' => false,
                            'recaptcha_v3_site_key' => '',
                            'recaptcha_v3_secret_key' => '',
                            'recaptcha_v3_min_score' => 0.5,
                        ],
                        'analytics' => [
                            'enabled' => false,
                            'ga4_measurement_id' => '',
                            'purchase_event_enabled' => true,
                            'purchase_event_name' => 'purchase',
                        ],
                        'email' => ['enabled' => false],
                        'seo' => [
                            'default_title' => (string) config('app.name', 'AG Shop'),
                            'default_description' => '',
                            'robots' => 'index,follow',
                            'canonical_policy' => 'self',
                        ],
                        'og' => [
                            'default_image_url' => null,
                            'home_image_url' => null,
                            'category_image_url' => null,
                            'product_image_url' => null,
                            'page_image_url' => null,
                            'blog_image_url' => null,
                        ],
                        'schema' => [
                            'enabled' => true,
                            'org_enabled' => true,
                            'website_enabled' => true,
                            'breadcrumbs_enabled' => true,
                            'itemlist_enabled' => true,
                            'home_enabled' => true,
                            'category_enabled' => true,
                            'product_enabled' => true,
                            'blog_enabled' => true,
                            'page_enabled' => true,
                            'faq_enabled' => true,
                            'org_type' => 'Organization',
                            'business_name' => (string) config('app.name', 'AG Shop'),
                            'business_phone' => '',
                            'business_email' => '',
                            'address_street' => '',
                            'address_city' => '',
                            'address_region' => '',
                            'address_postal_code' => '',
                            'address_country' => 'HR',
                            'same_as' => '',
                            'blog_author_name' => '',
                            'blog_author_url' => '',
                            'product_currency' => 'EUR',
                            'faq_group' => '',
                            'faq_limit' => 8,
                            'itemlist_limit' => 12,
                        ],
                    ],
                ];
            }

            $view->with('wishlistProductMap', $shared['wishlistProductMap']);
            $view->with('wishlistSummary', $shared['wishlistSummary']);
            $view->with('storeSettings', $shared['storeSettings']);
        });

        PaymentMethod::observe(LocalSettingObserver::class);
        ShippingMethod::observe(LocalSettingObserver::class);
        GeoZone::observe(LocalSettingObserver::class);
        GeoZoneCountry::observe(LocalSettingObserver::class);
        Currency::observe(LocalSettingObserver::class);
        TaxRate::observe(LocalSettingObserver::class);
        OrderStatus::observe(LocalSettingObserver::class);
        Language::observe(LocalSettingObserver::class);
        Order::observe(OrderLoyaltyObserver::class);

        ContentBlock::observe(ContentCacheObserver::class);
        ContentBlockTranslation::observe(ContentCacheObserver::class);
        ContentBlockSlot::observe(ContentCacheObserver::class);

        Product::observe(ProductPriceObserver::class);
        ProductGroupPrice::observe(ProductGroupPriceObserver::class);
        ProductOptionValue::observe(ProductOptionValuePriceObserver::class);
    }

    private function syncAppLocaleFromLocalSettings(): void
    {
        $fallbackLocale = strtolower(trim((string) config('app.locale', 'en'))) ?: 'en';

        try {
            $defaultLocale = Language::query()
                ->where('is_active', true)
                ->orderByDesc('is_default')
                ->orderBy('sort_order')
                ->orderBy('code')
                ->value('code');

            $defaultLocale = strtolower(trim((string) $defaultLocale));
            if ($defaultLocale === '') {
                $defaultLocale = $fallbackLocale;
            }
        } catch (\Throwable) {
            $defaultLocale = $fallbackLocale;
        }

        Config::set('app.locale', $defaultLocale);
        Config::set('app.fallback_locale', $defaultLocale);
        App::setLocale($defaultLocale);
    }

    private function applyDynamicStoreMailSettings(): void
    {
        try {
            $settings = app(StoreSettingsService::class)->email();
            if (! (bool) ($settings['enabled'] ?? false)) {
                return;
            }

            $mailer = (string) ($settings['mailer'] ?? 'smtp');
            Config::set('mail.default', $mailer);

            if ($mailer === 'smtp') {
                Config::set('mail.mailers.smtp.host', (string) ($settings['host'] ?? ''));
                Config::set('mail.mailers.smtp.port', (int) ($settings['port'] ?? 587));
                Config::set('mail.mailers.smtp.username', (string) ($settings['username'] ?? ''));
                Config::set('mail.mailers.smtp.password', (string) ($settings['password'] ?? ''));
                Config::set('mail.mailers.smtp.encryption', (string) ($settings['encryption'] ?? ''));
            }

            if ($mailer === 'sendmail') {
                Config::set('mail.mailers.sendmail.path', (string) ($settings['sendmail_path'] ?? '/usr/sbin/sendmail -bs -i'));
            }

            $fromAddress = trim((string) ($settings['from_address'] ?? ''));
            if ($fromAddress !== '') {
                Config::set('mail.from.address', $fromAddress);
            }
            $fromName = trim((string) ($settings['from_name'] ?? ''));
            if ($fromName !== '') {
                Config::set('mail.from.name', $fromName);
            }
            $replyTo = trim((string) ($settings['reply_to'] ?? ''));
            if ($replyTo !== '') {
                Config::set('mail.reply_to.address', $replyTo);
                Config::set('mail.reply_to.name', $fromName !== '' ? $fromName : (string) config('mail.from.name', ''));
            }
        } catch (\Throwable) {
            // Do not break app boot if settings table is not ready.
        }
    }
}
