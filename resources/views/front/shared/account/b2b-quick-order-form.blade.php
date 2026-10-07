@if (! $canViewPrices)
    @include('front.partials.b2b-price-access')
@else
<div
    class="quick-order-builder"
    data-quick-order-builder
    data-search-url="{{ route('account.b2b.quick-order.search') }}"
    data-resolve-url="{{ route('account.b2b.quick-order.resolve') }}"
    data-initial-query="{{ $initialQuickOrderQuery ?? '' }}"
    data-sync-url="{{ route('account.b2b.quick-order.draft') }}"
    data-storage-key="b2b-quick-order-draft-{{ auth()->id() }}"
    data-min-search-length="2"
    data-searching-label="{{ __('Pretraživanje artikala...') }}"
    data-empty-search-label="{{ __('Nema pronađenih artikala.') }}"
    data-min-search-label="{{ __('Upišite najmanje 2 znaka.') }}"
    data-empty-selection-label="{{ __('Još niste dodali nijedan artikl.') }}"
    data-remove-label="{{ __('Ukloni artikl') }}"
    data-b2b-label="B2B"
    data-saving-label="{{ __('Spremanje nacrta...') }}"
    data-saved-label="{{ __('Nacrt spremljen. Možete nastaviti kasnije.') }}"
    data-save-error-label="{{ __('Nacrt je spremljen u ovom pregledniku. Spremanje na račun nije uspjelo.') }}"
    data-cleared-label="{{ __('Odabrani artikli su uklonjeni.') }}"
    data-limit-label="{{ __('Možete odabrati najviše 100 različitih stavki.') }}"
    data-added-label="{{ __('Artikl dodan u odabrane stavke.') }}"
    data-importing-label="{{ __('Provjera artikala...') }}"
    data-import-error-label="{{ __('Provjera nije uspjela. Pokušajte ponovno.') }}"
    data-import-success-label="{{ __('Dodano stavki') }}"
    data-search-error-label="{{ __('Pretraživanje nije uspjelo. Pokušajte ponovno.') }}"
    data-price-excludes-tax-label="{{ __('ui.b2b.pricing.excludes_tax') }}"
>
    <script type="application/json" data-quick-order-initial>@json($initialQuickOrderItems ?? [])</script>
    <script type="application/json" data-quick-order-suggestions>@json($quickOrderSuggestions ?? [])</script>

    <form method="POST" action="{{ route('account.b2b.quick-order.store') }}" data-quick-order-form>
        @csrf

        @error('items')
            <p class="quick-order-alert" role="alert">{{ $message }}</p>
        @enderror

        <div class="quick-order-search-block">
            <div class="quick-order-search-panel">
                <label for="quick-order-search" class="quick-order-search-label">{{ __('Pronađite artikl') }}</label>
                <p id="quick-order-search-help" class="quick-order-search-help">
                    {{ __('Pretražujte po nazivu, šifri, SKU-u ili barkodu.') }}
                </p>

                <div class="quick-order-combobox">
                    <span class="quick-order-search-icon" aria-hidden="true">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor">
                            <circle cx="11" cy="11" r="7"></circle>
                            <path d="m20 20-3.5-3.5"></path>
                        </svg>
                    </span>
                    <input
                        id="quick-order-search"
                        type="search"
                        class="quick-order-search-input"
                        placeholder="{{ __('Upišite naziv, šifru, SKU ili barkod...') }}"
                        maxlength="100"
                        autocomplete="off"
                        spellcheck="false"
                        role="combobox"
                        aria-autocomplete="list"
                        aria-controls="quick-order-results"
                        aria-expanded="false"
                        aria-describedby="quick-order-search-help"
                        data-quick-order-search
                    >
                    <span class="quick-order-spinner" data-quick-order-spinner hidden aria-hidden="true"></span>

                    <div
                        id="quick-order-results"
                        class="quick-order-results"
                        role="listbox"
                        data-quick-order-results
                        hidden
                    ></div>
                </div>
                <p class="quick-order-keyboard-help">{{ __('Tipke ↑ ↓ za odabir, Enter za dodavanje artikla.') }}</p>
            </div>

            @if (($unavailableDraftCount ?? 0) > 0)
                <p class="quick-order-alert" role="status">{{ __(':count spremljenih stavki više nije dostupno i nisu uključene u narudžbu.', ['count' => $unavailableDraftCount]) }}</p>
            @endif

            <div class="quick-order-suggestions" data-quick-order-suggestions-panel hidden>
                <div class="quick-order-suggestion-tabs" role="tablist" aria-label="{{ __('Vaši artikli') }}">
                    @foreach (['frequent' => __('Često naručivani'), 'favorites' => __('Favoriti'), 'recent' => __('Nedavno naručeni')] as $key => $label)
                        <button type="button" id="quick-order-tab-{{ $key }}" role="tab" aria-selected="{{ $loop->first ? 'true' : 'false' }}" aria-controls="quick-order-suggestion-items" tabindex="{{ $loop->first ? '0' : '-1' }}" class="quick-order-suggestion-tab" data-quick-order-suggestion-tab="{{ $key }}">{{ $label }}</button>
                    @endforeach
                </div>
                <div id="quick-order-suggestion-items" class="quick-order-suggestion-items" role="tabpanel" aria-labelledby="quick-order-tab-frequent" data-quick-order-suggestion-items></div>
            </div>

            <details class="quick-order-bulk">
                <summary>{{ __('Imate popis šifri? Zalijepite više artikala odjednom') }}</summary>
                <div class="quick-order-bulk-content">
                    <label for="quick-order-bulk-lines">{{ __('Šifra, SKU ili barkod i količina, jedan artikl po retku') }}</label>
                    <p id="quick-order-bulk-help">{{ __('Format: šifra; količina. Možete kopirati i dva stupca iz Excela. Bez količine dodaje se 1 komad. Za artikle s varijantama unesite SKU varijante.') }}</p>
                    <textarea id="quick-order-bulk-lines" rows="4" maxlength="20000" placeholder="SKU-001; 5&#10;SKU-002; 2" aria-describedby="quick-order-bulk-help" data-quick-order-bulk-lines></textarea>
                    <button type="button" class="quick-order-secondary" data-quick-order-import>{{ __('Provjeri i dodaj artikle') }}</button>
                    <div class="quick-order-import-feedback" role="status" aria-live="polite" data-quick-order-import-feedback hidden></div>
                </div>
            </details>
        </div>

        <div class="quick-order-selection">
            <div class="quick-order-selection-heading">
                <div>
                    <h3>{{ __('Odabrani artikli') }}</h3>
                    <p>{{ __('Promijenite količinu ili uklonite stavku prije dodavanja u košaricu.') }}</p>
                </div>
                <div class="quick-order-selection-tools">
                    <span class="quick-order-count" data-quick-order-count>0 {{ __('stavki') }}</span>
                    <button type="button" class="quick-order-clear" data-quick-order-clear hidden>{{ __('Ukloni sve') }}</button>
                    <button type="button" class="quick-order-clear" data-quick-order-undo hidden>{{ __('Vrati uklonjeno') }}</button>
                </div>
            </div>

            <div class="quick-order-draft-status" role="status" aria-live="polite" data-quick-order-draft-status></div>
            <span class="sr-only" role="status" aria-live="polite" data-quick-order-announcement></span>

            <div class="quick-order-empty" data-quick-order-empty>
                <span aria-hidden="true">＋</span>
                <p>{{ __('Još niste dodali nijedan artikl.') }}</p>
            </div>

            <div class="quick-order-lines" data-quick-order-lines hidden></div>

            <div class="quick-order-footer" data-quick-order-footer hidden>
                <div class="quick-order-total">
                    <span>{{ __('Ukupno') }}</span>
                    <strong data-quick-order-total>0,00 €</strong>
                    @include('front.partials.b2b-tax-note')
                    <small>{{ __('Konačna cijena potvrđuje se u košarici.') }}</small>
                </div>
                <div class="quick-order-actions">
                    <a href="{{ route('cart.index') }}" class="quick-order-secondary">{{ __('Otvori košaricu') }}</a>
                    <button type="submit" class="quick-order-primary" data-quick-order-submit disabled>
                        {{ __('Dodaj sve u košaricu') }}
                    </button>
                </div>
            </div>
        </div>
    </form>
</div>
@endif
