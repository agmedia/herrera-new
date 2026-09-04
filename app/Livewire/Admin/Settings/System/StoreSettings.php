<?php

namespace App\Livewire\Admin\Settings\System;

use App\Jobs\GenerateWebpConversionsJob;
use App\Models\Catalog\Attribute\Attribute;
use App\Models\Catalog\Category\Category;
use App\Models\Catalog\Option\Option;
use App\Models\Content\Page\InfoPage;
use App\Models\Settings\Local\Language;
use App\Services\Settings\SystemSettingsService;
use App\Support\Media\MediaProfileRegistry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Silber\Bouncer\BouncerFacade as Bouncer;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class StoreSettings extends Component
{
    use WithFileUploads;

    /** @var array<int, string> */
    private const LOCALIZED_SETTING_KEYS = [
        'store_footer_hours',
        'store_footer_contact_title',
        'store_footer_contact_intro',
        'store_footer_col_1_title',
        'store_footer_col_1_custom_links',
        'store_footer_col_2_title',
        'store_footer_col_2_custom_links',
        'store_footer_col_3_title',
        'store_footer_col_3_custom_links',
        'store_footer_bottom_copyright_text',
        'store_cookie_consent_title',
        'store_cookie_consent_message',
        'store_cookie_consent_accept_label',
        'store_cookie_consent_policy_label',
        'store_cookie_consent_policy_url',
        'store_cookie_preferences_title',
        'store_cookie_preferences_accept_all_label',
        'store_cookie_preferences_accept_necessary_label',
        'store_cookie_preferences_save_label',
        'store_cookie_necessary_title',
        'store_cookie_necessary_description',
        'store_cookie_analytics_title',
        'store_cookie_analytics_description',
        'store_cookie_marketing_title',
        'store_cookie_marketing_description',
    ];

    public string $tab = 'email';

    public string $locale = 'en';

    public string $previousLocale = 'en';

    /** @var array<string, array<string, string>> */
    public array $localizedValues = [];

    /** @var array<string, mixed> */
    public array $baseLocalizedValues = [];

    /** @var array<string, mixed> */
    public array $form = [
        'store_email_enabled' => false,
        'store_email_mailer' => 'smtp',
        'store_email_smtp_host' => '',
        'store_email_smtp_port' => 587,
        'store_email_smtp_username' => '',
        'store_email_smtp_password' => '',
        'store_email_smtp_encryption' => 'tls',
        'store_email_sendmail_path' => '/usr/sbin/sendmail -bs -i',
        'store_email_from_address' => '',
        'store_email_from_name' => '',
        'store_email_reply_to' => '',
        'store_email_orders_to' => '',
        'store_email_contact_to' => '',

        'store_brand_name' => '',
        'store_footer_phone' => '',
        'store_footer_email_sales' => '',
        'store_footer_email_support' => '',
        'store_footer_hours' => '',
        'store_footer_contact_title' => '',
        'store_footer_contact_intro' => '',
        'store_footer_col_1_title' => '',
        'store_footer_col_1_category_ids' => [],
        'store_footer_col_1_page_ids' => [],
        'store_footer_col_1_custom_links' => '',
        'store_footer_col_2_title' => '',
        'store_footer_col_2_category_ids' => [],
        'store_footer_col_2_page_ids' => [],
        'store_footer_col_2_custom_links' => '',
        'store_footer_col_3_title' => '',
        'store_footer_col_3_category_ids' => [],
        'store_footer_col_3_page_ids' => [],
        'store_footer_col_3_custom_links' => '',
        'store_footer_bottom_link_page_ids' => [],
        'store_footer_bottom_copyright_text' => '',
        'store_social_facebook_url' => '',
        'store_social_instagram_url' => '',
        'store_social_tiktok_url' => '',
        'store_social_youtube_url' => '',
        'store_footer_social_facebook_enabled' => true,
        'store_footer_social_instagram_enabled' => true,
        'store_footer_social_tiktok_enabled' => true,
        'store_footer_social_youtube_enabled' => true,
        'store_brand_logo_path' => '',
        'store_brand_logo_optimized_path' => '',
        'store_brand_logo_width' => 0,
        'store_brand_logo_height' => 0,
        'store_brand_favicon_path' => '',
        'store_brand_favicon_16_path' => '',
        'store_brand_favicon_32_path' => '',
        'store_brand_favicon_180_path' => '',
        'store_brand_favicon_192_path' => '',
        'store_brand_favicon_512_path' => '',
        'store_brand_favicon_ico_path' => '',

        'store_newsletter_provider' => 'none',
        'store_newsletter_mailchimp_api_key' => '',
        'store_newsletter_mailchimp_list_id' => '',
        'store_newsletter_klaviyo_api_key' => '',
        'store_newsletter_klaviyo_list_id' => '',
        'store_newsletter_club_label' => '',
        'store_newsletter_title' => '',
        'store_newsletter_subtitle' => '',
        'store_newsletter_button_label' => '',
        'store_newsletter_consent_label' => '',

        'store_captcha_recaptcha_v3_enabled' => false,
        'store_captcha_recaptcha_v3_site_key' => '',
        'store_captcha_recaptcha_v3_secret_key' => '',
        'store_captcha_recaptcha_v3_min_score' => 0.5,

        'store_analytics_enabled' => false,
        'store_analytics_ga4_measurement_id' => '',
        'store_analytics_purchase_event_enabled' => true,
        'store_analytics_purchase_event_name' => 'purchase',
        'store_pricing_prices_include_tax' => false,

        'store_seo_default_title' => '',
        'store_seo_default_description' => '',
        'store_seo_robots' => 'index,follow',
        'store_seo_canonical_policy' => 'self',

        'store_og_default_image_path' => '',
        'store_og_home_image_path' => '',
        'store_og_category_image_path' => '',
        'store_og_product_image_path' => '',
        'store_og_page_image_path' => '',
        'store_og_blog_image_path' => '',

        'store_schema_enabled' => true,
        'store_schema_org_enabled' => true,
        'store_schema_website_enabled' => true,
        'store_schema_breadcrumbs_enabled' => true,
        'store_schema_itemlist_enabled' => true,
        'store_schema_home_enabled' => true,
        'store_schema_category_enabled' => true,
        'store_schema_product_enabled' => true,
        'store_schema_blog_enabled' => true,
        'store_schema_page_enabled' => true,
        'store_schema_faq_enabled' => true,
        'store_schema_org_type' => 'Organization',
        'store_schema_business_name' => '',
        'store_schema_business_phone' => '',
        'store_schema_business_email' => '',
        'store_schema_address_street' => '',
        'store_schema_address_city' => '',
        'store_schema_address_region' => '',
        'store_schema_address_postal_code' => '',
        'store_schema_address_country' => 'HR',
        'store_schema_same_as' => '',
        'store_schema_blog_author_name' => '',
        'store_schema_blog_author_url' => '',
        'store_schema_product_currency' => 'EUR',
        'store_schema_faq_group' => '',
        'store_schema_faq_limit' => 8,
        'store_schema_itemlist_limit' => 12,

        'store_announcement_enabled' => true,
        'store_announcement_text' => '',
        'store_announcement_url' => '',
        'store_announcement_new_tab' => false,
        'store_announcement_scroll_enabled' => false,
        'store_announcement_scroll_duration_seconds' => 18,
        'store_announcement_background_color' => '#000000',
        'store_announcement_text_color' => '#ffffff',
        'store_benefits_bar_enabled' => true,
        'store_benefits_bar_item_1' => 'Više od **50 000 proizvoda** u ponudi',
        'store_benefits_bar_item_2' => 'Plaćanje karticama do **12 rata bez naknada**',
        'store_benefits_bar_item_3' => '**Dostava** u roku **3-5 radnih dana**',
        'store_images_use_webp' => true,
        'store_product_fit_finder_enabled' => false,
        'store_search_autocomplete_enabled' => false,
        'store_search_autocomplete_products_enabled' => true,
        'store_search_autocomplete_categories_enabled' => true,
        'store_search_autocomplete_manufacturers_enabled' => true,
        'store_search_autocomplete_blog_enabled' => true,
        'store_search_autocomplete_products_limit' => 5,
        'store_search_autocomplete_categories_limit' => 6,
        'store_search_autocomplete_manufacturers_limit' => 6,
        'store_search_autocomplete_blog_limit' => 3,
        'store_search_autocomplete_show_product_image' => true,
        'store_search_autocomplete_show_product_brand' => true,
        'store_search_autocomplete_show_product_sku' => true,
        'store_search_autocomplete_show_product_price' => true,
        'store_product_desktop_default_cols' => 4,
        'store_product_mobile_default_cols' => 2,
        'store_product_catalog_pagination_mode' => 'pagination',
        'store_product_filter_option_ids' => [],
        'store_product_filter_attribute_group_codes' => [],
        'store_product_filter_panel_settings' => [],

        'store_cookie_consent_enabled' => true,
        'store_cookie_consent_title' => 'Koristimo kolačiće',
        'store_cookie_consent_message' => 'Koristimo kolačiće za ispravan rad sajta i bolje korisničko iskustvo.',
        'store_cookie_consent_accept_label' => 'U redu',
        'store_cookie_consent_policy_label' => 'Politika kolačića',
        'store_cookie_consent_policy_url' => '',
        'store_cookie_preferences_title' => 'Postavke kolačića',
        'store_cookie_preferences_accept_all_label' => 'Prihvati sve',
        'store_cookie_preferences_accept_necessary_label' => 'Samo nužni',
        'store_cookie_preferences_save_label' => 'Spremi odabir',
        'store_cookie_necessary_title' => 'Nužni kolačići',
        'store_cookie_necessary_description' => 'Neki kolačići na ovoj internetskoj stranici neophodni su za pravilno funkcioniranje stranice stoga ih nije moguće onemogućiti.',
        'store_cookie_analytics_title' => 'Analitički kolačići',
        'store_cookie_analytics_description' => 'Analitički kolačići nam pomažu kako bismo poboljšali našu internetsku stranicu sakupljajući i analizirajući podatke o njenoj posjećenosti.',
        'store_cookie_marketing_title' => 'Marketinški kolačići',
        'store_cookie_marketing_description' => 'Marketinški kolačići služe za praćenje posjetitelja u korištenju internet stranice u svrhu omogućavanja prikazivanja relevantnih oglasa oglašivača trećih strana.',
    ];

    public ?TemporaryUploadedFile $logoUpload = null;

    public ?TemporaryUploadedFile $faviconUpload = null;

    public ?TemporaryUploadedFile $ogDefaultImageUpload = null;

    public ?TemporaryUploadedFile $ogHomeImageUpload = null;

    public ?TemporaryUploadedFile $ogCategoryImageUpload = null;

    public ?TemporaryUploadedFile $ogProductImageUpload = null;

    public ?TemporaryUploadedFile $ogPageImageUpload = null;

    public ?TemporaryUploadedFile $ogBlogImageUpload = null;

    /** @var array<string, mixed> */
    public array $webpGeneration = [
        'running' => false,
        'total' => 0,
        'processed' => 0,
        'failed' => 0,
        'finished' => false,
        'started_at' => null,
        'finished_at' => null,
        'last_id' => 0,
        'current_id' => null,
        'current_collection' => null,
        'last_processed_id' => null,
        'last_processed_collection' => null,
        'cursor' => 0,
    ];

    public function mount(): void
    {
        $this->authorizeAccess();

        $settings = app(SystemSettingsService::class);
        $this->locale = strtolower(trim((string) (request()->query('locale') ?: config('app.locale', 'en'))));
        $this->previousLocale = $this->locale;

        foreach ($this->form as $key => $default) {
            $this->form[$key] = $settings->get($key, $default);
        }

        foreach (self::LOCALIZED_SETTING_KEYS as $key) {
            $this->baseLocalizedValues[$key] = $this->form[$key] ?? '';
            $this->localizedValues[$key] = $this->normalizeTranslations(
                $settings->get($key.'_translations', [])
            );
        }

        $this->loadLocalizedInputs($this->locale);
        $this->form = $this->sanitizeSelectableSettings($this->form);

        if (trim((string) $this->form['store_announcement_text']) === '') {
            $this->form['store_announcement_text'] = (string) __('ui.front.desktop.promo_bar');
        }

        $this->refreshWebpGenerationStatus();
    }

    public function save(): void
    {
        $this->authorizeAccess();
        $this->syncLocalizedInputs($this->locale);

        $this->form = $this->sanitizeSelectableSettings($this->form);
        $validated = $this->validate($this->rulesForCurrentTab());
        $normalizedForm = $this->sanitizeSelectableSettings(array_merge($this->form, $validated['form'] ?? []));
        $payload = Arr::only($normalizedForm, $this->currentTabSettingKeys());

        if (array_key_exists('store_schema_product_currency', $payload)) {
            $payload['store_schema_product_currency'] = strtoupper((string) ($payload['store_schema_product_currency'] ?? 'EUR'));
        }

        if (array_key_exists('store_schema_address_country', $payload)) {
            $payload['store_schema_address_country'] = strtoupper((string) ($payload['store_schema_address_country'] ?? 'HR'));
        }

        if (array_key_exists('store_analytics_purchase_event_name', $payload)) {
            $payload['store_analytics_purchase_event_name'] = trim((string) ($payload['store_analytics_purchase_event_name'] ?? 'purchase')) ?: 'purchase';
        }

        $payload = array_merge($payload, $this->uploadPayloadForCurrentTab());
        $payload = $this->appendLocalizedPayload($payload);

        app(SystemSettingsService::class)->putMany($payload);
        $this->form = array_merge($this->form, Arr::only($payload, array_keys($this->form)));
        $this->loadLocalizedInputs($this->locale);
        $this->resetUploadsForCurrentTab();

        $this->dispatch('notify', type: 'success', message: __('Store settings saved.'));
    }

    public function updatedLocale(): void
    {
        $this->syncLocalizedInputs($this->previousLocale);
        $this->loadLocalizedInputs($this->locale);
        $this->previousLocale = $this->locale;
    }

    public function hasLocalizedSettingsForCurrentTab(): bool
    {
        return array_intersect($this->currentTabSettingKeys(), self::LOCALIZED_SETTING_KEYS) !== [];
    }

    /**
     * @return array<string, mixed>
     */
    private function rules(): array
    {
        return [
            'form.store_email_enabled' => ['required', 'boolean'],
            'form.store_email_mailer' => ['required', 'string', 'in:smtp,sendmail,log'],
            'form.store_email_smtp_host' => ['nullable', 'string', 'max:191'],
            'form.store_email_smtp_port' => ['required', 'integer', 'min:1', 'max:65535'],
            'form.store_email_smtp_username' => ['nullable', 'string', 'max:191'],
            'form.store_email_smtp_password' => ['nullable', 'string', 'max:191'],
            'form.store_email_smtp_encryption' => ['nullable', 'string', 'in:,tls,ssl'],
            'form.store_email_sendmail_path' => ['nullable', 'string', 'max:255'],
            'form.store_email_from_address' => ['nullable', 'email', 'max:191'],
            'form.store_email_from_name' => ['nullable', 'string', 'max:191'],
            'form.store_email_reply_to' => ['nullable', 'email', 'max:191'],
            'form.store_email_orders_to' => ['nullable', 'email', 'max:191'],
            'form.store_email_contact_to' => ['nullable', 'email', 'max:191'],

            'form.store_brand_name' => ['nullable', 'string', 'max:191'],
            'form.store_footer_phone' => ['nullable', 'string', 'max:120'],
            'form.store_footer_email_sales' => ['nullable', 'email', 'max:191'],
            'form.store_footer_email_support' => ['nullable', 'email', 'max:191'],
            'form.store_footer_hours' => ['nullable', 'string', 'max:255'],
            'form.store_footer_contact_title' => ['nullable', 'string', 'max:120'],
            'form.store_footer_contact_intro' => ['nullable', 'string', 'max:255'],
            'form.store_footer_col_1_title' => ['nullable', 'string', 'max:120'],
            'form.store_footer_col_1_category_ids' => ['nullable', 'array'],
            'form.store_footer_col_1_category_ids.*' => ['integer', Rule::exists('categories', 'id')->where(fn ($q) => $q->where('scope', Category::SCOPE_CATALOG))],
            'form.store_footer_col_1_page_ids' => ['nullable', 'array'],
            'form.store_footer_col_1_page_ids.*' => ['integer', 'exists:content_info_pages,id'],
            'form.store_footer_col_1_custom_links' => ['nullable', 'string', 'max:5000'],
            'form.store_footer_col_2_title' => ['nullable', 'string', 'max:120'],
            'form.store_footer_col_2_category_ids' => ['nullable', 'array'],
            'form.store_footer_col_2_category_ids.*' => ['integer', Rule::exists('categories', 'id')->where(fn ($q) => $q->where('scope', Category::SCOPE_CATALOG))],
            'form.store_footer_col_2_page_ids' => ['nullable', 'array'],
            'form.store_footer_col_2_page_ids.*' => ['integer', 'exists:content_info_pages,id'],
            'form.store_footer_col_2_custom_links' => ['nullable', 'string', 'max:5000'],
            'form.store_footer_col_3_title' => ['nullable', 'string', 'max:120'],
            'form.store_footer_col_3_category_ids' => ['nullable', 'array'],
            'form.store_footer_col_3_category_ids.*' => ['integer', Rule::exists('categories', 'id')->where(fn ($q) => $q->where('scope', Category::SCOPE_CATALOG))],
            'form.store_footer_col_3_page_ids' => ['nullable', 'array'],
            'form.store_footer_col_3_page_ids.*' => ['integer', 'exists:content_info_pages,id'],
            'form.store_footer_col_3_custom_links' => ['nullable', 'string', 'max:5000'],
            'form.store_footer_bottom_link_page_ids' => ['nullable', 'array'],
            'form.store_footer_bottom_link_page_ids.*' => ['integer', 'exists:content_info_pages,id'],
            'form.store_footer_bottom_copyright_text' => ['nullable', 'string', 'max:255'],
            'form.store_social_facebook_url' => ['nullable', 'url', 'max:2048'],
            'form.store_social_instagram_url' => ['nullable', 'url', 'max:2048'],
            'form.store_social_tiktok_url' => ['nullable', 'url', 'max:2048'],
            'form.store_social_youtube_url' => ['nullable', 'url', 'max:2048'],
            'form.store_footer_social_facebook_enabled' => ['required', 'boolean'],
            'form.store_footer_social_instagram_enabled' => ['required', 'boolean'],
            'form.store_footer_social_tiktok_enabled' => ['required', 'boolean'],
            'form.store_footer_social_youtube_enabled' => ['required', 'boolean'],

            'form.store_newsletter_provider' => ['required', 'string', 'in:none,database,mailchimp,klaviyo'],
            'form.store_newsletter_mailchimp_api_key' => [
                Rule::requiredIf(fn (): bool => (string) ($this->form['store_newsletter_provider'] ?? 'none') === 'mailchimp'),
                'nullable',
                'string',
                'max:255',
            ],
            'form.store_newsletter_mailchimp_list_id' => [
                Rule::requiredIf(fn (): bool => (string) ($this->form['store_newsletter_provider'] ?? 'none') === 'mailchimp'),
                'nullable',
                'string',
                'max:255',
            ],
            'form.store_newsletter_klaviyo_api_key' => [
                Rule::requiredIf(fn (): bool => (string) ($this->form['store_newsletter_provider'] ?? 'none') === 'klaviyo'),
                'nullable',
                'string',
                'max:255',
            ],
            'form.store_newsletter_klaviyo_list_id' => [
                Rule::requiredIf(fn (): bool => (string) ($this->form['store_newsletter_provider'] ?? 'none') === 'klaviyo'),
                'nullable',
                'string',
                'max:255',
            ],
            'form.store_newsletter_club_label' => ['nullable', 'string', 'max:120'],
            'form.store_newsletter_title' => ['nullable', 'string', 'max:191'],
            'form.store_newsletter_subtitle' => ['nullable', 'string', 'max:255'],
            'form.store_newsletter_button_label' => ['nullable', 'string', 'max:80'],
            'form.store_newsletter_consent_label' => ['nullable', 'string', 'max:255'],

            'form.store_captcha_recaptcha_v3_enabled' => ['required', 'boolean'],
            'form.store_captcha_recaptcha_v3_site_key' => ['nullable', 'string', 'max:255'],
            'form.store_captcha_recaptcha_v3_secret_key' => ['nullable', 'string', 'max:255'],
            'form.store_captcha_recaptcha_v3_min_score' => ['required', 'numeric', 'min:0', 'max:1'],

            'form.store_analytics_enabled' => ['required', 'boolean'],
            'form.store_analytics_ga4_measurement_id' => ['nullable', 'string', 'max:64'],
            'form.store_analytics_purchase_event_enabled' => ['required', 'boolean'],
            'form.store_analytics_purchase_event_name' => ['nullable', 'string', 'max:64'],
            'form.store_pricing_prices_include_tax' => ['required', 'boolean'],

            'form.store_seo_default_title' => ['nullable', 'string', 'max:191'],
            'form.store_seo_default_description' => ['nullable', 'string', 'max:320'],
            'form.store_seo_robots' => ['nullable', 'string', 'max:120'],
            'form.store_seo_canonical_policy' => ['required', 'string', 'in:self,none'],

            'form.store_schema_enabled' => ['required', 'boolean'],
            'form.store_schema_org_enabled' => ['required', 'boolean'],
            'form.store_schema_website_enabled' => ['required', 'boolean'],
            'form.store_schema_breadcrumbs_enabled' => ['required', 'boolean'],
            'form.store_schema_itemlist_enabled' => ['required', 'boolean'],
            'form.store_schema_home_enabled' => ['required', 'boolean'],
            'form.store_schema_category_enabled' => ['required', 'boolean'],
            'form.store_schema_product_enabled' => ['required', 'boolean'],
            'form.store_schema_blog_enabled' => ['required', 'boolean'],
            'form.store_schema_page_enabled' => ['required', 'boolean'],
            'form.store_schema_faq_enabled' => ['required', 'boolean'],
            'form.store_schema_org_type' => ['required', 'string', 'in:Organization,LocalBusiness,Store'],
            'form.store_schema_business_name' => ['nullable', 'string', 'max:191'],
            'form.store_schema_business_phone' => ['nullable', 'string', 'max:120'],
            'form.store_schema_business_email' => ['nullable', 'email', 'max:191'],
            'form.store_schema_address_street' => ['nullable', 'string', 'max:191'],
            'form.store_schema_address_city' => ['nullable', 'string', 'max:120'],
            'form.store_schema_address_region' => ['nullable', 'string', 'max:120'],
            'form.store_schema_address_postal_code' => ['nullable', 'string', 'max:32'],
            'form.store_schema_address_country' => ['nullable', 'string', 'max:2'],
            'form.store_schema_same_as' => ['nullable', 'string', 'max:5000'],
            'form.store_schema_blog_author_name' => ['nullable', 'string', 'max:191'],
            'form.store_schema_blog_author_url' => ['nullable', 'url', 'max:2048'],
            'form.store_schema_product_currency' => ['required', 'string', 'size:3'],
            'form.store_schema_faq_group' => ['nullable', 'string', 'max:120'],
            'form.store_schema_faq_limit' => ['required', 'integer', 'min:1', 'max:20'],
            'form.store_schema_itemlist_limit' => ['required', 'integer', 'min:1', 'max:48'],

            'form.store_announcement_enabled' => ['required', 'boolean'],
            'form.store_announcement_text' => ['nullable', 'string', 'max:191'],
            'form.store_announcement_url' => ['nullable', 'url', 'max:2048'],
            'form.store_announcement_new_tab' => ['required', 'boolean'],
            'form.store_announcement_scroll_enabled' => ['required', 'boolean'],
            'form.store_announcement_scroll_duration_seconds' => ['required', 'integer', 'min:6', 'max:60'],
            'form.store_announcement_background_color' => ['required', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'form.store_announcement_text_color' => ['required', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'form.store_benefits_bar_enabled' => ['required', 'boolean'],
            'form.store_benefits_bar_item_1' => ['nullable', 'string', 'max:191'],
            'form.store_benefits_bar_item_2' => ['nullable', 'string', 'max:191'],
            'form.store_benefits_bar_item_3' => ['nullable', 'string', 'max:191'],
            'form.store_images_use_webp' => ['required', 'boolean'],
            'form.store_product_fit_finder_enabled' => ['required', 'boolean'],
            'form.store_search_autocomplete_enabled' => ['required', 'boolean'],
            'form.store_search_autocomplete_products_enabled' => ['required', 'boolean'],
            'form.store_search_autocomplete_categories_enabled' => ['required', 'boolean'],
            'form.store_search_autocomplete_manufacturers_enabled' => ['required', 'boolean'],
            'form.store_search_autocomplete_blog_enabled' => ['required', 'boolean'],
            'form.store_search_autocomplete_products_limit' => ['required', 'integer', 'min:1', 'max:12'],
            'form.store_search_autocomplete_categories_limit' => ['required', 'integer', 'min:1', 'max:10'],
            'form.store_search_autocomplete_manufacturers_limit' => ['required', 'integer', 'min:1', 'max:10'],
            'form.store_search_autocomplete_blog_limit' => ['required', 'integer', 'min:1', 'max:10'],
            'form.store_search_autocomplete_show_product_image' => ['required', 'boolean'],
            'form.store_search_autocomplete_show_product_brand' => ['required', 'boolean'],
            'form.store_search_autocomplete_show_product_sku' => ['required', 'boolean'],
            'form.store_search_autocomplete_show_product_price' => ['required', 'boolean'],
            'form.store_product_desktop_default_cols' => ['required', 'integer', 'in:4,5'],
            'form.store_product_mobile_default_cols' => ['required', 'integer', 'in:1,2'],
            'form.store_product_catalog_pagination_mode' => ['required', 'string', 'in:pagination,load_more,infinite'],
            'form.store_product_filter_option_ids' => ['nullable', 'array'],
            'form.store_product_filter_option_ids.*' => [
                'integer',
                Rule::exists('catalog_options', 'id')->where(fn ($q) => $q->where('is_active', true)),
            ],
            'form.store_product_filter_attribute_group_codes' => ['nullable', 'array'],
            'form.store_product_filter_attribute_group_codes.*' => ['string', 'max:120'],
            'form.store_product_filter_panel_settings' => ['nullable', 'array'],

            'form.store_cookie_consent_enabled' => ['required', 'boolean'],
            'form.store_cookie_consent_title' => ['nullable', 'string', 'max:120'],
            'form.store_cookie_consent_message' => ['nullable', 'string', 'max:2000'],
            'form.store_cookie_consent_accept_label' => ['nullable', 'string', 'max:60'],
            'form.store_cookie_consent_policy_label' => ['nullable', 'string', 'max:60'],
            'form.store_cookie_consent_policy_url' => ['nullable', 'url', 'max:2048'],
            'form.store_cookie_preferences_title' => ['nullable', 'string', 'max:120'],
            'form.store_cookie_preferences_accept_all_label' => ['nullable', 'string', 'max:60'],
            'form.store_cookie_preferences_accept_necessary_label' => ['nullable', 'string', 'max:60'],
            'form.store_cookie_preferences_save_label' => ['nullable', 'string', 'max:60'],
            'form.store_cookie_necessary_title' => ['nullable', 'string', 'max:120'],
            'form.store_cookie_necessary_description' => ['nullable', 'string', 'max:2000'],
            'form.store_cookie_analytics_title' => ['nullable', 'string', 'max:120'],
            'form.store_cookie_analytics_description' => ['nullable', 'string', 'max:2000'],
            'form.store_cookie_marketing_title' => ['nullable', 'string', 'max:120'],
            'form.store_cookie_marketing_description' => ['nullable', 'string', 'max:2000'],

            'logoUpload' => ['nullable', 'file', 'max:4096', 'mimes:jpg,jpeg,png,webp,avif,svg'],
            'faviconUpload' => ['nullable', 'image', 'max:2048'],
            'ogDefaultImageUpload' => ['nullable', 'image', 'max:4096'],
            'ogHomeImageUpload' => ['nullable', 'image', 'max:4096'],
            'ogCategoryImageUpload' => ['nullable', 'image', 'max:4096'],
            'ogProductImageUpload' => ['nullable', 'image', 'max:4096'],
            'ogPageImageUpload' => ['nullable', 'image', 'max:4096'],
            'ogBlogImageUpload' => ['nullable', 'image', 'max:4096'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function rulesForCurrentTab(): array
    {
        $allowedFormRules = array_fill_keys(
            array_map(static fn (string $key): string => 'form.'.$key, $this->currentTabSettingKeys()),
            true
        );
        $allowedUploadRules = array_fill_keys($this->currentTabUploadKeys(), true);

        return array_filter($this->rules(), function (mixed $rule, string $key) use ($allowedFormRules, $allowedUploadRules): bool {
            if (isset($allowedFormRules[$key]) || isset($allowedUploadRules[$key])) {
                return true;
            }

            if (str_ends_with($key, '.*')) {
                return isset($allowedFormRules[Str::before($key, '.*')]);
            }

            return false;
        }, ARRAY_FILTER_USE_BOTH);
    }

    public function render()
    {
        $locale = $this->locale;
        $fallbackLocale = (string) config('app.locale');

        $catalogCategoryOptions = Category::query()
            ->where('scope', Category::SCOPE_CATALOG)
            ->where('is_active', true)
            ->withDepth()
            ->defaultOrder()
            ->with([
                'translations' => fn ($q) => $q
                    ->where('scope', Category::SCOPE_CATALOG)
                    ->whereIn('locale', [$locale, $fallbackLocale]),
            ])
            ->get();

        $categoryNameById = $catalogCategoryOptions->mapWithKeys(function (Category $category) use ($locale, $fallbackLocale): array {
            $translation = $category->translations->firstWhere('locale', $locale)
                ?? $category->translations->firstWhere('locale', $fallbackLocale)
                ?? $category->translations->first();

            return [
                (int) $category->id => (string) ($translation?->name ?? $category->code ?? ('Category #'.$category->id)),
            ];
        });
        $categoryMap = $catalogCategoryOptions->keyBy(fn (Category $category): int => (int) $category->id);

        $catalogCategoryOptions = $catalogCategoryOptions
            ->map(function (Category $category) use ($categoryNameById, $categoryMap): array {
                $parts = [];
                $cursor = $category;
                $guard = 0;

                while ($cursor && $guard < 32) {
                    $parts[] = (string) ($categoryNameById[(int) $cursor->id] ?? ('Category #'.$cursor->id));
                    $parentId = (int) ($cursor->parent_id ?? 0);
                    $cursor = $parentId > 0 ? $categoryMap->get($parentId) : null;
                    $guard++;
                }

                return [
                    'id' => (int) $category->id,
                    'label' => implode(' > ', array_reverse($parts)),
                ];
            })
            ->values()
            ->all();

        $pageOptions = InfoPage::query()
            ->where('is_active', true)
            ->with(['translations' => fn ($q) => $q->whereIn('locale', [$locale, $fallbackLocale])])
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(function (InfoPage $page) use ($locale, $fallbackLocale): array {
                $translation = $page->translations->firstWhere('locale', $locale)
                    ?? $page->translations->firstWhere('locale', $fallbackLocale)
                    ?? $page->translations->first();

                return [
                    'id' => (int) $page->id,
                    'label' => (string) ($translation?->title ?? $page->code),
                ];
            })
            ->values()
            ->all();

        $optionFilterOptions = Option::query()
            ->where('is_active', true)
            ->withCount('values')
            ->with(['translations' => fn ($q) => $q->whereIn('locale', [$locale, $fallbackLocale])])
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(function (Option $option) use ($locale, $fallbackLocale): array {
                $translation = $option->translations->firstWhere('locale', $locale)
                    ?? $option->translations->firstWhere('locale', $fallbackLocale)
                    ?? $option->translations->first();

                return [
                    'id' => (int) $option->id,
                    'label' => (string) (($translation?->name ?? $option->code).' ('.(int) $option->values_count.')'),
                    'panel_key' => 'opt_'.(int) $option->id,
                ];
            })
            ->values()
            ->all();

        $attributeFilterGroupOptions = Attribute::query()
            ->where('is_active', true)
            ->with(['translations' => fn ($q) => $q->whereIn('locale', [$locale, $fallbackLocale])])
            ->orderBy('group_code')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->groupBy(fn (Attribute $attribute): string => (string) $attribute->group_code)
            ->map(function ($rows, string $groupCode) use ($locale, $fallbackLocale): array {
                $first = $rows->first();
                $firstTranslation = $first?->translations?->firstWhere('locale', $locale)
                    ?? $first?->translations?->firstWhere('locale', $fallbackLocale)
                    ?? $first?->translations?->first();
                $groupName = trim((string) ($firstTranslation?->group_name ?? ''));
                if ($groupName === '') {
                    $groupName = ucfirst(str_replace('_', ' ', $groupCode));
                }

                return [
                    'group_code' => $groupCode,
                    'label' => $groupName.' ('.$groupCode.')',
                    'panel_key' => 'attr_'.Str::slug($groupCode, '_'),
                ];
            })
            ->values()
            ->all();

        return view('livewire.admin.settings.system.store-settings', [
            'catalogCategoryOptions' => $catalogCategoryOptions,
            'pageOptions' => $pageOptions,
            'optionFilterOptions' => $optionFilterOptions,
            'attributeFilterGroupOptions' => $attributeFilterGroupOptions,
        ]);
    }

    private function authorizeAccess(): void
    {
        $user = auth()->user();
        abort_unless(
            $user && (Bouncer::is($user)->an('superadmin') || $user->can('settings.system.store.manage')),
            403
        );
    }

    public function startWebpGeneration(): void
    {
        $this->authorizeAccess();
        Cache::forget($this->webpCoverageCacheKey());

        $cacheKey = $this->webpGenerationCacheKey();
        $state = Cache::get($cacheKey, []);
        if ((bool) ($state['running'] ?? false)) {
            $this->refreshWebpGenerationStatus();

            return;
        }

        $this->optimizeStoredLogoIfNeeded();

        $missingIds = $this->collectMissingWebpMediaIds();
        $total = count($missingIds);

        $state = [
            'running' => $total > 0,
            'total' => (int) $total,
            'processed' => 0,
            'failed' => 0,
            'finished' => $total === 0,
            'started_at' => now()->toDateTimeString(),
            'finished_at' => $total === 0 ? now()->toDateTimeString() : null,
            'last_id' => 0,
            'current_id' => null,
            'current_collection' => null,
            'last_processed_id' => null,
            'last_processed_collection' => null,
            'cursor' => 0,
            'last_ping_at' => now()->toDateTimeString(),
            'pending_ids' => $missingIds,
        ];

        Cache::put($cacheKey, $state, now()->addHours(6));
        $this->refreshWebpGenerationStatus();

        if ($total === 0) {
            $this->dispatch('notify', type: 'success', message: __('admin.settings.store.images.notify_no_media'));

            return;
        }

        GenerateWebpConversionsJob::dispatch((int) (auth()->id() ?? 0));
        $this->dispatch('notify', type: 'success', message: __('WebP obrada je pokrenuta u pozadini.'));
    }

    public function processWebpGenerationStep(): void
    {
        $cacheKey = $this->webpGenerationCacheKey();
        $state = Cache::get($cacheKey, []);
        if (! ((bool) ($state['running'] ?? false))) {
            $this->refreshWebpGenerationStatus();

            return;
        }

        GenerateWebpConversionsJob::processInteractiveStep((int) (auth()->id() ?? 0));

        $state = Cache::get($cacheKey, []);
        $lastPingAt = (string) ($state['last_ping_at'] ?? '');
        $lastPingTs = strtotime($lastPingAt) ?: 0;
        if ($lastPingTs <= 0 || (time() - $lastPingTs) > 30) {
            $state['last_ping_at'] = now()->toDateTimeString();
            Cache::put($cacheKey, $state, now()->addHours(6));
            GenerateWebpConversionsJob::dispatch((int) (auth()->id() ?? 0));
        }

        $this->refreshWebpGenerationStatus();
    }

    /**
     * @return array<int, string>
     */
    private function webpConversionNamesForModel(string $modelType, ?string $collectionName = null): array
    {
        $map = MediaProfileRegistry::conversionMapForModel($modelType);

        if ($collectionName !== null && $collectionName !== '') {
            $map = array_filter($map, static function (array $config) use ($collectionName): bool {
                $collections = array_values(array_filter((array) ($config['collections'] ?? [])));

                return in_array($collectionName, $collections, true);
            });
        }

        $conversionNames = array_keys($map);
        if ($conversionNames === []) {
            return [];
        }

        return array_map(static fn (string $name): string => $name.'_webp', $conversionNames);
    }

    /**
     * @return array<int, int>
     */
    private function collectMissingWebpMediaIds(): array
    {
        $ids = [];

        $this->activeStorefrontWebpGenerationMediaQuery()
            ->orderBy('id')
            ->chunkById(250, function ($mediaItems) use (&$ids): void {
                /** @var Media $media */
                foreach ($mediaItems as $media) {
                    if ($this->mediaHasMissingWebp($media)) {
                        $ids[] = (int) $media->id;
                    }
                }
            });

        return $ids;
    }

    private function mediaHasMissingWebp(Media $media): bool
    {
        $conversionNames = $this->webpConversionNamesForModel((string) $media->model_type, (string) $media->collection_name);
        if ($conversionNames === []) {
            return false;
        }

        $generated = is_array($media->generated_conversions) ? $media->generated_conversions : [];

        foreach ($conversionNames as $conversionName) {
            if (($generated[$conversionName] ?? false) !== true) {
                return true;
            }

            $absolutePath = $media->getPath($conversionName);
            $relativePath = $absolutePath;
            if ($rootPath = config("filesystems.disks.{$media->disk}.root")) {
                $relativePath = str_replace($rootPath, '', $absolutePath);
            }

            if (! Storage::disk($media->disk)->exists((string) $relativePath)) {
                return true;
            }
        }

        return false;
    }

    private function webpGenerationCacheKey(): string
    {
        $userId = (int) (auth()->id() ?? 0);

        return 'settings.store.webp_generation.active_products.'.$userId;
    }

    private function webpCoverageCacheKey(): string
    {
        return 'settings.store.webp_coverage.active_products';
    }

    private function refreshWebpGenerationStatus(): void
    {
        $state = Cache::get($this->webpGenerationCacheKey(), []);
        $coverage = $this->webpCoverageSummary();

        if (! is_array($state) || $state === []) {
            $this->webpGeneration = array_merge($this->webpGeneration, [
                'running' => false,
                'finished' => false,
                'processed' => (int) ($coverage['processed'] ?? 0),
                'total' => (int) ($coverage['total'] ?? 0),
                'failed' => 0,
            ]);

            return;
        }

        $merged = array_merge($this->webpGeneration, $state);
        if (! ((bool) ($merged['running'] ?? false))) {
            $merged['processed'] = (int) ($coverage['processed'] ?? 0);
            $merged['total'] = (int) ($coverage['total'] ?? 0);
        }

        $this->webpGeneration = $merged;
    }

    /**
     * @return array{processed:int,total:int}
     */
    private function webpCoverageSummary(): array
    {
        $cached = Cache::get($this->webpCoverageCacheKey());
        if (is_array($cached) && (int) ($cached['total'] ?? 0) > 0) {
            return [
                'processed' => (int) ($cached['processed'] ?? 0),
                'total' => (int) ($cached['total'] ?? 0),
            ];
        }

        $total = 0;
        $processed = 0;

        $this->activeStorefrontWebpGenerationMediaQuery()
            ->orderBy('id')
            ->chunkById(250, function ($mediaItems) use (&$total, &$processed): void {
                /** @var Media $media */
                foreach ($mediaItems as $media) {
                    $conversionNames = $this->webpConversionNamesForModel((string) $media->model_type, (string) $media->collection_name);
                    if ($conversionNames === []) {
                        continue;
                    }

                    $total++;
                    if (! $this->mediaHasMissingWebp($media)) {
                        $processed++;
                    }
                }
            });

        $summary = [
            'processed' => $processed,
            'total' => $total,
        ];

        // Avoid keeping stale 0/0 snapshots for long; recompute next time if still empty.
        if ($total > 0) {
            Cache::put($this->webpCoverageCacheKey(), $summary, now()->addMinutes(5));
        } else {
            Cache::forget($this->webpCoverageCacheKey());
        }

        return $summary;
    }

    /**
     * @return Builder<Media>
     */
    private function activeStorefrontWebpGenerationMediaQuery(): Builder
    {
        return Media::query()
            ->whereIn('model_type', MediaProfileRegistry::modelClasses())
            ->whereHasMorph('model', MediaProfileRegistry::modelClasses(), function (Builder $modelQuery): void {
                $modelQuery->where('is_active', true);
            });
    }

    /**
     * @return array<string, int|string>
     */
    private function processLogoUpload(TemporaryUploadedFile $upload): array
    {
        $storedOriginalPath = $upload->store('store-settings', 'public');
        $payload = [
            'store_brand_logo_path' => $storedOriginalPath,
            'store_brand_logo_optimized_path' => '',
            'store_brand_logo_width' => 0,
            'store_brand_logo_height' => 0,
        ];

        $source = $this->createImageResourceFromUpload($upload);
        if (! $source || ! function_exists('imagewebp')) {
            return $payload;
        }

        return array_merge($payload, $this->renderOptimizedLogo($source, $storedOriginalPath));
    }

    /**
     * @return array<string, int|string>
     */
    private function renderOptimizedLogo(mixed $source, string $storedOriginalPath): array
    {
        $sourceWidth = imagesx($source);
        $sourceHeight = imagesy($source);
        if ($sourceWidth < 1 || $sourceHeight < 1) {
            imagedestroy($source);

            return [];
        }

        $targetWidth = min(320, $sourceWidth);
        $targetHeight = max(1, (int) round($sourceHeight * ($targetWidth / $sourceWidth)));
        $canvas = imagecreatetruecolor($targetWidth, $targetHeight);
        if (! $canvas) {
            imagedestroy($source);

            return [];
        }

        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);
        $transparent = imagecolorallocatealpha($canvas, 0, 0, 0, 127);
        imagefilledrectangle($canvas, 0, 0, $targetWidth, $targetHeight, $transparent);
        imagecopyresampled(
            $canvas,
            $source,
            0,
            0,
            0,
            0,
            $targetWidth,
            $targetHeight,
            $sourceWidth,
            $sourceHeight
        );

        ob_start();
        $rendered = imagewebp($canvas, null, 82);
        $binary = ob_get_clean();
        imagedestroy($canvas);
        imagedestroy($source);

        if (! $rendered || ! is_string($binary) || $binary === '') {
            return [];
        }

        $optimizedPath = 'store-settings/'.pathinfo($storedOriginalPath, PATHINFO_FILENAME).'-optimized.webp';
        Storage::disk('public')->put($optimizedPath, $binary);

        return [
            'store_brand_logo_optimized_path' => $optimizedPath,
            'store_brand_logo_width' => $targetWidth,
            'store_brand_logo_height' => $targetHeight,
        ];
    }

    private function optimizeStoredLogoIfNeeded(): void
    {
        if (! function_exists('imagewebp')) {
            return;
        }

        $settings = app(SystemSettingsService::class);
        $originalPath = trim((string) $settings->get('store_brand_logo_path', ''));
        $optimizedPath = trim((string) $settings->get('store_brand_logo_optimized_path', ''));
        $optimizedWidth = (int) $settings->get('store_brand_logo_width', 0);
        $optimizedHeight = (int) $settings->get('store_brand_logo_height', 0);
        $disk = Storage::disk('public');

        if ($originalPath === '' || ! $disk->exists($originalPath)) {
            return;
        }

        if (
            $optimizedPath !== ''
            && $optimizedWidth > 0
            && $optimizedWidth <= 320
            && $optimizedHeight > 0
            && $disk->exists($optimizedPath)
        ) {
            return;
        }

        $realPath = $disk->path($originalPath);
        $mime = strtolower((string) ($disk->mimeType($originalPath) ?: ''));
        $source = $this->createImageResourceFromPath($realPath, $mime);
        if (! $source) {
            return;
        }

        $payload = $this->renderOptimizedLogo($source, $originalPath);
        if ($payload !== []) {
            $settings->putMany($payload);
            $this->form = array_merge($this->form, $payload);
        }
    }

    /**
     * @return array<string, string>
     */
    private function processFaviconUpload(TemporaryUploadedFile $upload): array
    {
        $extension = strtolower((string) $upload->getClientOriginalExtension());
        $storedOriginalPath = $upload->store('store-settings/favicon', 'public');
        $payload = ['store_brand_favicon_path' => $storedOriginalPath];

        $source = $this->createImageResourceFromUpload($upload);
        if (! $source) {
            return $payload;
        }

        $targets = [
            16 => 'store_brand_favicon_16_path',
            32 => 'store_brand_favicon_32_path',
            180 => 'store_brand_favicon_180_path',
            192 => 'store_brand_favicon_192_path',
            512 => 'store_brand_favicon_512_path',
        ];

        foreach ($targets as $size => $settingKey) {
            $pngPath = 'store-settings/favicon/'.uniqid('favicon-'.$size.'-', true).'.png';
            $pngBinary = $this->renderSquarePng($source, $size);
            if ($pngBinary === null) {
                continue;
            }

            Storage::disk('public')->put($pngPath, $pngBinary);
            $payload[$settingKey] = $pngPath;
        }

        $icoPath = 'store-settings/favicon/'.uniqid('favicon-ico-', true).'.ico';
        $icoBinary = $this->renderIcoFromImage($source, 32);
        if ($icoBinary !== null) {
            Storage::disk('public')->put($icoPath, $icoBinary);
            $payload['store_brand_favicon_ico_path'] = $icoPath;
        }

        imagedestroy($source);

        if (! in_array($extension, ['png', 'jpg', 'jpeg', 'webp', 'gif', 'avif'], true)) {
            $payload['store_brand_favicon_path'] = $payload['store_brand_favicon_32_path'] ?? $storedOriginalPath;
        }

        return $payload;
    }

    private function createImageResourceFromUpload(TemporaryUploadedFile $upload): mixed
    {
        $realPath = $upload->getRealPath();
        if (! is_string($realPath) || $realPath === '') {
            return null;
        }

        return $this->createImageResourceFromPath($realPath, strtolower((string) $upload->getMimeType()));
    }

    private function createImageResourceFromPath(string $realPath, string $mime): mixed
    {
        return match ($mime) {
            'image/png' => @imagecreatefrompng($realPath) ?: null,
            'image/jpeg', 'image/jpg' => @imagecreatefromjpeg($realPath) ?: null,
            'image/gif' => @imagecreatefromgif($realPath) ?: null,
            'image/webp' => function_exists('imagecreatefromwebp') ? (@imagecreatefromwebp($realPath) ?: null) : null,
            'image/avif' => function_exists('imagecreatefromavif') ? (@imagecreatefromavif($realPath) ?: null) : null,
            default => null,
        };
    }

    /**
     * @return array<int, int>
     */
    private function normalizeIdList(mixed $value): array
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            $value = is_array($decoded) ? $decoded : [];
        }

        if (! is_array($value)) {
            return [];
        }

        $normalized = [];
        foreach ($value as $id) {
            $intId = (int) $id;
            if ($intId > 0) {
                $normalized[] = $intId;
            }
        }

        return array_values(array_unique($normalized));
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function sanitizeSelectableSettings(array $values): array
    {
        $validCategoryIds = Category::query()
            ->where('scope', Category::SCOPE_CATALOG)
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();
        $validPageIds = InfoPage::query()
            ->where('is_active', true)
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();
        $validOptionIds = Option::query()
            ->where('is_active', true)
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();
        $validAttributeGroupCodes = Attribute::query()
            ->where('is_active', true)
            ->whereNotNull('group_code')
            ->distinct()
            ->pluck('group_code')
            ->map(fn ($code): string => trim((string) $code))
            ->filter(fn (string $code): bool => $code !== '')
            ->values()
            ->all();

        foreach ([1, 2, 3] as $col) {
            $categoryKey = 'store_footer_col_'.$col.'_category_ids';
            $pageKey = 'store_footer_col_'.$col.'_page_ids';
            $values[$categoryKey] = $this->filterIdList($values[$categoryKey] ?? [], $validCategoryIds);
            $values[$pageKey] = $this->filterIdList($values[$pageKey] ?? [], $validPageIds);
        }

        $values['store_footer_bottom_link_page_ids'] = $this->filterIdList($values['store_footer_bottom_link_page_ids'] ?? [], $validPageIds);
        $values['store_product_filter_option_ids'] = $this->filterIdList($values['store_product_filter_option_ids'] ?? [], $validOptionIds);
        $values['store_product_filter_attribute_group_codes'] = collect($values['store_product_filter_attribute_group_codes'] ?? [])
            ->map(fn ($code): string => trim((string) $code))
            ->filter(fn (string $code): bool => $code !== '' && in_array($code, $validAttributeGroupCodes, true))
            ->unique()
            ->values()
            ->all();
        $allowedFilterPanelKeys = collect(['category', 'manufacturer', 'price'])
            ->merge(array_map(
                static fn (int $id): string => 'opt_'.$id,
                $validOptionIds
            ))
            ->merge(array_map(
                static fn (string $code): string => 'attr_'.Str::slug($code, '_'),
                $validAttributeGroupCodes
            ))
            ->unique()
            ->values();
        $rawFilterPanelSettings = is_array($values['store_product_filter_panel_settings'] ?? null)
            ? $values['store_product_filter_panel_settings']
            : [];
        $allowedHeights = [160, 220, 286, 360];
        $values['store_product_filter_panel_settings'] = $allowedFilterPanelKeys
            ->mapWithKeys(function (string $panelKey) use ($rawFilterPanelSettings, $allowedHeights): array {
                $raw = is_array($rawFilterPanelSettings[$panelKey] ?? null)
                    ? $rawFilterPanelSettings[$panelKey]
                    : [];
                $height = (int) ($raw['max_height'] ?? 286);

                return [
                    $panelKey => [
                        'visible' => array_key_exists('visible', $raw) ? (bool) $raw['visible'] : true,
                        'default_open' => array_key_exists('default_open', $raw) ? (bool) $raw['default_open'] : true,
                        'max_height' => in_array($height, $allowedHeights, true) ? $height : 286,
                    ],
                ];
            })
            ->all();

        return $values;
    }

    /**
     * @return array<int, string>
     */
    private function currentTabSettingKeys(): array
    {
        return array_values(array_filter(
            array_keys($this->form),
            fn (string $key): bool => match ($this->tab) {
                'email' => str_starts_with($key, 'store_email_'),
                'branding' => str_starts_with($key, 'store_brand_')
                    || str_starts_with($key, 'store_footer_')
                    || str_starts_with($key, 'store_social_'),
                'newsletter' => str_starts_with($key, 'store_newsletter_'),
                'integrations' => str_starts_with($key, 'store_captcha_')
                    || str_starts_with($key, 'store_analytics_'),
                'pricing' => str_starts_with($key, 'store_pricing_'),
                'images' => str_starts_with($key, 'store_images_'),
                'products' => str_starts_with($key, 'store_product_')
                    || str_starts_with($key, 'store_search_autocomplete_'),
                'seo' => str_starts_with($key, 'store_seo_'),
                'og' => str_starts_with($key, 'store_og_'),
                'schema' => str_starts_with($key, 'store_schema_'),
                'announcement' => str_starts_with($key, 'store_announcement_')
                    || str_starts_with($key, 'store_benefits_bar_'),
                'cookies' => str_starts_with($key, 'store_cookie_'),
                default => false,
            }
        ));
    }

    private function syncLocalizedInputs(string $locale): void
    {
        $locale = strtolower(trim($locale));
        if ($locale === '') {
            return;
        }

        foreach (self::LOCALIZED_SETTING_KEYS as $key) {
            $translations = $this->normalizeTranslations($this->localizedValues[$key] ?? []);
            $value = trim((string) ($this->form[$key] ?? ''));

            if ($value !== '') {
                $translations[$locale] = $value;
            } else {
                unset($translations[$locale]);
            }

            $this->localizedValues[$key] = $translations;
        }
    }

    private function loadLocalizedInputs(string $locale): void
    {
        $locale = strtolower(trim($locale));
        $fallbackLocale = $this->defaultContentLocale();

        foreach (self::LOCALIZED_SETTING_KEYS as $key) {
            $translations = $this->normalizeTranslations($this->localizedValues[$key] ?? []);

            if (array_key_exists($locale, $translations)) {
                $this->form[$key] = $translations[$locale];
            } elseif ($locale === $fallbackLocale) {
                $this->form[$key] = $this->baseLocalizedValues[$key] ?? '';
            } else {
                $this->form[$key] = '';
            }
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function appendLocalizedPayload(array $payload): array
    {
        $fallbackLocale = $this->defaultContentLocale();

        foreach (self::LOCALIZED_SETTING_KEYS as $key) {
            if (! array_key_exists($key, $payload)) {
                continue;
            }

            $translations = $this->normalizeTranslations($this->localizedValues[$key] ?? []);
            $baseValue = $this->baseLocalizedValues[$key] ?? '';

            if (array_key_exists($fallbackLocale, $translations)) {
                $baseValue = $translations[$fallbackLocale];
            }

            $this->baseLocalizedValues[$key] = $baseValue;
            $payload[$key] = $baseValue;
            $payload[$key.'_translations'] = $translations;
        }

        return $payload;
    }

    /**
     * @return array<string, string>
     */
    private function normalizeTranslations(mixed $translations): array
    {
        if (! is_array($translations)) {
            return [];
        }

        $normalized = [];
        foreach ($translations as $locale => $value) {
            $locale = strtolower(trim((string) $locale));
            $value = trim((string) $value);

            if ($locale !== '' && $value !== '') {
                $normalized[$locale] = $value;
            }
        }

        return $normalized;
    }

    private function defaultContentLocale(): string
    {
        $default = Language::query()
            ->where('is_default', true)
            ->orderBy('sort_order')
            ->value('code');

        return strtolower(trim((string) $default)) ?: strtolower((string) config('app.locale', 'en'));
    }

    /**
     * @return array<int, string>
     */
    private function currentTabUploadKeys(): array
    {
        return match ($this->tab) {
            'branding' => ['logoUpload', 'faviconUpload'],
            'og' => [
                'ogDefaultImageUpload',
                'ogHomeImageUpload',
                'ogCategoryImageUpload',
                'ogProductImageUpload',
                'ogPageImageUpload',
                'ogBlogImageUpload',
            ],
            default => [],
        };
    }

    /**
     * @return array<string, string>
     */
    private function uploadPayloadForCurrentTab(): array
    {
        $payload = [];

        if ($this->tab === 'branding') {
            if ($this->logoUpload) {
                $payload = array_merge($payload, $this->processLogoUpload($this->logoUpload));
            }

            if ($this->faviconUpload) {
                $payload = array_merge($payload, $this->processFaviconUpload($this->faviconUpload));
            }
        }

        if ($this->tab === 'og') {
            if ($this->ogDefaultImageUpload) {
                $payload['store_og_default_image_path'] = $this->ogDefaultImageUpload->store('store-settings', 'public');
            }
            if ($this->ogHomeImageUpload) {
                $payload['store_og_home_image_path'] = $this->ogHomeImageUpload->store('store-settings', 'public');
            }
            if ($this->ogCategoryImageUpload) {
                $payload['store_og_category_image_path'] = $this->ogCategoryImageUpload->store('store-settings', 'public');
            }
            if ($this->ogProductImageUpload) {
                $payload['store_og_product_image_path'] = $this->ogProductImageUpload->store('store-settings', 'public');
            }
            if ($this->ogPageImageUpload) {
                $payload['store_og_page_image_path'] = $this->ogPageImageUpload->store('store-settings', 'public');
            }
            if ($this->ogBlogImageUpload) {
                $payload['store_og_blog_image_path'] = $this->ogBlogImageUpload->store('store-settings', 'public');
            }
        }

        return $payload;
    }

    private function resetUploadsForCurrentTab(): void
    {
        foreach ($this->currentTabUploadKeys() as $uploadKey) {
            $this->{$uploadKey} = null;
        }
    }

    /**
     * @param  array<int, int>  $allowedIds
     * @return array<int, int>
     */
    private function filterIdList(mixed $value, array $allowedIds): array
    {
        $allowed = array_fill_keys($allowedIds, true);

        return array_values(array_filter(
            $this->normalizeIdList($value),
            static fn (int $id): bool => isset($allowed[$id])
        ));
    }

    private function renderSquarePng(mixed $source, int $size): ?string
    {
        $target = imagecreatetruecolor($size, $size);
        if (! $target) {
            return null;
        }

        imagealphablending($target, false);
        imagesavealpha($target, true);
        $transparent = imagecolorallocatealpha($target, 0, 0, 0, 127);
        imagefill($target, 0, 0, $transparent);

        $srcW = (int) imagesx($source);
        $srcH = (int) imagesy($source);
        $srcMin = max(1, min($srcW, $srcH));
        $srcX = (int) floor(($srcW - $srcMin) / 2);
        $srcY = (int) floor(($srcH - $srcMin) / 2);

        imagecopyresampled($target, $source, 0, 0, $srcX, $srcY, $size, $size, $srcMin, $srcMin);

        ob_start();
        imagepng($target, null, 9);
        $binary = ob_get_clean();
        imagedestroy($target);

        return is_string($binary) ? $binary : null;
    }

    private function renderIcoFromImage(mixed $source, int $size): ?string
    {
        $pngBinary = $this->renderSquarePng($source, $size);
        if ($pngBinary === null) {
            return null;
        }

        $widthByte = $size >= 256 ? 0 : $size;
        $heightByte = $size >= 256 ? 0 : $size;
        $dataSize = strlen($pngBinary);
        $offset = 6 + 16;

        $header = pack('vvv', 0, 1, 1);
        $directory = pack(
            'CCCCvvVV',
            $widthByte,
            $heightByte,
            0,
            0,
            1,
            32,
            $dataSize,
            $offset
        );

        return $header.$directory.$pngBinary;
    }
}
