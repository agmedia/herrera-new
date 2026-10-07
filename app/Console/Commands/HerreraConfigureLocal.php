<?php

namespace App\Console\Commands;

use App\Models\Catalog\Category\Category;
use App\Models\Content\Page\InfoPage;
use App\Models\User;
use App\Services\Front\NavigationMenuService;
use App\Services\Import\HerreraCheckoutConfigurationService;
use App\Services\Settings\SystemSettingsService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Silber\Bouncer\BouncerFacade as Bouncer;

class HerreraConfigureLocal extends Command
{
    protected $signature = 'herrera:configure-local {--copy-admins : Preserve existing local superadmin sign-ins from herrera_new}';

    protected $description = 'Configure isolated Herrera preview branding and safe offline payment/email settings';

    public function handle(SystemSettingsService $settings): int
    {
        if (! app()->environment('local') || DB::connection()->getDatabaseName() !== 'herrera_new_migration') {
            $this->error('This preview configuration is restricted to the local herrera_new_migration database.');

            return self::FAILURE;
        }

        $source = DB::connection('herrera_source')->table('oc_setting')->where('store_id', 0)
            ->whereIn('key', ['config_name', 'config_meta_title', 'config_meta_description', 'config_email', 'config_telephone', 'config_address'])
            ->pluck('value', 'key');
        $categories = Category::query()->where('scope', Category::SCOPE_CATALOG)->currentlyVisible()
            ->whereNull('parent_id')->orderBy('sort_order')->get();
        $pages = InfoPage::query()->where('is_active', true)->orderBy('sort_order')->pluck('id')->all();
        $navigation = [
            ['type' => 'catalog', 'label' => 'Svi artikli', 'url' => '/shop', 'is_active' => true, 'show_dropdown' => true, 'sort_order' => 0],
            ['type' => 'custom', 'label' => 'Brandovi', 'url' => '/brendovi', 'is_active' => true, 'sort_order' => 1],
            ['type' => 'custom', 'label' => 'Akcije', 'url' => '/akcije', 'is_active' => true, 'sort_order' => 2],
        ];
        $aboutId = InfoPage::query()->where('is_active', true)
            ->whereHas('translations', fn ($q) => $q->where('locale', 'hr')->where('slug', 'o-nama'))->value('id');
        if ($aboutId) {
            $navigation[] = ['type' => 'page', 'label' => 'O nama', 'page_id' => $aboutId, 'is_active' => true, 'sort_order' => 3];
        }
        $navigation[] = ['type' => 'custom', 'label' => 'Kontakt', 'url' => '/contact', 'is_active' => true, 'sort_order' => 4];
        $contactLinks = [];
        $contactEmail = trim((string) $source->get('config_email', ''));
        $contactPhone = trim((string) $source->get('config_telephone', ''));
        if (filter_var($contactEmail, FILTER_VALIDATE_EMAIL)) {
            $contactLinks[] = ['label' => $contactEmail, 'url' => 'mailto:'.$contactEmail, 'is_active' => true, 'sort_order' => 0];
        }
        if ($contactPhone !== '') {
            $contactLinks[] = ['label' => $contactPhone, 'url' => 'tel:'.preg_replace('/[^+0-9]/', '', $contactPhone), 'is_active' => true, 'sort_order' => 1];
        }
        $settings->putMany([
            'store_brand_name' => 'Herrera', 'store_brand_logo_path' => 'assets/brand/herrera-logo.svg',
            'store_brand_logo_optimized_path' => '', 'store_brand_logo_width' => 190, 'store_brand_logo_height' => 70,
            'store_announcement_enabled' => true,
            'store_announcement_text' => 'Herrera · Nacionalni partner za veleprodaju i distribuciju',
            'store_announcement_background_color' => '#00B5CC', 'store_announcement_text_color' => '#ffffff',
            'store_benefits_bar_enabled' => false, 'store_newsletter_enabled' => true,
            'store_cookie_consent_policy_url' => '/page/pravila-privatnosti',
            'store_cookie_consent_policy_url_translations' => ['hr' => '/page/pravila-privatnosti', 'en' => '/page/pravila-privatnosti'],
            'store_newsletter_provider' => 'database', 'store_newsletter_coupon_enabled' => false,
            'store_newsletter_title' => 'Prijavite se na newsletter',
            'store_newsletter_subtitle' => 'Primajte novosti o asortimanu i B2B ponudi.',
            'store_newsletter_button_label' => 'Prijavi se',
            'store_newsletter_consent_label' => 'Pristajem na primanje newslettera i pohranu email adrese.',
            'store_legal_warranty_enabled' => true,
            'store_product_fit_finder_enabled' => false,
            'store_product_desktop_default_cols' => 5,
            'store_search_autocomplete_enabled' => true,
            'store_footer_phone' => (string) $source->get('config_telephone', ''),
            'store_footer_email_sales' => (string) $source->get('config_email', ''),
            'store_footer_email_support' => (string) $source->get('config_email', ''),
            'store_footer_contact_title' => 'Herrera · B2B podrška',
            'store_footer_contact_intro' => 'Informacije o proizvodima, suradnji i narudžbama.',
            'store_footer_address' => trim((string) $source->get('config_address', '')),
            'store_footer_col_1_title' => 'Proizvodi', 'store_footer_col_1_category_ids' => $categories->take(6)->pluck('id')->all(),
            'store_footer_col_2_title' => 'Informacije', 'store_footer_col_2_page_ids' => $pages,
            'store_footer_col_3_title' => 'B2B kupci',
            'store_footer_col_1_title_translations' => ['hr' => 'Proizvodi'],
            'store_footer_col_2_title_translations' => ['hr' => 'Informacije'],
            'store_footer_col_3_title_translations' => ['hr' => 'B2B kupci'],
            'store_footer_col_3_custom_links' => "Prijava|/auth/login\nRegistracija tvrtke|/auth/b2b-register\nKontakt|/contact",
            'store_footer_bottom_link_page_ids' => $pages,
            'store_footer_bottom_copyright_text' => 'Herrera · Sva prava pridržana.',
            'store_footer_social_facebook_enabled' => false, 'store_footer_social_instagram_enabled' => false,
            'store_footer_social_tiktok_enabled' => false, 'store_footer_social_youtube_enabled' => false,
            'store_seo_default_title' => (string) $source->get('config_meta_title', 'Herrera – Veleprodaja i distribucija'),
            'store_seo_default_description' => (string) $source->get('config_meta_description', ''),
            'store_seo_robots' => 'noindex,nofollow',
            'store_schema_enabled' => true, 'store_schema_org_name' => 'Herrera',
            'store_email_enabled' => false, 'store_analytics_enabled' => false,
            'store_captcha_recaptcha_v3_enabled' => false,
            'store_pricing_prices_include_tax' => false,
            'catalog_use_attributes' => true, 'catalog_use_options' => false,
            'catalog_use_manufacturers' => true, 'catalog_hide_out_of_stock_products' => false,
            'catalog_use_blog' => true,
            'msan_enabled' => false, 'msan_price_stock_sync_enabled' => false,
            NavigationMenuService::SETTINGS_KEY => $navigation,
            NavigationMenuService::TOP_BAR_SETTINGS_KEY => [
                'is_enabled' => $contactLinks !== [], 'height' => 34, 'font_size' => 12,
                'background_color' => '#32373B', 'text_color' => '#ffffff', 'border_color' => '#32373B',
                'links' => $contactLinks,
                'socials' => [
                    ['network' => 'facebook', 'url' => 'https://www.facebook.com/Braytron-Hrvatska-111496658683612', 'is_active' => true, 'sort_order' => 0],
                    ['network' => 'linkedin', 'url' => 'https://www.linkedin.com/company/braytron-hrvatska', 'is_active' => true, 'sort_order' => 1],
                    ['network' => 'twitter', 'url' => 'https://twitter.com/BraytronHr?t=L6x9MrI3_apFe5ezB73StA&s=08', 'is_active' => true, 'sort_order' => 2],
                ],
            ],
            NavigationMenuService::APPEARANCE_SETTINGS_KEY => [
                'container_width' => 1860, 'header_content_width' => 1860,
                'background_color' => '#00B5CC', 'text_color' => '#ffffff', 'highlight_color' => '#32373B',
            ],
        ]);
        app(HerreraCheckoutConfigurationService::class)->import();

        if ($this->option('copy-admins')) {
            $connection = config('database.connections.mysql');
            $connection['database'] = 'herrera_new';
            config(['database.connections.herrera_local_admin_source' => $connection]);
            $adminSource = DB::connection('herrera_local_admin_source');
            $admins = $adminSource->table('users')->whereIn('id', $adminSource->table('assigned_roles')
                ->join('roles', 'roles.id', '=', 'assigned_roles.role_id')->where('roles.name', 'superadmin')
                ->where('assigned_roles.entity_type', (new User)->getMorphClass())->select('assigned_roles.entity_id'))->get();
            foreach ($admins as $admin) {
                $user = User::query()->firstOrCreate(['email' => $admin->email], [
                    'name' => $admin->name, 'password' => $admin->password,
                ]);
                Bouncer::assign('superadmin')->to($user);
            }
            $this->info('Existing local superadmin access preserved: '.$admins->count());
        }
        $this->info('Herrera local preview configured. Outbound mail, live payments and supplier sync are disabled.');

        return self::SUCCESS;
    }
}
