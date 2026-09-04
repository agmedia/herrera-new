<div class="space-y-6">
    <div class="admin-panel admin-search-panel p-6">
        <h1 class="text-xl font-semibold tracking-tight">{{ __('Store Settings') }}</h1>
        <p class="mt-2 text-sm text-slate-600">{{ __('Central place for storefront email, branding, newsletter, and announcement bar settings.') }}</p>
    </div>

    <div class="admin-panel admin-form-panel p-6">
        <div class="mb-4 flex flex-wrap gap-2">
            @foreach ([
                'email' => 'Email',
                'branding' => 'Branding & Footer',
                'newsletter' => 'Newsletter',
                'integrations' => 'Integrations',
                'pricing' => 'Pricing',
                'images' => 'Images',
                'products' => 'admin.settings.store.products.tab',
                'seo' => 'SEO',
                'og' => 'OG / Twitter',
                'schema' => 'Schema Markup',
                'announcement' => 'Announcement bar',
                'cookies' => 'Cookies',
            ] as $tabKey => $tabLabel)
                <button type="button" wire:click="$set('tab', '{{ $tabKey }}')" class="rounded-xl border px-3 py-1.5 text-xs font-semibold {{ $tab === $tabKey ? 'border-cyan-700 bg-cyan-700 text-white' : 'border-slate-300 text-slate-700 hover:bg-slate-100' }}">
                    {{ __($tabLabel) }}
                </button>
            @endforeach
        </div>
        <p class="mb-4 text-xs text-slate-500">{{ __('Saving applies only to the currently open tab.') }}</p>

        @if ($this->hasLocalizedSettingsForCurrentTab())
            <div class="mb-4 flex flex-wrap items-end gap-3 rounded-xl border border-cyan-200 bg-cyan-50 p-3">
                <div class="w-32">
                    <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-cyan-800">{{ __('admin.common.locale') }}</label>
                    <select wire:model.live="locale" data-tom-select data-tom-no-search="1" class="admin-search-input admin-select w-full rounded-xl border px-3 py-2 text-sm lowercase">
                        @foreach ($adminLocaleOptions as $localeOption)
                            <option value="{{ $localeOption }}">{{ $localeOption }}</option>
                        @endforeach
                    </select>
                </div>
                <p class="pb-2 text-xs text-cyan-900">{{ __('Text fields in this tab are saved separately for each language. Inactive languages remain available here for translation.') }}</p>
            </div>
        @endif

        <form wire:submit="save" class="space-y-4">
            @if ($errors->any())
                <div class="rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-900">
                    <p class="font-semibold">{{ __('Please review the highlighted fields before saving this tab.') }}</p>
                </div>
            @endif

            @if ($tab === 'email')
                <div class="grid gap-4 md:grid-cols-2">
                    <label class="inline-flex items-center gap-2 text-sm font-semibold text-slate-700 md:col-span-2">
                        <input type="checkbox" wire:model="form.store_email_enabled" class="rounded border-slate-300 text-cyan-700 focus:ring-cyan-500" />
                        {{ __('Enable store email notifications') }}
                    </label>
                    <div>
                        <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Mailer') }}</label>
                        <select wire:model="form.store_email_mailer" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">
                            <option value="smtp">{{ __('SMTP') }}</option>
                            <option value="sendmail">{{ __('Sendmail') }}</option>
                            <option value="log">{{ __('Log') }}</option>
                        </select>
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('From Address') }}</label>
                        <input type="email" wire:model="form.store_email_from_address" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm" />
                        @error('form.store_email_from_address') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('From Name') }}</label>
                        <input type="text" wire:model="form.store_email_from_name" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm" />
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Reply-To') }}</label>
                        <input type="email" wire:model="form.store_email_reply_to" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm" />
                        @error('form.store_email_reply_to') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Orders To') }}</label>
                        <input type="email" wire:model="form.store_email_orders_to" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm" />
                        @error('form.store_email_orders_to') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Contact Forms To') }}</label>
                        <input type="email" wire:model="form.store_email_contact_to" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm" />
                        @error('form.store_email_contact_to') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                    </div>
                    <div class="md:col-span-2 grid gap-3 md:grid-cols-3">
                        <div>
                            <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('SMTP Host') }}</label>
                            <input type="text" wire:model="form.store_email_smtp_host" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm" />
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('SMTP Port') }}</label>
                            <input type="number" wire:model="form.store_email_smtp_port" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm" />
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Encryption') }}</label>
                            <select wire:model="form.store_email_smtp_encryption" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">
                                <option value="">{{ __('None') }}</option>
                                <option value="tls">{{ __('TLS') }}</option>
                                <option value="ssl">{{ __('SSL') }}</option>
                            </select>
                        </div>
                    </div>
                    <div>
                            <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('SMTP Username') }}</label>
                        <input type="text" wire:model="form.store_email_smtp_username" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm" />
                    </div>
                    <div>
                            <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('SMTP Password') }}</label>
                        <input type="password" wire:model="form.store_email_smtp_password" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm" />
                    </div>
                    <div class="md:col-span-2">
                        <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Sendmail Path') }}</label>
                        <input type="text" wire:model="form.store_email_sendmail_path" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm" />
                    </div>
                </div>
            @endif

            @if ($tab === 'branding')
                <div class="grid gap-4 md:grid-cols-2">
                    <div>
                        <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Store Name') }}</label>
                        <input type="text" wire:model="form.store_brand_name" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm" />
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Footer Phone') }}</label>
                        <input type="text" wire:model="form.store_footer_phone" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm" />
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Footer Sales Email') }}</label>
                        <input type="email" wire:model="form.store_footer_email_sales" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm" />
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Footer Support Email') }}</label>
                        <input type="email" wire:model="form.store_footer_email_support" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm" />
                    </div>
                    <div class="md:col-span-2">
                        <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Footer Working Hours') }}</label>
                        <input type="text" wire:model="form.store_footer_hours" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm" />
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Footer Contact Title') }}</label>
                        <input type="text" wire:model="form.store_footer_contact_title" placeholder="{{ __('ui.front.desktop.footer.support') }}" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm" />
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Footer Contact Intro') }}</label>
                        <input type="text" wire:model="form.store_footer_contact_intro" placeholder="{{ __('ui.front.desktop.footer.webshop_queries') }}" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm" />
                    </div>
                    <div class="md:col-span-2 rounded-xl border border-slate-200 bg-slate-50 p-4">
                        <h3 class="text-sm font-bold text-slate-800">{{ __('Footer Link Columns') }}</h3>
                        <p class="mt-1 text-xs text-slate-600">{{ __('Configure 3 footer columns: title + product categories + pages + custom links.') }}</p>
                    </div>
                    @foreach ([1, 2, 3] as $col)
                        <div class="md:col-span-2 rounded-xl border border-slate-200 p-4">
                            <p class="mb-3 text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Column') }} {{ $col }}</p>
                            <div class="grid gap-3 md:grid-cols-4">
                                <div>
                                    <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Title') }}</label>
                                    <input type="text" wire:model="form.store_footer_col_{{ $col }}_title" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm" />
                                </div>
                                <div>
                                    <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Product Categories') }}</label>
                                    <div class="max-h-40 space-y-1 overflow-auto rounded-xl border border-slate-300 p-2 text-sm">
                                        @foreach (($catalogCategoryOptions ?? []) as $option)
                                            <label class="flex items-center gap-2">
                                                <input type="checkbox" wire:model="form.store_footer_col_{{ $col }}_category_ids" value="{{ (int) $option['id'] }}" class="rounded border-slate-300 text-cyan-700 focus:ring-cyan-500">
                                                <span>{{ (string) $option['label'] }}</span>
                                            </label>
                                        @endforeach
                                    </div>
                                </div>
                                <div>
                                    <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Pages') }}</label>
                                    <div class="max-h-40 space-y-1 overflow-auto rounded-xl border border-slate-300 p-2 text-sm">
                                        @foreach (($pageOptions ?? []) as $option)
                                            <label class="flex items-center gap-2">
                                                <input type="checkbox" wire:model="form.store_footer_col_{{ $col }}_page_ids" value="{{ (int) $option['id'] }}" class="rounded border-slate-300 text-cyan-700 focus:ring-cyan-500">
                                                <span>{{ (string) $option['label'] }}</span>
                                            </label>
                                        @endforeach
                                    </div>
                                </div>
                                <div>
                                    <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Custom Links') }}</label>
                                    <textarea wire:model="form.store_footer_col_{{ $col }}_custom_links" rows="6" class="w-full rounded-xl border border-slate-300 px-3 py-2 font-mono text-xs" placeholder="{{ __('Blog|/blog&#10;FAQ|/page/faq&#10;Kontakt|/contact') }}"></textarea>
                                    <p class="mt-1 text-xs text-slate-500">{{ __('One per line:') }} <code>Label|URL</code></p>
                                </div>
                            </div>
                        </div>
                    @endforeach
                    <div class="md:col-span-2 rounded-xl border border-slate-200 p-4">
                        <p class="mb-3 text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Bottom Footer Bar') }}</p>
                        <div class="grid gap-3 md:grid-cols-2">
                            <div>
                                <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Copyright text') }}</label>
                                <input type="text" wire:model="form.store_footer_bottom_copyright_text" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm" placeholder="{{ __('Sva prava pridržana.') }}" />
                            </div>
                            <div>
                                <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Bottom links (pages)') }}</label>
                                <div class="max-h-40 space-y-1 overflow-auto rounded-xl border border-slate-300 p-2 text-sm">
                                    @foreach (($pageOptions ?? []) as $option)
                                        <label class="flex items-center gap-2">
                                            <input type="checkbox" wire:model="form.store_footer_bottom_link_page_ids" value="{{ (int) $option['id'] }}" class="rounded border-slate-300 text-cyan-700 focus:ring-cyan-500">
                                            <span>{{ (string) $option['label'] }}</span>
                                        </label>
                                    @endforeach
                                </div>
                                <p class="mt-1 text-xs text-slate-500">{{ __('Order in this list = display order in footer.') }}</p>
                            </div>
                        </div>
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Logo') }}</label>
                        <input type="file" wire:model="logoUpload" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm" />
                        <p class="mt-1 text-xs text-slate-500">{{ __('Allowed: JPG, PNG, WEBP, AVIF, SVG') }}</p>
                        @if ($form['store_brand_logo_path'])
                            <p class="mt-1 text-xs text-slate-500">{{ __('Current:') }} {{ $form['store_brand_logo_path'] }}</p>
                        @endif
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Favicon') }}</label>
                        <input type="file" wire:model="faviconUpload" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm" />
                        <p class="mt-1 text-xs text-slate-500">{{ __('Auto-generate: 16, 32, 180, 192, 512 and ICO.') }}</p>
                        @if ($form['store_brand_favicon_path'])
                            <p class="mt-1 text-xs text-slate-500">{{ __('Current:') }} {{ $form['store_brand_favicon_path'] }}</p>
                        @endif
                    </div>
                    <div class="md:col-span-2 grid gap-3 md:grid-cols-2">
                        <div>
                            <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Facebook URL') }}</label>
                            <input type="url" wire:model="form.store_social_facebook_url" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm" />
                            <label class="mt-2 inline-flex items-center gap-2 text-xs font-semibold text-slate-700">
                                <input type="checkbox" wire:model="form.store_footer_social_facebook_enabled" class="rounded border-slate-300 text-cyan-700 focus:ring-cyan-500" />
                                {{ __('Show in footer') }}
                            </label>
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Instagram URL') }}</label>
                            <input type="url" wire:model="form.store_social_instagram_url" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm" />
                            <label class="mt-2 inline-flex items-center gap-2 text-xs font-semibold text-slate-700">
                                <input type="checkbox" wire:model="form.store_footer_social_instagram_enabled" class="rounded border-slate-300 text-cyan-700 focus:ring-cyan-500" />
                                {{ __('Show in footer') }}
                            </label>
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('TikTok URL') }}</label>
                            <input type="url" wire:model="form.store_social_tiktok_url" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm" />
                            <label class="mt-2 inline-flex items-center gap-2 text-xs font-semibold text-slate-700">
                                <input type="checkbox" wire:model="form.store_footer_social_tiktok_enabled" class="rounded border-slate-300 text-cyan-700 focus:ring-cyan-500" />
                                {{ __('Show in footer') }}
                            </label>
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('YouTube URL') }}</label>
                            <input type="url" wire:model="form.store_social_youtube_url" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm" />
                            <label class="mt-2 inline-flex items-center gap-2 text-xs font-semibold text-slate-700">
                                <input type="checkbox" wire:model="form.store_footer_social_youtube_enabled" class="rounded border-slate-300 text-cyan-700 focus:ring-cyan-500" />
                                {{ __('Show in footer') }}
                            </label>
                        </div>
                    </div>
                </div>
            @endif

            @if ($tab === 'newsletter')
                <div class="grid gap-4 md:grid-cols-2">
                    <div class="md:col-span-2 rounded-xl border border-slate-200 bg-slate-50 p-4">
                        <p class="text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">{{ __('Footer Newsletter Copy') }}</p>
                        <div class="mt-3 grid gap-3 md:grid-cols-2">
                            <div>
                                <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Eyebrow') }}</label>
                                <input type="text" wire:model="form.store_newsletter_club_label" placeholder="{{ __('ui.front.desktop.newsletter.club') }}" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm" />
                                @error('form.store_newsletter_club_label') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Button Label') }}</label>
                                <input type="text" wire:model="form.store_newsletter_button_label" placeholder="{{ __('ui.front.desktop.newsletter.button') }}" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm" />
                                @error('form.store_newsletter_button_label') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                            </div>
                            <div class="md:col-span-2">
                                <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Title') }}</label>
                                <input type="text" wire:model="form.store_newsletter_title" placeholder="{{ __('ui.front.desktop.newsletter.title') }}" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm" />
                                @error('form.store_newsletter_title') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                            </div>
                            <div class="md:col-span-2">
                                <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Subtitle') }}</label>
                                <input type="text" wire:model="form.store_newsletter_subtitle" placeholder="{{ __('ui.front.desktop.newsletter.subtitle') }}" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm" />
                                @error('form.store_newsletter_subtitle') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                            </div>
                            <div class="md:col-span-2">
                                <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Consent Label') }}</label>
                                <input type="text" wire:model="form.store_newsletter_consent_label" placeholder="{{ __('ui.front.desktop.newsletter.consent') }}" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm" />
                                @error('form.store_newsletter_consent_label') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                            </div>
                        </div>
                    </div>

                    <div class="md:col-span-2">
                        <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Provider') }}</label>
                        <select wire:model="form.store_newsletter_provider" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm md:w-72">
                            <option value="none">{{ __('None') }}</option>
                            <option value="database">{{ __('Database') }}</option>
                            <option value="mailchimp">{{ __('Mailchimp') }}</option>
                            <option value="klaviyo">{{ __('Klaviyo') }}</option>
                        </select>
                        <p class="mt-2 text-xs text-slate-500">{{ __('Database stores signups locally and shows them in Users / Newsletter Signups.') }}</p>
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Mailchimp API Key') }}</label>
                        <input type="text" wire:model="form.store_newsletter_mailchimp_api_key" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm" />
                        @error('form.store_newsletter_mailchimp_api_key') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Mailchimp List ID') }}</label>
                        <input type="text" wire:model="form.store_newsletter_mailchimp_list_id" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm" />
                        @error('form.store_newsletter_mailchimp_list_id') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Klaviyo API Key') }}</label>
                        <input type="text" wire:model="form.store_newsletter_klaviyo_api_key" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm" />
                        @error('form.store_newsletter_klaviyo_api_key') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Klaviyo List ID') }}</label>
                        <input type="text" wire:model="form.store_newsletter_klaviyo_list_id" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm" />
                        @error('form.store_newsletter_klaviyo_list_id') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                    </div>
                </div>
            @endif

            @if ($tab === 'integrations')
                <div class="grid gap-4 md:grid-cols-2">
                    <div class="md:col-span-2 rounded-xl border border-slate-200 bg-slate-50 p-4">
                        <h3 class="text-sm font-bold text-slate-800">{{ __('reCAPTCHA v3') }}</h3>
                        <p class="mt-1 text-xs text-slate-600">{{ __('Protect forms (contact, checkout later) from spam bots.') }}</p>
                    </div>
                    <label class="inline-flex items-center gap-2 text-sm font-semibold text-slate-700 md:col-span-2">
                        <input type="checkbox" wire:model="form.store_captcha_recaptcha_v3_enabled" class="rounded border-slate-300 text-cyan-700 focus:ring-cyan-500" />
                        {{ __('Enable reCAPTCHA v3') }}
                    </label>
                    <div>
                        <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Site key') }}</label>
                        <input type="text" wire:model="form.store_captcha_recaptcha_v3_site_key" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm" />
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Secret key') }}</label>
                        <input type="password" wire:model="form.store_captcha_recaptcha_v3_secret_key" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm" />
                    </div>
                    <div class="md:col-span-2 md:w-56">
                        <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Min score') }}</label>
                        <input type="number" step="0.1" min="0" max="1" wire:model="form.store_captcha_recaptcha_v3_min_score" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm" />
                    </div>

                    <div class="md:col-span-2 rounded-xl border border-slate-200 bg-slate-50 p-4 mt-2">
                        <h3 class="text-sm font-bold text-slate-800">{{ __('Google Analytics (GA4)') }}</h3>
                        <p class="mt-1 text-xs text-slate-600">{{ __('Inject global gtag script and push purchase event on checkout success.') }}</p>
                    </div>
                    <label class="inline-flex items-center gap-2 text-sm font-semibold text-slate-700 md:col-span-2">
                        <input type="checkbox" wire:model="form.store_analytics_enabled" class="rounded border-slate-300 text-cyan-700 focus:ring-cyan-500" />
                        {{ __('Enable GA4 tracking') }}
                    </label>
                    <div>
                        <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('GA4 Measurement ID') }}</label>
                        <input type="text" wire:model="form.store_analytics_ga4_measurement_id" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm" placeholder="G-XXXXXXXXXX" />
                    </div>
                    <div></div>
                    <label class="inline-flex items-center gap-2 text-sm font-semibold text-slate-700 md:col-span-2">
                        <input type="checkbox" wire:model="form.store_analytics_purchase_event_enabled" class="rounded border-slate-300 text-cyan-700 focus:ring-cyan-500" />
                        {{ __('Enable purchase event on successful checkout') }}
                    </label>
                    <div class="md:w-72">
                        <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Purchase event name') }}</label>
                        <input type="text" wire:model="form.store_analytics_purchase_event_name" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm" placeholder="purchase" />
                    </div>

                </div>
            @endif

            @if ($tab === 'pricing')
                <div class="grid gap-4 md:grid-cols-2">
                    <div class="md:col-span-2 rounded-xl border border-slate-200 bg-slate-50 p-4">
                        <h3 class="text-sm font-bold text-slate-800">{{ __('Pricing') }}</h3>
                        <p class="mt-1 text-xs text-slate-600">{{ __('Choose if stored catalog prices are entered with VAT included or VAT excluded.') }}</p>
                    </div>
                    <label class="inline-flex items-center gap-2 text-sm font-semibold text-slate-700 md:col-span-2">
                        <input type="checkbox" wire:model="form.store_pricing_prices_include_tax" class="rounded border-slate-300 text-cyan-700 focus:ring-cyan-500" />
                        {{ __('Product prices include VAT') }}
                    </label>
                </div>
            @endif

            @if ($tab === 'images')
                <div class="grid gap-4 md:grid-cols-2" @if(($webpGeneration['running'] ?? false) === true) wire:poll.1500ms="processWebpGenerationStep" @endif>
                    <div class="md:col-span-2 rounded-xl border border-slate-200 bg-slate-50 p-4">
                        <h3 class="text-sm font-bold text-slate-800">{{ __('admin.settings.store.images.title') }}</h3>
                        <p class="mt-1 text-xs text-slate-600">{{ __('admin.settings.store.images.subtitle') }}</p>
                    </div>

                    <label class="inline-flex items-center gap-2 text-sm font-semibold text-slate-700 md:col-span-2">
                        <input type="checkbox" wire:model="form.store_images_use_webp" class="rounded border-slate-300 text-cyan-700 focus:ring-cyan-500" />
                        {{ __('admin.settings.store.images.use_webp') }}
                    </label>

                    <div class="md:col-span-2 rounded-xl border border-slate-200 p-4">
                        <div class="flex flex-wrap items-center gap-3">
                            <button
                                type="button"
                                wire:click="startWebpGeneration"
                                class="rounded-xl border border-cyan-700 bg-cyan-700 px-3 py-2 text-xs font-semibold text-white hover:bg-cyan-800 disabled:cursor-not-allowed disabled:opacity-60"
                                @disabled(($webpGeneration['running'] ?? false) === true)
                            >
                                {{ __('admin.settings.store.images.generate') }}
                            </button>

                            <span class="text-xs text-slate-600">
                                {{ __('admin.settings.store.images.processed') }} {{ (int) ($webpGeneration['processed'] ?? 0) }} / {{ (int) ($webpGeneration['total'] ?? 0) }}
                                @if (($webpGeneration['failed'] ?? 0) > 0)
                                    • {{ __('admin.settings.store.images.failed') }} {{ (int) ($webpGeneration['failed'] ?? 0) }}
                                @endif
                            </span>
                        </div>

                        <div class="mt-3 h-2 w-full overflow-hidden rounded-full bg-slate-200">
                            @php
                                $total = (int) ($webpGeneration['total'] ?? 0);
                                $processed = (int) ($webpGeneration['processed'] ?? 0);
                                $percent = $total > 0 ? min(100, (int) round(($processed / $total) * 100)) : 0;
                            @endphp
                            <div class="h-full bg-cyan-600 transition-all duration-300" style="width: {{ $percent }}%"></div>
                        </div>

                        <p class="mt-2 text-xs text-slate-600">
                            @if (($webpGeneration['running'] ?? false) === true)
                                {{ __('admin.settings.store.images.status_running') }} ({{ $percent }}%)
                            @elseif (($webpGeneration['finished'] ?? false) === true)
                                {{ __('admin.settings.store.images.status_finished') }}
                            @else
                                {{ __('admin.settings.store.images.status_idle') }}
                            @endif
                        </p>

                        @if (($webpGeneration['current_id'] ?? null) || ($webpGeneration['last_processed_id'] ?? null))
                            <p class="mt-1 text-xs text-slate-500">
                                @if (($webpGeneration['current_id'] ?? null))
                                    Trenutno: media #{{ (int) $webpGeneration['current_id'] }} @if (($webpGeneration['current_collection'] ?? null)) ({{ $webpGeneration['current_collection'] }}) @endif
                                @else
                                    Zadnje obrađeno: media #{{ (int) $webpGeneration['last_processed_id'] }} @if (($webpGeneration['last_processed_collection'] ?? null)) ({{ $webpGeneration['last_processed_collection'] }}) @endif
                                @endif
                            </p>
                        @endif
                    </div>
                </div>
            @endif

            @if ($tab === 'products')
                <div class="grid gap-4 md:grid-cols-2">
                    <div class="md:col-span-2 rounded-xl border border-slate-200 bg-slate-50 p-4">
                        <h3 class="text-sm font-bold text-slate-800">{{ __('admin.settings.store.products.title') }}</h3>
                        <p class="mt-1 text-xs text-slate-600">{{ __('admin.settings.store.products.subtitle') }}</p>
                    </div>
                    <label class="inline-flex items-center gap-2 text-sm font-semibold text-slate-700 md:col-span-2">
                        <input type="checkbox" wire:model="form.store_product_fit_finder_enabled" class="rounded border-slate-300 text-cyan-700 focus:ring-cyan-500" />
                        {{ __('admin.settings.store.products.fit_finder_enabled') }}
                    </label>
                    <div class="md:col-span-2 rounded-xl border border-slate-200 bg-slate-50 p-4">
                        <label class="inline-flex items-center gap-2 text-sm font-bold text-slate-800">
                            <input type="checkbox" wire:model="form.store_search_autocomplete_enabled" class="rounded border-slate-300 text-cyan-700 focus:ring-cyan-500" />
                            {{ __('admin.settings.store.products.search_autocomplete_enabled') }}
                        </label>
                        <p class="mt-1 text-xs text-slate-600">{{ __('admin.settings.store.products.search_autocomplete_help') }}</p>
                    </div>
                    <div class="md:col-span-2 grid gap-4 xl:grid-cols-2">
                        <div class="rounded-xl border border-slate-200 p-4">
                            <div class="flex flex-wrap items-center justify-between gap-3">
                                <label class="inline-flex items-center gap-2 text-sm font-semibold text-slate-700">
                                    <input type="checkbox" wire:model="form.store_search_autocomplete_products_enabled" class="rounded border-slate-300 text-cyan-700 focus:ring-cyan-500" />
                                    {{ __('admin.settings.store.products.search_autocomplete_products_enabled') }}
                                </label>
                                <label class="flex items-center gap-2 text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">
                                    {{ __('admin.settings.store.products.search_autocomplete_max_results') }}
                                    <input type="number" min="1" max="12" wire:model="form.store_search_autocomplete_products_limit" class="admin-search-input w-20 rounded-lg border border-slate-300 px-2 py-1.5 text-sm text-slate-800" />
                                </label>
                            </div>
                            <p class="mt-3 text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">{{ __('admin.settings.store.products.search_autocomplete_product_details') }}</p>
                            <div class="mt-2 flex flex-wrap gap-x-5 gap-y-2">
                                <label class="inline-flex items-center gap-2 text-sm text-slate-700">
                                    <input type="checkbox" wire:model="form.store_search_autocomplete_show_product_image" class="rounded border-slate-300 text-cyan-700 focus:ring-cyan-500" />
                                    {{ __('admin.settings.store.products.search_autocomplete_show_image') }}
                                </label>
                                <label class="inline-flex items-center gap-2 text-sm text-slate-700">
                                    <input type="checkbox" wire:model="form.store_search_autocomplete_show_product_brand" class="rounded border-slate-300 text-cyan-700 focus:ring-cyan-500" />
                                    {{ __('admin.settings.store.products.search_autocomplete_show_brand') }}
                                </label>
                                <label class="inline-flex items-center gap-2 text-sm text-slate-700">
                                    <input type="checkbox" wire:model="form.store_search_autocomplete_show_product_sku" class="rounded border-slate-300 text-cyan-700 focus:ring-cyan-500" />
                                    {{ __('admin.settings.store.products.search_autocomplete_show_sku') }}
                                </label>
                                <label class="inline-flex items-center gap-2 text-sm text-slate-700">
                                    <input type="checkbox" wire:model="form.store_search_autocomplete_show_product_price" class="rounded border-slate-300 text-cyan-700 focus:ring-cyan-500" />
                                    {{ __('admin.settings.store.products.search_autocomplete_show_price') }}
                                </label>
                            </div>
                        </div>

                        <div class="rounded-xl border border-slate-200 p-4">
                            <div class="flex flex-wrap items-center justify-between gap-3">
                                <label class="inline-flex items-center gap-2 text-sm font-semibold text-slate-700">
                                    <input type="checkbox" wire:model="form.store_search_autocomplete_categories_enabled" class="rounded border-slate-300 text-cyan-700 focus:ring-cyan-500" />
                                    {{ __('admin.settings.store.products.search_autocomplete_categories_enabled') }}
                                </label>
                                <label class="flex items-center gap-2 text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">
                                    {{ __('admin.settings.store.products.search_autocomplete_max_results') }}
                                    <input type="number" min="1" max="10" wire:model="form.store_search_autocomplete_categories_limit" class="admin-search-input w-20 rounded-lg border border-slate-300 px-2 py-1.5 text-sm text-slate-800" />
                                </label>
                            </div>
                        </div>

                        <div class="rounded-xl border border-slate-200 p-4">
                            <div class="flex flex-wrap items-center justify-between gap-3">
                                <label class="inline-flex items-center gap-2 text-sm font-semibold text-slate-700">
                                    <input type="checkbox" wire:model="form.store_search_autocomplete_manufacturers_enabled" class="rounded border-slate-300 text-cyan-700 focus:ring-cyan-500" />
                                    {{ __('admin.settings.store.products.search_autocomplete_manufacturers_enabled') }}
                                </label>
                                <label class="flex items-center gap-2 text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">
                                    {{ __('admin.settings.store.products.search_autocomplete_max_results') }}
                                    <input type="number" min="1" max="10" wire:model="form.store_search_autocomplete_manufacturers_limit" class="admin-search-input w-20 rounded-lg border border-slate-300 px-2 py-1.5 text-sm text-slate-800" />
                                </label>
                            </div>
                        </div>

                        <div class="rounded-xl border border-slate-200 p-4">
                            <div class="flex flex-wrap items-center justify-between gap-3">
                                <label class="inline-flex items-center gap-2 text-sm font-semibold text-slate-700">
                                    <input type="checkbox" wire:model="form.store_search_autocomplete_blog_enabled" class="rounded border-slate-300 text-cyan-700 focus:ring-cyan-500" />
                                    {{ __('admin.settings.store.products.search_autocomplete_blog_enabled') }}
                                </label>
                                <label class="flex items-center gap-2 text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">
                                    {{ __('admin.settings.store.products.search_autocomplete_max_results') }}
                                    <input type="number" min="1" max="10" wire:model="form.store_search_autocomplete_blog_limit" class="admin-search-input w-20 rounded-lg border border-slate-300 px-2 py-1.5 text-sm text-slate-800" />
                                </label>
                            </div>
                        </div>
                    </div>
                    <div class="md:col-span-2 rounded-xl border border-slate-200 p-4">
                        <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('admin.settings.store.products.desktop_default_grid_label') }}</label>
                        <p class="mb-3 text-xs text-slate-600">{{ __('admin.settings.store.products.desktop_default_grid_help') }}</p>
                        <div class="inline-flex overflow-hidden rounded-xl border border-slate-300">
                            <label class="inline-flex cursor-pointer items-center gap-2 border-r border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700">
                                <input type="radio" wire:model="form.store_product_desktop_default_cols" value="4" class="border-slate-300 text-cyan-700 focus:ring-cyan-500" />
                                {{ __('admin.settings.store.products.desktop_grid_four') }}
                            </label>
                            <label class="inline-flex cursor-pointer items-center gap-2 px-4 py-2 text-sm font-semibold text-slate-700">
                                <input type="radio" wire:model="form.store_product_desktop_default_cols" value="5" class="border-slate-300 text-cyan-700 focus:ring-cyan-500" />
                                {{ __('admin.settings.store.products.desktop_grid_five') }}
                            </label>
                        </div>
                    </div>
                    <div class="md:col-span-2 rounded-xl border border-slate-200 p-4">
                        <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('admin.settings.store.products.mobile_default_grid_label') }}</label>
                        <p class="mb-3 text-xs text-slate-600">{{ __('admin.settings.store.products.mobile_default_grid_help') }}</p>
                        <div class="inline-flex overflow-hidden rounded-xl border border-slate-300">
                            <label class="inline-flex cursor-pointer items-center gap-2 border-r border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700">
                                <input type="radio" wire:model="form.store_product_mobile_default_cols" value="1" class="border-slate-300 text-cyan-700 focus:ring-cyan-500" />
                                {{ __('admin.settings.store.products.mobile_grid_one') }}
                            </label>
                            <label class="inline-flex cursor-pointer items-center gap-2 px-4 py-2 text-sm font-semibold text-slate-700">
                                <input type="radio" wire:model="form.store_product_mobile_default_cols" value="2" class="border-slate-300 text-cyan-700 focus:ring-cyan-500" />
                                {{ __('admin.settings.store.products.mobile_grid_two') }}
                            </label>
                        </div>
                    </div>
                    <div class="md:col-span-2 rounded-xl border border-slate-200 p-4">
                        <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('admin.settings.store.products.catalog_pagination_label') }}</label>
                        <p class="mb-3 text-xs text-slate-600">{{ __('admin.settings.store.products.catalog_pagination_help') }}</p>
                        <div class="inline-flex overflow-hidden rounded-xl border border-slate-300">
                            <label class="inline-flex cursor-pointer items-center gap-2 border-r border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700">
                                <input type="radio" wire:model="form.store_product_catalog_pagination_mode" value="pagination" class="border-slate-300 text-cyan-700 focus:ring-cyan-500" />
                                {{ __('admin.settings.store.products.catalog_pagination_mode_pagination') }}
                            </label>
                            <label class="inline-flex cursor-pointer items-center gap-2 border-r border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700">
                                <input type="radio" wire:model="form.store_product_catalog_pagination_mode" value="load_more" class="border-slate-300 text-cyan-700 focus:ring-cyan-500" />
                                {{ __('admin.settings.store.products.catalog_pagination_mode_load_more') }}
                            </label>
                            <label class="inline-flex cursor-pointer items-center gap-2 px-4 py-2 text-sm font-semibold text-slate-700">
                                <input type="radio" wire:model="form.store_product_catalog_pagination_mode" value="infinite" class="border-slate-300 text-cyan-700 focus:ring-cyan-500" />
                                {{ __('admin.settings.store.products.catalog_pagination_mode_infinite') }}
                            </label>
                        </div>
                    </div>
                    <div class="md:col-span-2 rounded-xl border border-slate-200 p-4">
                        @php
                            $selectedOptionFilterIds = collect($form['store_product_filter_option_ids'] ?? [])->map(fn ($id): int => (int) $id);
                            $selectedAttributeFilterGroups = collect($form['store_product_filter_attribute_group_codes'] ?? [])->map(fn ($code): string => (string) $code);
                            $filterPanelSettings = is_array($form['store_product_filter_panel_settings'] ?? null)
                                ? $form['store_product_filter_panel_settings']
                                : [];
                            $builtInFilterPanels = [
                                ['panel_key' => 'category', 'label' => __('admin.settings.store.products.filter_builtin_category')],
                                ['panel_key' => 'manufacturer', 'label' => __('admin.settings.store.products.filter_builtin_manufacturer')],
                                ['panel_key' => 'price', 'label' => __('admin.settings.store.products.filter_builtin_price')],
                            ];
                        @endphp
                        <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('admin.settings.store.products.filter_panels_title') }}</label>
                        <p class="mb-4 text-xs text-slate-600">{{ __('admin.settings.store.products.filter_panels_help') }}</p>

                        <div class="overflow-x-auto rounded-xl border border-slate-200">
                            <div class="grid min-w-[720px] grid-cols-[minmax(240px,1fr)_120px_180px_180px] items-center gap-3 bg-slate-100 px-4 py-2 text-[11px] font-bold uppercase tracking-[0.1em] text-slate-600">
                                <span>{{ __('admin.settings.store.products.filter_panel_name') }}</span>
                                <span>{{ __('admin.settings.store.products.filter_panel_visible') }}</span>
                                <span>{{ __('admin.settings.store.products.filter_panel_default_state') }}</span>
                                <span>{{ __('admin.settings.store.products.filter_panel_max_height') }}</span>
                            </div>

                            @foreach ($builtInFilterPanels as $panel)
                                <div class="grid min-w-[720px] grid-cols-[minmax(240px,1fr)_120px_180px_180px] items-center gap-3 border-t border-slate-200 px-4 py-3 text-sm">
                                    <strong class="text-slate-900">{{ $panel['label'] }}</strong>
                                    <label class="inline-flex items-center gap-2 text-slate-700">
                                        <input type="checkbox" wire:model="form.store_product_filter_panel_settings.{{ $panel['panel_key'] }}.visible" class="rounded border-slate-300 text-cyan-700 focus:ring-cyan-500">
                                        {{ __('admin.settings.store.products.filter_panel_show') }}
                                    </label>
                                    <select wire:model="form.store_product_filter_panel_settings.{{ $panel['panel_key'] }}.default_open" class="rounded-lg border-slate-300 text-sm">
                                        <option value="1">{{ __('admin.settings.store.products.filter_panel_open') }}</option>
                                        <option value="0">{{ __('admin.settings.store.products.filter_panel_closed') }}</option>
                                    </select>
                                    <select wire:model="form.store_product_filter_panel_settings.{{ $panel['panel_key'] }}.max_height" class="rounded-lg border-slate-300 text-sm">
                                        @foreach ([160, 220, 286, 360] as $height)
                                            <option value="{{ $height }}">{{ $height }} px</option>
                                        @endforeach
                                    </select>
                                </div>
                            @endforeach

                            @foreach (($optionFilterOptions ?? []) as $option)
                                <div class="grid min-w-[720px] grid-cols-[minmax(240px,1fr)_120px_180px_180px] items-center gap-3 border-t border-slate-200 px-4 py-3 text-sm">
                                    <strong class="text-slate-900">{{ $option['label'] }}</strong>
                                    <label class="inline-flex items-center gap-2 text-slate-700">
                                        <input type="checkbox" wire:model="form.store_product_filter_option_ids" value="{{ $option['id'] }}" class="rounded border-slate-300 text-cyan-700 focus:ring-cyan-500">
                                        {{ __('admin.settings.store.products.filter_panel_show') }}
                                    </label>
                                    <select wire:model="form.store_product_filter_panel_settings.{{ $option['panel_key'] }}.default_open" class="rounded-lg border-slate-300 text-sm">
                                        <option value="1">{{ __('admin.settings.store.products.filter_panel_open') }}</option>
                                        <option value="0">{{ __('admin.settings.store.products.filter_panel_closed') }}</option>
                                    </select>
                                    <select wire:model="form.store_product_filter_panel_settings.{{ $option['panel_key'] }}.max_height" class="rounded-lg border-slate-300 text-sm">
                                        @foreach ([160, 220, 286, 360] as $height)
                                            <option value="{{ $height }}">{{ $height }} px</option>
                                        @endforeach
                                    </select>
                                </div>
                            @endforeach

                            @foreach (($attributeFilterGroupOptions ?? []) as $group)
                                <div class="grid min-w-[720px] grid-cols-[minmax(240px,1fr)_120px_180px_180px] items-center gap-3 border-t border-slate-200 px-4 py-3 text-sm">
                                    <strong class="text-slate-900">{{ $group['label'] }}</strong>
                                    <label class="inline-flex items-center gap-2 text-slate-700">
                                        <input type="checkbox" wire:model="form.store_product_filter_attribute_group_codes" value="{{ $group['group_code'] }}" class="rounded border-slate-300 text-cyan-700 focus:ring-cyan-500">
                                        {{ __('admin.settings.store.products.filter_panel_show') }}
                                    </label>
                                    <select wire:model="form.store_product_filter_panel_settings.{{ $group['panel_key'] }}.default_open" class="rounded-lg border-slate-300 text-sm">
                                        <option value="1">{{ __('admin.settings.store.products.filter_panel_open') }}</option>
                                        <option value="0">{{ __('admin.settings.store.products.filter_panel_closed') }}</option>
                                    </select>
                                    <select wire:model="form.store_product_filter_panel_settings.{{ $group['panel_key'] }}.max_height" class="rounded-lg border-slate-300 text-sm">
                                        @foreach ([160, 220, 286, 360] as $height)
                                            <option value="{{ $height }}">{{ $height }} px</option>
                                        @endforeach
                                    </select>
                                </div>
                            @endforeach
                        </div>
                    </div>
                </div>
            @endif

            @if ($tab === 'seo')
                <div class="grid gap-4 md:grid-cols-2">
                    <div class="md:col-span-2">
                        <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Default Title') }}</label>
                        <input type="text" wire:model="form.store_seo_default_title" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm" />
                    </div>
                    <div class="md:col-span-2">
                        <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Default Meta Description') }}</label>
                        <textarea wire:model="form.store_seo_default_description" rows="3" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm"></textarea>
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Robots') }}</label>
                        <input type="text" wire:model="form.store_seo_robots" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm" placeholder="index,follow" />
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Canonical policy') }}</label>
                        <select wire:model="form.store_seo_canonical_policy" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">
                            <option value="self">{{ __('Self URL') }}</option>
                            <option value="none">{{ __('Disabled') }}</option>
                        </select>
                    </div>
                </div>
            @endif

            @if ($tab === 'og')
                <div class="grid gap-4 md:grid-cols-2">
                    <div>
                        <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Default OG image') }}</label>
                        <input type="file" wire:model="ogDefaultImageUpload" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm" />
                        @if ($form['store_og_default_image_path'])
                            <p class="mt-1 text-xs text-slate-500">{{ __('Current:') }} {{ $form['store_og_default_image_path'] }}</p>
                        @endif
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Home OG override') }}</label>
                        <input type="file" wire:model="ogHomeImageUpload" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm" />
                        @if ($form['store_og_home_image_path'])
                            <p class="mt-1 text-xs text-slate-500">{{ __('Current:') }} {{ $form['store_og_home_image_path'] }}</p>
                        @endif
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Category OG override') }}</label>
                        <input type="file" wire:model="ogCategoryImageUpload" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm" />
                        @if ($form['store_og_category_image_path'])
                            <p class="mt-1 text-xs text-slate-500">{{ __('Current:') }} {{ $form['store_og_category_image_path'] }}</p>
                        @endif
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Product OG override') }}</label>
                        <input type="file" wire:model="ogProductImageUpload" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm" />
                        @if ($form['store_og_product_image_path'])
                            <p class="mt-1 text-xs text-slate-500">{{ __('Current:') }} {{ $form['store_og_product_image_path'] }}</p>
                        @endif
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Page OG override') }}</label>
                        <input type="file" wire:model="ogPageImageUpload" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm" />
                        @if ($form['store_og_page_image_path'])
                            <p class="mt-1 text-xs text-slate-500">{{ __('Current:') }} {{ $form['store_og_page_image_path'] }}</p>
                        @endif
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Blog OG override') }}</label>
                        <input type="file" wire:model="ogBlogImageUpload" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm" />
                        @if ($form['store_og_blog_image_path'])
                            <p class="mt-1 text-xs text-slate-500">{{ __('Current:') }} {{ $form['store_og_blog_image_path'] }}</p>
                        @endif
                    </div>
                </div>
            @endif

            @if ($tab === 'schema')
                <div class="grid gap-4 md:grid-cols-2">
                    <label class="inline-flex items-center gap-2 text-sm font-semibold text-slate-700 md:col-span-2">
                        <input type="checkbox" wire:model="form.store_schema_enabled" class="rounded border-slate-300 text-cyan-700 focus:ring-cyan-500" />
                        {{ __('Enable schema markup JSON-LD') }}
                    </label>
                    <label class="inline-flex items-center gap-2 text-sm font-semibold text-slate-700">
                        <input type="checkbox" wire:model="form.store_schema_org_enabled" class="rounded border-slate-300 text-cyan-700 focus:ring-cyan-500" />
                        {{ __('Organization schema') }}
                    </label>
                    <label class="inline-flex items-center gap-2 text-sm font-semibold text-slate-700">
                        <input type="checkbox" wire:model="form.store_schema_website_enabled" class="rounded border-slate-300 text-cyan-700 focus:ring-cyan-500" />
                        {{ __('WebSite + SearchAction') }}
                    </label>
                    <label class="inline-flex items-center gap-2 text-sm font-semibold text-slate-700">
                        <input type="checkbox" wire:model="form.store_schema_breadcrumbs_enabled" class="rounded border-slate-300 text-cyan-700 focus:ring-cyan-500" />
                        {{ __('BreadcrumbList') }}
                    </label>
                    <label class="inline-flex items-center gap-2 text-sm font-semibold text-slate-700">
                        <input type="checkbox" wire:model="form.store_schema_itemlist_enabled" class="rounded border-slate-300 text-cyan-700 focus:ring-cyan-500" />
                        {{ __('ItemList (shop/category/blog list)') }}
                    </label>
                    <label class="inline-flex items-center gap-2 text-sm font-semibold text-slate-700">
                        <input type="checkbox" wire:model="form.store_schema_home_enabled" class="rounded border-slate-300 text-cyan-700 focus:ring-cyan-500" />
                        {{ __('Home WebPage') }}
                    </label>
                    <label class="inline-flex items-center gap-2 text-sm font-semibold text-slate-700">
                        <input type="checkbox" wire:model="form.store_schema_category_enabled" class="rounded border-slate-300 text-cyan-700 focus:ring-cyan-500" />
                        {{ __('Category CollectionPage') }}
                    </label>
                    <label class="inline-flex items-center gap-2 text-sm font-semibold text-slate-700">
                        <input type="checkbox" wire:model="form.store_schema_product_enabled" class="rounded border-slate-300 text-cyan-700 focus:ring-cyan-500" />
                        {{ __('Product schema') }}
                    </label>
                    <label class="inline-flex items-center gap-2 text-sm font-semibold text-slate-700">
                        <input type="checkbox" wire:model="form.store_schema_blog_enabled" class="rounded border-slate-300 text-cyan-700 focus:ring-cyan-500" />
                        {{ __('Blog / BlogPosting') }}
                    </label>
                    <label class="inline-flex items-center gap-2 text-sm font-semibold text-slate-700">
                        <input type="checkbox" wire:model="form.store_schema_page_enabled" class="rounded border-slate-300 text-cyan-700 focus:ring-cyan-500" />
                        {{ __('Generic Page schema') }}
                    </label>
                    <label class="inline-flex items-center gap-2 text-sm font-semibold text-slate-700">
                        <input type="checkbox" wire:model="form.store_schema_faq_enabled" class="rounded border-slate-300 text-cyan-700 focus:ring-cyan-500" />
                        {{ __('FAQ schema (home)') }}
                    </label>

                    <div>
                        <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Organization type') }}</label>
                        <select wire:model="form.store_schema_org_type" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">
                            <option value="Organization">{{ __('Organization') }}</option>
                            <option value="LocalBusiness">{{ __('LocalBusiness') }}</option>
                            <option value="Store">{{ __('Store') }}</option>
                        </select>
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Business Name') }}</label>
                        <input type="text" wire:model="form.store_schema_business_name" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm" />
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Business Phone') }}</label>
                        <input type="text" wire:model="form.store_schema_business_phone" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm" />
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Business Email') }}</label>
                        <input type="email" wire:model="form.store_schema_business_email" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm" />
                    </div>

                    <div class="md:col-span-2 grid gap-3 md:grid-cols-3">
                        <div>
                            <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Street') }}</label>
                            <input type="text" wire:model="form.store_schema_address_street" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm" />
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('City') }}</label>
                            <input type="text" wire:model="form.store_schema_address_city" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm" />
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Region') }}</label>
                            <input type="text" wire:model="form.store_schema_address_region" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm" />
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Postal code') }}</label>
                            <input type="text" wire:model="form.store_schema_address_postal_code" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm" />
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Country code') }}</label>
                            <input type="text" wire:model="form.store_schema_address_country" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm" placeholder="HR" />
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Product currency') }}</label>
                            <input type="text" wire:model="form.store_schema_product_currency" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm" placeholder="EUR" />
                        </div>
                    </div>

                    <div class="md:col-span-2">
                        <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('SameAs URLs (one per line)') }}</label>
                        <textarea wire:model="form.store_schema_same_as" rows="4" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm"></textarea>
                    </div>

                    <div>
                        <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Blog author name (default)') }}</label>
                        <input type="text" wire:model="form.store_schema_blog_author_name" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm" />
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Blog author profile URL') }}</label>
                        <input type="url" wire:model="form.store_schema_blog_author_url" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm" />
                    </div>

                    <div>
                        <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('FAQ group code (optional)') }}</label>
                        <input type="text" wire:model="form.store_schema_faq_group" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm" placeholder="support" />
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('FAQ max items') }}</label>
                        <input type="number" min="1" max="20" wire:model="form.store_schema_faq_limit" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm" />
                    </div>
                    <div class="md:col-span-2">
                        <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('ItemList max products/posts') }}</label>
                        <input type="number" min="1" max="48" wire:model="form.store_schema_itemlist_limit" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm md:w-48" />
                    </div>
                </div>
            @endif

            @if ($tab === 'announcement')
                <div class="grid gap-4 md:grid-cols-2">
                    <label class="inline-flex items-center gap-2 text-sm font-semibold text-slate-700 md:col-span-2">
                        <input type="checkbox" wire:model="form.store_announcement_enabled" class="rounded border-slate-300 text-cyan-700 focus:ring-cyan-500" />
                        {{ __('Show top announcement bar') }}
                    </label>
                    <div class="md:col-span-2">
                        <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Text') }}</label>
                        <input type="text" wire:model="form.store_announcement_text" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm" />
                    </div>
                    <div class="md:col-span-2">
                        <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Link URL (optional)') }}</label>
                        <input type="url" wire:model="form.store_announcement_url" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm" />
                    </div>
                    <label class="inline-flex items-center gap-2 text-sm font-semibold text-slate-700 md:col-span-2">
                        <input type="checkbox" wire:model="form.store_announcement_new_tab" class="rounded border-slate-300 text-cyan-700 focus:ring-cyan-500" />
                        {{ __('Open link in new tab') }}
                    </label>
                    <label class="inline-flex items-center gap-2 text-sm font-semibold text-slate-700 md:col-span-2">
                        <input type="checkbox" wire:model="form.store_announcement_scroll_enabled" class="rounded border-slate-300 text-cyan-700 focus:ring-cyan-500" />
                        {{ __('Scroll text from right to left') }}
                    </label>
                    <div class="md:col-span-2">
                        <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Animation speed') }}</label>
                        <div class="flex items-center gap-3">
                            <input type="range" min="6" max="60" step="1" wire:model.live="form.store_announcement_scroll_duration_seconds" class="h-2 w-full cursor-pointer accent-cyan-700" />
                            <div class="flex w-28 items-center gap-1">
                                <input type="number" min="6" max="60" step="1" wire:model="form.store_announcement_scroll_duration_seconds" class="w-20 rounded-xl border border-slate-300 px-3 py-2 text-sm" />
                                <span class="text-sm font-semibold text-slate-600">s</span>
                            </div>
                        </div>
                        @error('form.store_announcement_scroll_duration_seconds') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Background color') }}</label>
                        <input type="color" wire:model="form.store_announcement_background_color" class="h-10 w-20 cursor-pointer rounded-xl border border-slate-300 bg-white p-1" />
                        @error('form.store_announcement_background_color') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Text color') }}</label>
                        <input type="color" wire:model="form.store_announcement_text_color" class="h-10 w-20 cursor-pointer rounded-xl border border-slate-300 bg-white p-1" />
                        @error('form.store_announcement_text_color') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                    </div>

                    <div class="mt-2 border-t border-slate-200 pt-6 md:col-span-2">
                        <h3 class="text-base font-bold text-slate-900">{{ __('Info traka ispod navigacije') }}</h3>
                        <p class="mt-1 text-xs text-slate-600">{{ __('Dio poruke između **dvije zvjezdice** prikazuje se podebljano u boji naglaska.') }}</p>
                        <p class="mt-1 text-xs text-slate-600">{{ __('Boja pozadine i boja teksta iznad primjenjuju se i na ovu info traku.') }}</p>
                    </div>
                    <label class="inline-flex items-center gap-2 text-sm font-semibold text-slate-700 md:col-span-2">
                        <input type="checkbox" wire:model="form.store_benefits_bar_enabled" class="rounded border-slate-300 text-cyan-700 focus:ring-cyan-500" />
                        {{ __('Prikaži info traku ispod glavne navigacije') }}
                    </label>
                    @foreach ([1, 2, 3] as $benefitIndex)
                        <div class="md:col-span-2">
                            <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">
                                {{ __('Poruka :number', ['number' => $benefitIndex]) }}
                            </label>
                            <input
                                type="text"
                                wire:model="form.store_benefits_bar_item_{{ $benefitIndex }}"
                                class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm"
                            />
                            @error('form.store_benefits_bar_item_'.$benefitIndex) <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                        </div>
                    @endforeach
                </div>
            @endif

            @if ($tab === 'cookies')
                <div class="grid gap-4 md:grid-cols-2">
                    <label class="inline-flex items-center gap-2 text-sm font-semibold text-slate-700 md:col-span-2">
                        <input type="checkbox" wire:model="form.store_cookie_consent_enabled" class="rounded border-slate-300 text-cyan-700 focus:ring-cyan-500" />
                        {{ __('Prikaži cookie consent overlay') }}
                    </label>
                    <div class="md:col-span-2 rounded-xl border border-slate-200 bg-slate-50 p-4">
                        <h3 class="text-sm font-bold text-slate-800">{{ __('Centered modal + screen overlay') }}</h3>
                        <p class="mt-1 text-xs text-slate-600">{{ __('Dok korisnik ne klikne dugme prihvatanja, overlay ostaje aktivan i blokira interakciju sa stranicom.') }}</p>
                    </div>
                    <div class="md:col-span-2">
                        <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Naslov') }}</label>
                        <input type="text" wire:model="form.store_cookie_consent_title" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm" />
                    </div>
                    <div class="md:col-span-2">
                        <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Tekst') }}</label>
                        <textarea wire:model="form.store_cookie_consent_message" rows="4" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm"></textarea>
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Labela dugmeta') }}</label>
                        <input type="text" wire:model="form.store_cookie_consent_accept_label" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm" />
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Labela linka politike') }}</label>
                        <input type="text" wire:model="form.store_cookie_consent_policy_label" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm" />
                    </div>
                    <div class="md:col-span-2">
                        <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('URL politike kolačića (optional)') }}</label>
                        <input type="url" wire:model="form.store_cookie_consent_policy_url" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm" />
                        @error('form.store_cookie_consent_policy_url') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                    </div>
                    <div class="md:col-span-2 rounded-xl border border-slate-200 bg-slate-50 p-4">
                        <h3 class="text-sm font-bold text-slate-800">{{ __('Tekstovi za "Postavke kolačića" modal') }}</h3>
                    </div>
                    <div class="md:col-span-2">
                        <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Naslov modala postavki') }}</label>
                        <input type="text" wire:model="form.store_cookie_preferences_title" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm" />
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Prihvati sve') }}</label>
                        <input type="text" wire:model="form.store_cookie_preferences_accept_all_label" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm" />
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Samo nužni') }}</label>
                        <input type="text" wire:model="form.store_cookie_preferences_accept_necessary_label" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm" />
                    </div>
                    <div class="md:col-span-2">
                        <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Spremi odabir') }}</label>
                        <input type="text" wire:model="form.store_cookie_preferences_save_label" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm" />
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Nužni - naslov') }}</label>
                        <input type="text" wire:model="form.store_cookie_necessary_title" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm" />
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Analitički - naslov') }}</label>
                        <input type="text" wire:model="form.store_cookie_analytics_title" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm" />
                    </div>
                    <div class="md:col-span-2">
                        <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Marketinški - naslov') }}</label>
                        <input type="text" wire:model="form.store_cookie_marketing_title" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm" />
                    </div>
                    <div class="md:col-span-2">
                        <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Nužni - opis') }}</label>
                        <textarea wire:model="form.store_cookie_necessary_description" rows="3" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm"></textarea>
                    </div>
                    <div class="md:col-span-2">
                        <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Analitički - opis') }}</label>
                        <textarea wire:model="form.store_cookie_analytics_description" rows="3" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm"></textarea>
                    </div>
                    <div class="md:col-span-2">
                        <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Marketinški - opis') }}</label>
                        <textarea wire:model="form.store_cookie_marketing_description" rows="3" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm"></textarea>
                    </div>
                </div>
            @endif

            <div class="pt-2">
                <button type="submit" class="rounded-xl bg-cyan-700 px-4 py-2 text-sm font-semibold text-white hover:bg-cyan-800">{{ __('Save Store Settings') }}</button>
            </div>
        </form>
    </div>
</div>
