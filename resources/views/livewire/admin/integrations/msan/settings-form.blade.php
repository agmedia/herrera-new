<div class="space-y-6">
    <section class="admin-panel admin-form-panel p-6">
        <form wire:submit="save" class="space-y-6">
            <div wire:dirty class="sticky top-20 z-20 rounded-xl border border-amber-300 bg-amber-50/95 p-3 shadow-lg shadow-slate-900/5 backdrop-blur">
                <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <div><p class="text-sm font-semibold text-amber-950">{{ __('Imate nespremljene promjene') }}</p><p class="mt-0.5 text-xs text-amber-800">{{ __('Provjere veze koriste zadnje spremljene vrijednosti. Spremite prije odlaska na drugi dio modula.') }}</p></div>
                    <button type="submit" wire:loading.attr="disabled" wire:target="save" class="min-h-11 shrink-0 rounded-xl bg-cyan-700 px-4 py-2 text-sm font-semibold text-white hover:bg-cyan-800 disabled:cursor-wait disabled:opacity-60"><span wire:loading.remove wire:target="save">{{ __('Spremi promjene') }}</span><span wire:loading wire:target="save">{{ __('Spremam...') }}</span></button>
                </div>
            </div>
            <div class="grid gap-4 md:grid-cols-3">
                <div class="rounded-xl border border-slate-200 bg-white p-4 md:col-span-2">
                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <h2 class="text-base font-semibold text-slate-900">{{ __('M SAN B2B veza') }}</h2>
                            <p class="mt-1 text-sm text-slate-600">{{ __('Puni XML se periodično sprema u lokalnu radnu kopiju; webshop nikad ne poziva M SAN pri prikazu artikla.') }}</p>
                        </div>
                        <button type="button" wire:click="$toggle('form.msan_enabled')" class="admin-switch" data-state="{{ ($form['msan_enabled'] ?? false) ? 'on' : 'off' }}" role="switch" aria-checked="{{ ($form['msan_enabled'] ?? false) ? 'true' : 'false' }}">
                            <span class="admin-switch-track"><span class="admin-switch-thumb"></span></span>
                            <span class="admin-switch-label">{{ ($form['msan_enabled'] ?? false) ? __('Uključeno') : __('Isključeno') }}</span>
                        </button>
                    </div>
                    @error('form.msan_enabled') <p class="mt-2 text-xs text-rose-600">{{ $message }}</p> @enderror
                </div>
                <div @class([
                    'rounded-xl border p-4',
                    'border-emerald-200 bg-emerald-50' => $certificateValid,
                    'border-rose-200 bg-rose-50' => $certificateConfigured && ! $certificateValid,
                    'border-amber-200 bg-amber-50' => ! $certificateConfigured,
                ])>
                    <p @class([
                        'text-xs font-semibold uppercase tracking-[0.14em]',
                        'text-emerald-700' => $certificateValid,
                        'text-rose-700' => $certificateConfigured && ! $certificateValid,
                        'text-amber-700' => ! $certificateConfigured,
                    ])>{{ __('Certifikat') }}</p>
                    <p class="mt-2 text-sm font-semibold text-slate-900">
                        {{ $certificateValid ? __('Valjano i privatno spremljen') : ($certificateConfigured ? __('Nevaljan ili istekao') : __('Nije spremljen')) }}
                    </p>
                    @if ($certificateMetadata)
                        <p class="mt-1 text-xs text-slate-600">{{ __('Vrijedi do') }}: {{ $certificateMetadata['valid_until'] ?? '—' }}</p>
                        <p class="mt-1 break-all font-mono text-[10px] text-slate-500">SHA-256: {{ $certificateMetadata['fingerprint'] ?? '' }}</p>
                    @endif
                    @if ($certificateError)
                        <p class="mt-1 text-xs text-rose-700">{{ $certificateError }}</p>
                    @endif
                </div>
            </div>

            <div class="grid gap-4 md:grid-cols-2">
                <div>
                    <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('P12/PFX certifikat') }}</label>
                    <input type="file" wire:model="certificate" accept=".p12,.pfx,application/x-pkcs12" class="block w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm">
                    <p class="mt-1 text-xs text-slate-500">{{ __('Datoteka ide u privatni storage izvan public direktorija. Maksimalno 1 MB.') }}</p>
                    @error('certificate') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('PIN / lozinka za uvoz certifikata') }}</label>
                    <input type="password" wire:model="form.msan_p12_pin" autocomplete="new-password" placeholder="{{ ($form['msan_p12_pin_configured'] ?? false) ? __('PIN je spremljen — prazno ga zadržava') : __('Unesite PIN certifikata') }}" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">
                    <p class="mt-1 text-xs text-slate-500">{{ __('PIN se sprema šifrirano i nikad se ponovno ne prikazuje.') }}</p>
                    @error('form.msan_p12_pin') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                </div>
            </div>

            <div class="rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-xs text-slate-600">
                <strong>{{ __('Fiksni B2B servis') }}:</strong> <span class="break-all font-mono">{{ $productEndpoint }}</span>
            </div>

            <div class="grid gap-4 md:grid-cols-2">
                <div><label class="mb-1 block text-xs font-semibold text-slate-600">{{ __('Vremensko ograničenje spajanja (sekunde)') }}</label><input type="number" min="2" max="60" wire:model="form.msan_connect_timeout" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">@error('form.msan_connect_timeout') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror</div>
                <div><label class="mb-1 block text-xs font-semibold text-slate-600">{{ __('Vremensko ograničenje punog dohvata (sekunde)') }}</label><input type="number" min="15" max="300" wire:model="form.msan_request_timeout" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">@error('form.msan_request_timeout') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror</div>
            </div>

            <div class="border-t border-slate-200 pt-6">
                <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                    <div>
                        <h2 class="text-base font-semibold text-slate-900">{{ __('Automatsko osvježavanje cijena i količina') }}</h2>
                        <p class="mt-1 max-w-3xl text-sm text-slate-600">{{ __('Za uvezene artikle kojima je M SAN i dalje izvor podataka osvježava prodajnu MPC cijenu i lokalni prodajni limit prema dostupnosti. Artikli koje je preuzeo ERP ili drugi izvor ostaju netaknuti.') }}</p>
                    </div>
                    <button type="button" wire:click="$toggle('form.msan_price_stock_sync_enabled')" class="admin-switch" data-state="{{ ($form['msan_price_stock_sync_enabled'] ?? false) ? 'on' : 'off' }}" role="switch" aria-checked="{{ ($form['msan_price_stock_sync_enabled'] ?? false) ? 'true' : 'false' }}">
                        <span class="admin-switch-track"><span class="admin-switch-thumb"></span></span>
                        <span class="admin-switch-label">{{ ($form['msan_price_stock_sync_enabled'] ?? false) ? __('Uključeno') : __('Isključeno') }}</span>
                    </button>
                </div>
                @error('form.msan_price_stock_sync_enabled') <p class="mt-2 text-xs text-rose-600">{{ $message }}</p> @enderror

                <div class="mt-4 grid gap-4 lg:grid-cols-[minmax(0,1fr)_auto] lg:items-end">
                    <div>
                        <label class="mb-1 block text-xs font-semibold text-slate-600" for="msan-price-stock-cron">{{ __('Cron raspored') }}</label>
                        <input id="msan-price-stock-cron" type="text" maxlength="100" list="msan-price-stock-cron-presets" wire:model="form.msan_price_stock_sync_cron" autocomplete="off" spellcheck="false" placeholder="*/15 * * * *" class="w-full rounded-xl border border-slate-300 px-3 py-2 font-mono text-sm">
                        <datalist id="msan-price-stock-cron-presets">
                            <option value="*/15 * * * *">{{ __('Svakih 15 minuta') }}</option>
                            <option value="*/30 * * * *">{{ __('Svakih 30 minuta') }}</option>
                            <option value="0 * * * *">{{ __('Svaki puni sat') }}</option>
                            <option value="0 */6 * * *">{{ __('Svakih 6 sati') }}</option>
                            <option value="0 3 * * *">{{ __('Svaki dan u 03:00') }}</option>
                        </datalist>
                        <p class="mt-1 text-xs leading-5 text-slate-500">{{ __('Pet cron polja: minuta, sat, dan, mjesec i dan u tjednu. Najmanji dopušteni razmak je 10 minuta. Vrijeme se računa u zoni :timezone.', ['timezone' => $priceStockSyncTimezone]) }}</p>
                        @error('form.msan_price_stock_sync_cron') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                    </div>
                    @if ($canSync)
                        <button type="button" wire:click="syncPricesAndStock" wire:confirm="{{ __('Pokrenuti osvježavanje cijena i količina za uvezene M SAN artikle?') }}" wire:loading.attr="disabled" wire:target="syncPricesAndStock" class="min-h-11 rounded-xl border border-cyan-300 bg-cyan-50 px-4 py-2 text-sm font-semibold text-cyan-800 hover:bg-cyan-100 disabled:cursor-wait disabled:opacity-60">
                            <span wire:loading.remove wire:target="syncPricesAndStock">{{ __('Osvježi cijene i količine odmah') }}</span>
                            <span wire:loading wire:target="syncPricesAndStock">{{ __('Pokrećem...') }}</span>
                        </button>
                    @endif
                </div>
            </div>

            <div class="border-t border-slate-200 pt-6">
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <h2 class="text-base font-semibold text-slate-900">{{ __('FTP / FTPS slike') }}</h2>
                        <p class="mt-1 text-sm text-slate-600">{{ __('Opcionalno. Slike se preuzimaju samo za uvezene artikle i spremaju lokalno; hotlinkanje nije dopušteno.') }}</p>
                    </div>
                    <button type="button" wire:click="$toggle('form.msan_ftp_enabled')" class="admin-switch" data-state="{{ ($form['msan_ftp_enabled'] ?? false) ? 'on' : 'off' }}" role="switch" aria-checked="{{ ($form['msan_ftp_enabled'] ?? false) ? 'true' : 'false' }}">
                        <span class="admin-switch-track"><span class="admin-switch-thumb"></span></span>
                        <span class="admin-switch-label">{{ ($form['msan_ftp_enabled'] ?? false) ? __('Uključeno') : __('Isključeno') }}</span>
                    </button>
                </div>
                <div class="mt-4 grid gap-4 md:grid-cols-2">
                    <div><label class="mb-1 block text-xs font-semibold text-slate-600">{{ __('FTP host') }}</label><input type="text" value="{{ $ftpHost }}" disabled class="w-full rounded-xl border border-slate-200 bg-slate-100 px-3 py-2 text-sm text-slate-600"></div>
                    <div><label class="mb-1 block text-xs font-semibold text-slate-600">{{ __('FTP korisničko ime') }}</label><input type="text" wire:model="form.msan_ftp_username" autocomplete="off" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">@error('form.msan_ftp_username') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror</div>
                    <div class="md:col-span-2"><label class="mb-1 block text-xs font-semibold text-slate-600">{{ __('FTP lozinka') }}</label><input type="password" wire:model="form.msan_ftp_password" autocomplete="new-password" placeholder="{{ ($form['msan_ftp_password_configured'] ?? false) ? __('Lozinka je spremljena — prazno je zadržava') : __('Unesite FTP lozinku') }}" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">@error('form.msan_ftp_password') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror</div>
                    <div><label class="mb-1 block text-xs font-semibold text-slate-600">{{ __('FTP vremensko ograničenje spajanja') }}</label><input type="number" min="2" max="60" wire:model="form.msan_ftp_connect_timeout" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">@error('form.msan_ftp_connect_timeout') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror</div>
                    <div><label class="mb-1 block text-xs font-semibold text-slate-600">{{ __('FTP vremensko ograničenje prijenosa') }}</label><input type="number" min="15" max="120" wire:model="form.msan_ftp_timeout" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">@error('form.msan_ftp_timeout') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror</div>
                </div>
            </div>

            <div class="border-t border-slate-200 pt-6">
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <h2 class="text-base font-semibold text-slate-900">{{ __('Tehničke specifikacije') }}</h2>
                        <p class="mt-1 text-sm text-slate-600">{{ __('Specifikacije se dohvaćaju u pozadini, spremaju u lokalni snapshot i tek zatim objavljuju na uvezenim artiklima.') }}</p>
                    </div>
                    <button type="button" wire:click="$toggle('form.msan_import_specifications')" class="admin-switch" data-state="{{ ($form['msan_import_specifications'] ?? false) ? 'on' : 'off' }}" role="switch" aria-checked="{{ ($form['msan_import_specifications'] ?? false) ? 'true' : 'false' }}">
                        <span class="admin-switch-track"><span class="admin-switch-thumb"></span></span>
                        <span class="admin-switch-label">{{ ($form['msan_import_specifications'] ?? false) ? __('Uključeno') : __('Isključeno') }}</span>
                    </button>
                </div>
                @error('form.msan_import_specifications') <p class="mt-2 text-xs text-rose-600">{{ $message }}</p> @enderror

                <div class="mt-4 grid gap-4 lg:grid-cols-3">
                    <div>
                        <label class="mb-1 block text-xs font-semibold text-slate-600" for="msan-specifications-source">{{ __('Izvor specifikacija') }}</label>
                        <select id="msan-specifications-source" wire:model="form.msan_specifications_source" class="admin-select w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">
                            <option value="standard">{{ __('Standardni M SAN skup') }}</option>
                            <option value="icecat">{{ __('M SAN Icecat skup') }}</option>
                        </select>
                        <p class="mt-1 text-xs text-slate-500">{{ __('Odaberite samo izvor koji je M SAN omogućio za ovaj certifikat.') }}</p>
                        @error('form.msan_specifications_source') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-semibold text-slate-600" for="msan-specifications-timeout">{{ __('Vremensko ograničenje dohvata (sekunde)') }}</label>
                        <input id="msan-specifications-timeout" type="number" min="300" max="7200" wire:model="form.msan_specifications_timeout" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">
                        <p class="mt-1 text-xs text-slate-500">{{ __('Veliki skup može trajati do dva sata; obrada ne blokira admin sučelje.') }}</p>
                        @error('form.msan_specifications_timeout') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                    </div>
                    <div class="rounded-xl border border-slate-200 bg-white p-4">
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <strong class="text-sm text-slate-900">{{ __('Samo odabrani ili uvezeni artikli') }}</strong>
                                <p class="mt-1 text-xs text-slate-600">{{ __('Smanjuje količinu podataka i ubrzava objavu. Isključite samo kada trebate sve M SAN artikle.') }}</p>
                            </div>
                            <button type="button" wire:click="$toggle('form.msan_specifications_selected_only')" class="admin-switch" data-state="{{ ($form['msan_specifications_selected_only'] ?? false) ? 'on' : 'off' }}" role="switch" aria-checked="{{ ($form['msan_specifications_selected_only'] ?? false) ? 'true' : 'false' }}">
                                <span class="admin-switch-track"><span class="admin-switch-thumb"></span></span>
                                <span class="sr-only">{{ __('Samo odabrani ili uvezeni artikli') }}</span>
                            </button>
                        </div>
                        @error('form.msan_specifications_selected_only') <p class="mt-2 text-xs text-rose-600">{{ $message }}</p> @enderror
                    </div>
                </div>
            </div>

            <div class="border-t border-slate-200 pt-6">
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <div class="flex flex-wrap items-center gap-2">
                            <h2 class="text-base font-semibold text-slate-900">{{ __('EPREL energetski podaci') }}</h2>
                            <span class="rounded-full px-2 py-0.5 text-xs font-semibold {{ ($form['msan_eprel_api_key_configured'] ?? false) ? 'bg-emerald-100 text-emerald-800' : 'bg-amber-100 text-amber-800' }}">
                                {{ ($form['msan_eprel_api_key_configured'] ?? false) ? __('API ključ spremljen') : __('API ključ nije spremljen') }}
                            </span>
                        </div>
                        <p class="mt-1 text-sm text-slate-600">{{ __('EPREL se poziva samo u pozadinskoj obradi za artikle s mapiranom grupom i pouzdanim registracijskim brojem ili modelom; webshop ga nikad ne poziva pri otvaranju stranice.') }}</p>
                    </div>
                    <button type="button" wire:click="$toggle('form.msan_eprel_enabled')" class="admin-switch" data-state="{{ ($form['msan_eprel_enabled'] ?? false) ? 'on' : 'off' }}" role="switch" aria-checked="{{ ($form['msan_eprel_enabled'] ?? false) ? 'true' : 'false' }}">
                        <span class="admin-switch-track"><span class="admin-switch-thumb"></span></span>
                        <span class="admin-switch-label">{{ ($form['msan_eprel_enabled'] ?? false) ? __('Uključeno') : __('Isključeno') }}</span>
                    </button>
                </div>
                @error('form.msan_eprel_enabled') <p class="mt-2 text-xs text-rose-600">{{ $message }}</p> @enderror

                <div class="mt-4 grid gap-4 lg:grid-cols-2">
                    <div class="lg:col-span-2">
                        <label class="mb-1 block text-xs font-semibold text-slate-600" for="msan-eprel-api-key">{{ __('EPREL API ključ') }}</label>
                        <input id="msan-eprel-api-key" type="password" maxlength="2048" wire:model="form.msan_eprel_api_key" autocomplete="new-password" placeholder="{{ ($form['msan_eprel_api_key_configured'] ?? false) ? __('API ključ je spremljen — prazno ga zadržava') : __('Unesite EPREL API ključ') }}" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">
                        <p class="mt-1 text-xs text-slate-500">{{ __('Ključ se sprema šifrirano i nakon spremanja se više ne prikazuje.') }}</p>
                        @error('form.msan_eprel_api_key') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-semibold text-slate-600" for="msan-eprel-connect-timeout">{{ __('Vremensko ograničenje spajanja (sekunde)') }}</label>
                        <input id="msan-eprel-connect-timeout" type="number" min="2" max="30" wire:model="form.msan_eprel_connect_timeout" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">
                        @error('form.msan_eprel_connect_timeout') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-semibold text-slate-600" for="msan-eprel-timeout">{{ __('Vremensko ograničenje odgovora (sekunde)') }}</label>
                        <input id="msan-eprel-timeout" type="number" min="5" max="120" wire:model="form.msan_eprel_timeout" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">
                        @error('form.msan_eprel_timeout') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                    </div>
                </div>
            </div>

            <div class="border-t border-slate-200 pt-6">
                <h2 class="text-base font-semibold text-slate-900">{{ __('Pravila uvoza') }}</h2>
                <div class="mt-4 grid gap-3 md:grid-cols-2">
                    @foreach ([
                        'msan_import_images' => [__('Preuzmi slike'), __('Slike se obrađuju zasebnim poslovima nakon artikla.')],
                        'msan_import_products_active' => [__('Nove artikle odmah aktiviraj'), __('Sigurnije je ostaviti isključeno do ručne provjere cijene i opisa.')],
                    ] as $field => [$label, $description])
                        <div class="rounded-xl border border-slate-200 bg-white p-4">
                            <div class="flex items-start justify-between gap-3">
                                <div><strong class="text-sm text-slate-900">{{ $label }}</strong><p class="mt-1 text-xs text-slate-600">{{ $description }}</p></div>
                                <button type="button" wire:click="$toggle('form.{{ $field }}')" class="admin-switch" data-state="{{ ($form[$field] ?? false) ? 'on' : 'off' }}" role="switch" aria-checked="{{ ($form[$field] ?? false) ? 'true' : 'false' }}"><span class="admin-switch-track"><span class="admin-switch-thumb"></span></span></button>
                            </div>
                        </div>
                    @endforeach
                </div>
                <div class="mt-5 rounded-xl border border-amber-200 bg-amber-50 p-4">
                    <h3 class="text-sm font-semibold text-slate-900">{{ __('Maksimalna prodajna količina prema M SAN dostupnosti') }}</h3>
                    <p class="mt-1 text-xs leading-5 text-slate-600">
                        {{ __('M SAN šalje samo razinu dostupnosti 0–4, a ne stvaran broj komada. Za svaku razinu odredite maksimalnu lokalnu količinu koju webshop smije nuditi. To je prodajni limit, ne potvrđena zaliha dobavljača.') }}
                    </p>
                </div>
                <div class="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-5">
                    @foreach ($availabilityLevelLabels as $level => $levelLabel)
                        <div class="rounded-xl border border-slate-200 bg-white p-3">
                            <label for="msan-stock-level-{{ $level }}" class="block">
                                <span class="block text-xs font-semibold text-slate-700">{{ __($levelLabel) }}</span>
                                <span class="mt-0.5 block text-[11px] text-slate-500">{{ __('M SAN razina :level', ['level' => $level]) }}</span>
                            </label>
                            <div class="mt-2 flex items-center gap-2">
                                <input id="msan-stock-level-{{ $level }}" type="number" min="0" max="999999" wire:model="form.msan_stock_level_{{ $level }}" aria-label="{{ __('Maksimalna lokalna količina za M SAN razinu :level', ['level' => $level]) }}" class="min-w-0 w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">
                                <span class="shrink-0 text-xs font-medium text-slate-500">{{ __('kom.') }}</span>
                            </div>
                            <p class="mt-1 text-[11px] text-slate-500">{{ __('Maksimalno za prodaju') }}</p>
                            @error('form.msan_stock_level_'.$level) <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                        </div>
                    @endforeach
                </div>
            </div>

            <div class="flex flex-wrap justify-end gap-2 border-t border-slate-200 pt-5">
                <p class="mr-auto self-center text-xs text-slate-500">{{ __('Provjere veze koriste zadnje spremljene postavke. Nakon izmjene najprije ih spremite.') }}</p>
                <button type="button" wire:click="testConnection" wire:loading.attr="disabled" class="rounded-xl border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-100">{{ __('Provjeri B2B vezu') }}</button>
                <button type="button" wire:click="testFtpConnection" wire:loading.attr="disabled" @disabled(!($form['msan_ftp_enabled'] ?? false)) class="rounded-xl border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-100 disabled:opacity-50">{{ __('Provjeri FTP') }}</button>
                <button type="submit" wire:loading.attr="disabled" wire:target="save" class="min-h-11 rounded-xl bg-cyan-700 px-4 py-2 text-sm font-semibold text-white hover:bg-cyan-800 disabled:cursor-wait disabled:opacity-60"><span wire:loading.remove wire:target="save">{{ __('Spremi M SAN postavke') }}</span><span wire:loading wire:target="save">{{ __('Spremam...') }}</span></button>
            </div>
        </form>
    </section>
</div>
