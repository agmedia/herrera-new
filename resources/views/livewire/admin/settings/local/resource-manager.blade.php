<div class="space-y-6">
    @unless ($editPage || $createPage)
    <div class="admin-panel admin-search-panel p-6">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <h1 class="text-xl font-semibold tracking-tight">{{ $this->title }}</h1>
                <p class="mt-1 text-sm text-slate-600">
                    {{ __('Settings namespace:') }} <code>Settings/Local/{{ str_replace('-', '', ucwords($resource, '-')) }}</code>
                </p>
                <p class="mt-2 text-xs text-slate-500">{{ __('Items per page') }}: <span class="admin-chip">{{ $perPage }}</span></p>
            </div>
            <div class="flex w-full flex-col gap-3 sm:w-auto sm:flex-row sm:items-end">
                <div class="w-full sm:w-72">
                    <label for="settings-search" class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('admin.common.search') }}</label>
                    <input
                        id="settings-search"
                        type="text"
                        wire:model.live.debounce.300ms="search"
                        placeholder="{{ __('Code or name...') }}"
                        class="admin-search-input w-full rounded-xl border px-3 py-2 text-sm"
                    />
                </div>
                <a href="{{ route('admin.settings.local.resource.create', array_filter([
                    'resource' => $resource,
                    'search' => $search !== '' ? $search : null,
                    'page' => $rows->currentPage() > 1 ? $rows->currentPage() : null,
                ], static fn (int|string|null $value): bool => $value !== null)) }}" class="rounded-xl bg-cyan-700 px-4 py-2 text-center text-sm font-semibold text-white hover:bg-cyan-800">
                    {{ __('Create item') }}
                </a>
            </div>
        </div>
    </div>
    @endunless

    <div class="admin-stack" style="display:flex; flex-direction:column; gap:1.5rem;">
        @if ($editPage || $createPage)
        <div class="admin-panel admin-form-panel p-6">
            <h2 class="admin-section-title">
                {{ $editingId ? __('Edit item') : __('Create item') }}
            </h2>

            <form wire:submit="save" class="admin-form mt-4 space-y-4">
                @if (in_array('code', $resources[$resource]['fields'], true))
                    <div>
                        <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Code') }}</label>
                        <input type="text" wire:model="form.code" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm outline-none ring-cyan-200 focus:ring" />
                        @error('form.code') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                    </div>
                @endif

                @if (in_array('name', $resources[$resource]['fields'], true))
                    <div>
                        <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Name') }}</label>
                        <input type="text" wire:model="form.name" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm outline-none ring-cyan-200 focus:ring" />
                        @error('form.name') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                    </div>
                @endif

                @if (in_array('provider', $resources[$resource]['fields'], true))
                    <div>
                        <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Provider') }}</label>
                        <input type="text" wire:model="form.provider" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm outline-none ring-cyan-200 focus:ring" />
                    </div>
                @endif

                @if (in_array('geo_zone_id', $resources[$resource]['fields'], true))
                    <div>
                        <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Geo zone') }}</label>
                        <select wire:model="form.geo_zone_id" data-tom-select class="admin-select w-full rounded-xl border border-slate-300 px-3 py-2 text-sm outline-none ring-cyan-200 focus:ring">
                            <option value="">{{ __('None') }}</option>
                            @foreach ($this->geoZoneOptions() as $id => $name)
                                <option value="{{ $id }}">{{ $name }}</option>
                            @endforeach
                        </select>
                        @error('form.geo_zone_id') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                    </div>
                @endif

                @if (in_array('country_code', $resources[$resource]['fields'], true))
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Country') }}</label>
                            <select wire:model="form.country_code" data-tom-select class="admin-select w-full rounded-xl border border-slate-300 px-3 py-2 text-sm outline-none ring-cyan-200 focus:ring">
                                <option value="">{{ __('Select...') }}</option>
                                @foreach (($countryLabels ?? []) as $countryCode => $countryLabel)
                                    <option value="{{ $countryCode }}">{{ $countryCode }} - {{ $countryLabel }}</option>
                                @endforeach
                            </select>
                            @error('form.country_code') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                        </div>
                        @if ($resource === 'geo-zone-countries')
                            <div>
                                <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Region') }}</label>
                                <input type="text" wire:model="form.region_code" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm outline-none ring-cyan-200 focus:ring" />
                            </div>
                        @endif
                    </div>
                    @if ($resource === 'geo-zone-countries')
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Postal from') }}</label>
                                <input type="text" wire:model="form.postal_code_from" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm outline-none ring-cyan-200 focus:ring" />
                            </div>
                            <div>
                                <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Postal to') }}</label>
                                <input type="text" wire:model="form.postal_code_to" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm outline-none ring-cyan-200 focus:ring" />
                            </div>
                        </div>
                    @endif
                @endif

                @if (in_array('fee_type', $resources[$resource]['fields'], true))
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Fee type') }}</label>
                            <select wire:model="form.fee_type" data-tom-select data-tom-no-search="1" class="admin-select w-full rounded-xl border border-slate-300 px-3 py-2 text-sm outline-none ring-cyan-200 focus:ring">
                                <option value="fixed">{{ __('Fixed') }}</option>
                                <option value="percent">{{ __('Percent') }}</option>
                            </select>
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Fee value') }}</label>
                            <input type="number" step="0.01" wire:model="form.fee_value" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm outline-none ring-cyan-200 focus:ring" />
                        </div>
                    </div>
                @endif

                @if (in_array('price', $resources[$resource]['fields'], true))
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Price') }}</label>
                            <input type="number" step="0.01" wire:model="form.price" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm outline-none ring-cyan-200 focus:ring" />
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Free over') }}</label>
                            <input type="number" step="0.01" wire:model="form.free_over" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm outline-none ring-cyan-200 focus:ring" />
                        </div>
                    </div>
                @endif

                @if (in_array('min_subtotal', $resources[$resource]['fields'], true))
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Min subtotal') }}</label>
                            <input type="number" step="0.01" wire:model="form.min_subtotal" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm outline-none ring-cyan-200 focus:ring" />
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Max subtotal') }}</label>
                            <input type="number" step="0.01" wire:model="form.max_subtotal" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm outline-none ring-cyan-200 focus:ring" />
                        </div>
                    </div>
                @endif

                @if (in_array('rate_type', $resources[$resource]['fields'], true))
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Rate type') }}</label>
                            <select wire:model="form.rate_type" data-tom-select data-tom-no-search="1" class="admin-select w-full rounded-xl border border-slate-300 px-3 py-2 text-sm outline-none ring-cyan-200 focus:ring">
                                <option value="percent">{{ __('Percent') }}</option>
                                <option value="fixed">{{ __('Fixed') }}</option>
                            </select>
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Rate') }}</label>
                            <input type="number" step="0.0001" wire:model="form.rate" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm outline-none ring-cyan-200 focus:ring" />
                        </div>
                    </div>
                @endif

                @if (in_array('symbol', $resources[$resource]['fields'], true))
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Symbol') }}</label>
                            <input type="text" wire:model="form.symbol" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm outline-none ring-cyan-200 focus:ring" />
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Position') }}</label>
                            <select wire:model="form.symbol_position" data-tom-select data-tom-no-search="1" class="admin-select w-full rounded-xl border border-slate-300 px-3 py-2 text-sm outline-none ring-cyan-200 focus:ring">
                                <option value="left">{{ __('Left') }}</option>
                                <option value="right">{{ __('Right') }}</option>
                            </select>
                        </div>
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Exchange rate') }}</label>
                        <input type="number" step="0.00000001" wire:model="form.exchange_rate" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm outline-none ring-cyan-200 focus:ring" />
                    </div>
                @endif

                @if (in_array('locale', $resources[$resource]['fields'], true))
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('admin.common.locale') }}</label>
                            <input type="text" wire:model="form.locale" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm outline-none ring-cyan-200 focus:ring" />
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Direction') }}</label>
                            <select wire:model="form.direction" data-tom-select data-tom-no-search="1" class="admin-select w-full rounded-xl border border-slate-300 px-3 py-2 text-sm outline-none ring-cyan-200 focus:ring">
                                <option value="ltr">LTR</option>
                                <option value="rtl">RTL</option>
                            </select>
                        </div>
                    </div>
                    <div>
                            <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Native name') }}</label>
                        <input type="text" wire:model="form.native_name" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm outline-none ring-cyan-200 focus:ring" />
                    </div>
                @endif

                @if (in_array('description', $resources[$resource]['fields'], true))
                    <div>
                        <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Description') }}</label>
                        <textarea rows="3" wire:model="form.description" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm outline-none ring-cyan-200 focus:ring"></textarea>
                    </div>
                @endif

                @if (in_array('color', $resources[$resource]['fields'], true))
                    <div>
                        <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Color token') }}</label>
                        <input type="text" wire:model="form.color" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm outline-none ring-cyan-200 focus:ring" />
                    </div>
                @endif

                @if (in_array('settings_text', $resources[$resource]['fields'], true))
                    <div>
                        <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Settings JSON') }}</label>
                        <textarea rows="5" wire:model="form.settings_text" class="w-full rounded-xl border border-slate-300 px-3 py-2 font-mono text-xs outline-none ring-cyan-200 focus:ring"></textarea>
                        @error('form.settings_text') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                    </div>
                @endif

                @if ($resource === 'payment-methods')
                    <div>
                        <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Default order status') }}</label>
                        <select wire:model="form.default_order_status_id" data-tom-select class="admin-select w-full rounded-xl border border-slate-300 px-3 py-2 text-sm outline-none ring-cyan-200 focus:ring">
                            <option value="">{{ __('Store default') }}</option>
                            @foreach ($this->orderStatusOptions() as $statusId => $statusName)
                                <option value="{{ $statusId }}">{{ $statusName }}</option>
                            @endforeach
                        </select>
                        @error('form.default_order_status_id') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                    </div>
                @endif

                @if ($this->isBankTransferForm())
                    <div class="rounded-xl border border-cyan-200 bg-cyan-50 p-3">
                        <p class="mb-2 text-xs font-semibold uppercase tracking-[0.14em] text-cyan-800">{{ __('UPI bank transfer data (required)') }}</p>
                        <div class="grid grid-cols-1 gap-3 md:grid-cols-2">
                            <div>
                                <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Receiver name') }}</label>
                                <input type="text" wire:model="form.upi_receiver_name" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm outline-none ring-cyan-200 focus:ring" />
                                @error('form.upi_receiver_name') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">IBAN</label>
                                <input type="text" wire:model="form.upi_receiver_iban" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm uppercase outline-none ring-cyan-200 focus:ring" />
                                @error('form.upi_receiver_iban') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Receiver street') }}</label>
                                <input type="text" wire:model="form.upi_receiver_street" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm outline-none ring-cyan-200 focus:ring" />
                                @error('form.upi_receiver_street') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Receiver place') }}</label>
                                <input type="text" wire:model="form.upi_receiver_place" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm outline-none ring-cyan-200 focus:ring" />
                                @error('form.upi_receiver_place') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Model') }}</label>
                                <input type="text" wire:model="form.upi_model" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm outline-none ring-cyan-200 focus:ring" />
                            </div>
                            <div>
                                <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Purpose code') }}</label>
                                <input type="text" wire:model="form.upi_purpose_code" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm outline-none ring-cyan-200 focus:ring" />
                            </div>
                            <div class="md:col-span-2">
                                <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Payment description') }}</label>
                                <input type="text" wire:model="form.upi_description" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm outline-none ring-cyan-200 focus:ring" />
                            </div>
                        </div>
                    </div>
                @endif

                @if ($this->isWspayForm())
                    <div class="rounded-xl border border-indigo-200 bg-indigo-50 p-3">
                        <p class="mb-2 text-xs font-semibold uppercase tracking-[0.14em] text-indigo-800">{{ __('WSPay settings (required)') }}</p>
                        <div class="grid grid-cols-1 gap-3 md:grid-cols-2">
                            <div>
                                <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Mode') }}</label>
                                <select wire:model="form.wspay_mode" data-tom-select data-tom-no-search="1" class="admin-select w-full rounded-xl border border-slate-300 px-3 py-2 text-sm outline-none ring-cyan-200 focus:ring">
                                    <option value="test">Test</option>
                                    <option value="live">Live</option>
                                </select>
                                @error('form.wspay_mode') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">Shop ID</label>
                                <input type="text" wire:model="form.wspay_shop_id" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm outline-none ring-cyan-200 focus:ring" />
                                @error('form.wspay_shop_id') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Secret key') }}</label>
                                <input type="text" wire:model="form.wspay_secret_key" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm outline-none ring-cyan-200 focus:ring" />
                                @error('form.wspay_secret_key') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Return method') }}</label>
                                <select wire:model="form.wspay_return_method" data-tom-select data-tom-no-search="1" class="admin-select w-full rounded-xl border border-slate-300 px-3 py-2 text-sm outline-none ring-cyan-200 focus:ring">
                                    <option value="GET">GET</option>
                                    <option value="POST">POST</option>
                                </select>
                                @error('form.wspay_return_method') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                            </div>
                            <div class="md:col-span-2">
                                <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('WSPay form URL') }}</label>
                                <input type="text" value="{{ ($form['wspay_mode'] ?? 'test') === 'live' ? 'https://form.wspay.biz/authorization.aspx' : 'https://formtest.wspay.biz/authorization.aspx' }}" readonly class="w-full rounded-xl border border-slate-300 bg-slate-100 px-3 py-2 text-sm text-slate-600 outline-none" />
                            </div>
                        </div>
                    </div>
                @endif

                @if ($this->isCorvusForm())
                    <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-3">
                        <p class="mb-2 text-xs font-semibold uppercase tracking-[0.14em] text-emerald-800">{{ __('CorvusPay settings (required)') }}</p>
                        @if ($editingId)
                            <p class="mb-3 rounded-lg px-3 py-2 text-xs font-semibold {{ $corvusCredentialsStored ? 'bg-emerald-100 text-emerald-900' : 'bg-amber-100 text-amber-900' }}">
                                {{ $corvusCredentialsStored
                                    ? __('CorvusPay credentials are stored. Enter a new secret key only when replacing it.')
                                    : __('CorvusPay credentials are missing. The payment method cannot be activated.') }}
                            </p>
                        @endif
                        <div class="grid grid-cols-1 gap-3 md:grid-cols-2">
                            <div>
                                <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Mode') }}</label>
                                <select wire:model="form.corvus_mode" data-tom-select data-tom-no-search="1" class="admin-select w-full rounded-xl border border-slate-300 px-3 py-2 text-sm outline-none ring-cyan-200 focus:ring">
                                    <option value="test">Test</option>
                                    <option value="live">Live</option>
                                </select>
                                @error('form.corvus_mode') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Store ID') }}</label>
                                <input type="text" wire:model="form.corvus_store_id" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm outline-none ring-cyan-200 focus:ring" />
                                @error('form.corvus_store_id') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Secret key') }}</label>
                                <input type="password" wire:model="form.corvus_secret_key" autocomplete="new-password" placeholder="{{ $corvusCredentialsStored ? __('Leave blank to keep the stored secret') : '' }}" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm outline-none ring-cyan-200 focus:ring" />
                                @error('form.corvus_secret_key') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Language') }}</label>
                                <select wire:model="form.corvus_language" data-tom-select data-tom-no-search="1" class="admin-select w-full rounded-xl border border-slate-300 px-3 py-2 text-sm outline-none ring-cyan-200 focus:ring">
                                    <option value="hr">hr</option>
                                    <option value="en">en</option>
                                    <option value="it">it</option>
                                    <option value="de">de</option>
                                    <option value="rs">rs</option>
                                    <option value="sl">sl</option>
                                    <option value="mk">mk</option>
                                    <option value="sq">sq</option>
                                </select>
                                @error('form.corvus_language') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Currency') }}</label>
                                <input type="text" wire:model="form.corvus_currency" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm uppercase outline-none ring-cyan-200 focus:ring" />
                                @error('form.corvus_currency') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Require complete') }}</label>
                                <select wire:model="form.corvus_require_complete" data-tom-select data-tom-no-search="1" class="admin-select w-full rounded-xl border border-slate-300 px-3 py-2 text-sm outline-none ring-cyan-200 focus:ring">
                                    <option value="false">{{ __('Immediate charge') }}</option>
                                    <option value="true">{{ __('Preauthorization') }}</option>
                                </select>
                                @error('form.corvus_require_complete') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                            </div>
                            <div class="md:col-span-2">
                                <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('CorvusPay form URL') }}</label>
                                <input type="text" value="{{ ($form['corvus_mode'] ?? 'test') === 'live' ? 'https://wallet.corvuspay.com/checkout/' : 'https://wallet.test.corvuspay.com/checkout/' }}" readonly class="w-full rounded-xl border border-slate-300 bg-slate-100 px-3 py-2 text-sm text-slate-600 outline-none" />
                            </div>
                            <div class="md:col-span-2">
                                <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Success URL template') }}</label>
                                <input type="text" value="{{ rtrim(url('/'), '/').'/checkout/corvus/success' }}" readonly class="w-full rounded-xl border border-slate-300 bg-slate-100 px-3 py-2 text-sm text-slate-600 outline-none" />
                            </div>
                            <div class="md:col-span-2">
                                <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Cancel URL template') }}</label>
                                <input type="text" value="{{ rtrim(url('/'), '/').'/checkout/corvus/cancel' }}" readonly class="w-full rounded-xl border border-slate-300 bg-slate-100 px-3 py-2 text-sm text-slate-600 outline-none" />
                            </div>
                        </div>
                    </div>
                @endif

                @if ($this->isKeksForm())
                    <div class="rounded-xl border border-fuchsia-200 bg-fuchsia-50 p-3">
                        <p class="mb-2 text-xs font-semibold uppercase tracking-[0.14em] text-fuchsia-800">{{ __('KEKS Pay settings (required)') }}</p>
                        <div class="grid grid-cols-1 gap-3 md:grid-cols-2">
                            <div>
                                <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Mode') }}</label>
                                <select wire:model="form.keks_mode" data-tom-select data-tom-no-search="1" class="admin-select w-full rounded-xl border border-slate-300 px-3 py-2 text-sm outline-none ring-cyan-200 focus:ring">
                                    <option value="test">Test</option>
                                    <option value="live">Live</option>
                                </select>
                                @error('form.keks_mode') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">CID</label>
                                <input type="text" wire:model="form.keks_cid" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm outline-none ring-cyan-200 focus:ring" />
                                @error('form.keks_cid') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">TID</label>
                                <input type="text" wire:model="form.keks_tid" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm outline-none ring-cyan-200 focus:ring" />
                                @error('form.keks_tid') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">DES key</label>
                                <input type="text" wire:model="form.keks_des_key" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm outline-none ring-cyan-200 focus:ring" />
                                @error('form.keks_des_key') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">QR type</label>
                                <input type="number" min="1" max="9" wire:model="form.keks_qr_type" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm outline-none ring-cyan-200 focus:ring" />
                                @error('form.keks_qr_type') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">Advice auth mode</label>
                                <select wire:model="form.keks_advice_auth_mode" data-tom-select data-tom-no-search="1" class="admin-select w-full rounded-xl border border-slate-300 px-3 py-2 text-sm outline-none ring-cyan-200 focus:ring">
                                    <option value="none">none</option>
                                    <option value="token">token</option>
                                    <option value="basic">basic</option>
                                    <option value="url_token">url_token</option>
                                </select>
                                @error('form.keks_advice_auth_mode') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">Advice token</label>
                                <input type="text" wire:model="form.keks_advice_token" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm outline-none ring-cyan-200 focus:ring" />
                                @error('form.keks_advice_token') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">Advice username</label>
                                <input type="text" wire:model="form.keks_advice_username" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm outline-none ring-cyan-200 focus:ring" />
                                @error('form.keks_advice_username') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">Advice password</label>
                                <input type="text" wire:model="form.keks_advice_password" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm outline-none ring-cyan-200 focus:ring" />
                                @error('form.keks_advice_password') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                            </div>
                            <div class="md:col-span-2">
                                <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">KEKS sell URL</label>
                                <input type="text" wire:model="form.keks_sell_base_url" placeholder="{{ ($form['keks_mode'] ?? 'test') === 'live' ? 'https://kekspay.hr/galebpay' : 'https://kekspayuat.erstebank.hr/galebpay' }}" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm outline-none ring-cyan-200 focus:ring" />
                                @error('form.keks_sell_base_url') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                            </div>
                            <div class="md:col-span-2">
                                <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">Advice URL</label>
                                <input type="text" value="{{ rtrim(url('/'), '/').'/checkout/keks/advice' }}" readonly class="w-full rounded-xl border border-slate-300 bg-slate-100 px-3 py-2 text-sm text-slate-600 outline-none" />
                            </div>
                            <div class="md:col-span-2">
                                <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">Success URL</label>
                                <input type="text" value="{{ rtrim(url('/'), '/').'/checkout/keks/success?bill_id={ORDER_NUMBER}' }}" readonly class="w-full rounded-xl border border-slate-300 bg-slate-100 px-3 py-2 text-sm text-slate-600 outline-none" />
                            </div>
                            <div class="md:col-span-2">
                                <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">Fail URL</label>
                                <input type="text" value="{{ rtrim(url('/'), '/').'/checkout/keks/fail?bill_id={ORDER_NUMBER}' }}" readonly class="w-full rounded-xl border border-slate-300 bg-slate-100 px-3 py-2 text-sm text-slate-600 outline-none" />
                            </div>
                        </div>
                    </div>
                @endif

                @if ($this->isBoxNowForm())
                    <div class="rounded-xl border border-blue-200 bg-blue-50 p-3">
                        <p class="mb-2 text-xs font-semibold uppercase tracking-[0.14em] text-blue-800">{{ __('BOX NOW widget config') }}</p>
                        <div>
                            <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Partner ID') }}</label>
                            <input type="text" wire:model="form.boxnow_partner_id" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm outline-none ring-cyan-200 focus:ring" />
                            @error('form.boxnow_partner_id') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                        </div>
                    </div>
                @endif

                <div class="grid grid-cols-2 gap-3">
                    @if (in_array('sort_order', $resources[$resource]['fields'], true))
                        <div>
                            <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('admin.common.sort') }}</label>
                            <input type="number" wire:model="form.sort_order" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm outline-none ring-cyan-200 focus:ring" />
                        </div>
                    @endif
                    @if (in_array('decimal_places', $resources[$resource]['fields'], true))
                        <div>
                            <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Decimals') }}</label>
                            <input type="number" wire:model="form.decimal_places" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm outline-none ring-cyan-200 focus:ring" />
                        </div>
                    @endif
                    @if (in_array('priority', $resources[$resource]['fields'], true))
                        <div>
                            <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Priority') }}</label>
                            <input type="number" wire:model="form.priority" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm outline-none ring-cyan-200 focus:ring" />
                        </div>
                    @endif
                </div>

                <div class="flex flex-wrap items-center gap-3 pt-1">
                    @foreach (['is_active' => __('Active'), 'is_default' => __('Default'), 'is_paid' => __('Paid'), 'is_cancelled' => __('Cancelled')] as $key => $label)
                        @if (in_array($key, $resources[$resource]['fields'], true))
                            <label class="inline-flex items-center gap-2 text-sm text-slate-700">
                                <input
                                    type="checkbox"
                                    value="1"
                                    wire:model.live="{{ 'form.'.$key }}"
                                    @checked((bool) ($form[$key] ?? false))
                                    class="rounded border-slate-300 text-cyan-700 focus:ring-cyan-500"
                                />
                                <span>{{ $label }}</span>
                            </label>
                        @endif
                    @endforeach
                </div>

                <div class="admin-form-actions flex items-center gap-2 pt-2">
                    <button type="submit" class="rounded-xl bg-cyan-700 px-4 py-2 text-sm font-semibold text-white hover:bg-cyan-800">
                        {{ $editingId ? __('Update') : __('Create') }}
                    </button>
                    @if ($editPage || $createPage)
                        <a href="{{ route('admin.settings.local.resource', array_filter([
                            'resource' => $resource,
                            'search' => $search !== '' ? $search : null,
                            'page' => $returnPage > 1 ? $returnPage : null,
                        ], static fn (int|string|null $value): bool => $value !== null)) }}" class="rounded-xl border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-100">
                            {{ __('Cancel') }}
                        </a>
                    @elseif ($editingId)
                        <button type="button" wire:click="cancelEdit" class="rounded-xl border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-100">
                            {{ __('Cancel') }}
                        </button>
                    @endif
                </div>
            </form>
        </div>
        @endif

        @unless ($editPage || $createPage)
        <div class="admin-panel admin-panel-soft p-5">
            <h2 class="admin-section-title">{{ __('admin.common.items') }}</h2>
            <div class="mt-4 overflow-x-auto">
                <table class="admin-items-table min-w-full text-sm">
                    <thead class="text-slate-600">
                        <tr>
                            <th class="px-3 py-2 text-center font-semibold">{{ __('Code') }}</th>
                            <th class="px-3 py-2 text-left font-semibold">{{ __('Name') }}</th>
                            <th class="px-3 py-2 text-center font-semibold">{{ __('admin.common.sort') }}</th>
                            <th class="px-3 py-2 text-center font-semibold">{{ __('admin.common.state') }}</th>
                            <th class="px-3 py-2 text-right font-semibold">{{ __('admin.common.actions') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @forelse ($rows as $row)
                            <tr>
                                <td class="px-3 py-2 text-center font-mono text-xs text-slate-700">{{ $row->code ?? ($row->country_code ?? '-') }}</td>
                                <td class="px-3 py-2 text-slate-800">
                                    @if ($resource === 'geo-zone-countries')
                                        @php
                                            $countryCode = strtoupper((string) ($row->country_code ?? ''));
                                            $countryName = (string) (($countryLabels ?? [])[$countryCode] ?? $countryCode);
                                            $zoneName = (string) (($geoZoneLabels ?? [])[(int) ($row->geo_zone_id ?? 0)] ?? '');
                                        @endphp
                                        <div>{{ $countryName }} ({{ $countryCode }})</div>
                                        @if ($zoneName !== '')
                                            <div class="text-xs text-slate-500">{{ __('Geo zone') }}: {{ $zoneName }}</div>
                                        @endif
                                    @elseif ($resource === 'regions')
                                        @php
                                            $countryCode = strtoupper((string) ($row->country_code ?? ''));
                                            $countryName = (string) (($countryLabels ?? [])[$countryCode] ?? $countryCode);
                                        @endphp
                                        <div>{{ $row->name }}</div>
                                        <div class="text-xs text-slate-500">{{ $countryName }} ({{ $countryCode }})</div>
                                    @else
                                        {{ $row->name ?? ($row->country_code ?? '#'.$row->id) }}
                                    @endif
                                </td>
                                <td class="px-3 py-2 text-center text-slate-600">{{ $row->sort_order ?? '-' }}</td>
                                <td class="px-3 py-2 text-center">
                                    @if (isset($row->is_active))
                                        <button type="button" wire:click="toggleActive({{ $row->id }})" class="rounded-full px-2.5 py-1 text-xs font-semibold {{ $row->is_active ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-200 text-slate-700' }}">
                                            {{ $row->is_active ? __('Active') : __('Inactive') }}
                                        </button>
                                    @else
                                        <span class="text-slate-500">-</span>
                                    @endif

                                    @if (isset($row->is_default) && $row->is_default)
                                        <span class="ml-1 rounded-full bg-cyan-100 px-2 py-0.5 text-xs font-semibold text-cyan-800">{{ __('Default') }}</span>
                                    @endif
                                </td>
                                <td class="px-3 py-2 text-right">
                                    <div class="inline-flex items-center gap-1">
                                        @if (isset($row->is_default))
                                            <button type="button" wire:click="makeDefault({{ $row->id }})" class="rounded-lg border border-slate-300 px-2 py-1 text-xs font-semibold text-slate-700 hover:bg-slate-100">{{ __('Default') }}</button>
                                        @endif
                                        <a href="{{ route('admin.settings.local.resource.edit', array_filter([
                                            'resource' => $resource,
                                            'record' => $row->id,
                                            'search' => $search !== '' ? $search : null,
                                            'page' => $rows->currentPage() > 1 ? $rows->currentPage() : null,
                                        ], static fn (int|string|null $value): bool => $value !== null)) }}" class="rounded-lg border border-slate-300 px-2 py-1 text-xs font-semibold text-slate-700 hover:bg-slate-100">{{ __('admin.common.edit') }}</a>
                                        <button type="button" wire:click="delete({{ $row->id }})" wire:confirm="{{ __('Delete this item?') }}" class="rounded-lg border border-rose-200 px-2 py-1 text-xs font-semibold text-rose-700 hover:bg-rose-50">{{ __('admin.common.delete') }}</button>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="px-3 py-8 text-center text-sm text-slate-500">{{ __('No records yet.') }}</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="mt-4">
                {{ $rows->links() }}
            </div>
        </div>
        @endunless
    </div>
</div>
