<div
    class="quick-order-builder"
    data-quick-order-builder
    data-search-url="{{ route('account.b2b.quick-order.search') }}"
    data-sync-url="{{ route('account.b2b.quick-order.draft') }}"
    data-storage-key="b2b-quick-order-draft-{{ auth()->id() }}"
    data-min-search-length="2"
    data-searching-label="{{ __('Pretraživanje artikala...') }}"
    data-empty-search-label="{{ __('Nema pronađenih artikala.') }}"
    data-min-search-label="{{ __('Upišite najmanje 2 znaka.') }}"
    data-empty-selection-label="{{ __('Još niste dodali nijedan artikl.') }}"
    data-remove-label="{{ __('Ukloni artikl') }}"
    data-b2b-label="B2B"
>
    <script type="application/json" data-quick-order-initial>@json($initialQuickOrderItems ?? [])</script>

    <form method="POST" action="{{ route('account.b2b.quick-order.store') }}" data-quick-order-form>
        @csrf

        @error('items')
            <p class="quick-order-alert" role="alert">{{ $message }}</p>
        @enderror

        <div class="quick-order-search-block">
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
        </div>

        <div class="quick-order-selection">
            <div class="quick-order-selection-heading">
                <div>
                    <h3>{{ __('Odabrani artikli') }}</h3>
                    <p>{{ __('Promijenite količinu ili uklonite stavku prije dodavanja u košaricu.') }}</p>
                </div>
                <span class="quick-order-count" data-quick-order-count>0 {{ __('artikala') }}</span>
            </div>

            <div class="quick-order-empty" data-quick-order-empty>
                <span aria-hidden="true">＋</span>
                <p>{{ __('Još niste dodali nijedan artikl.') }}</p>
            </div>

            <div class="quick-order-lines" data-quick-order-lines hidden></div>

            <div class="quick-order-footer" data-quick-order-footer hidden>
                <div class="quick-order-total">
                    <span>{{ __('Ukupno') }}</span>
                    <strong data-quick-order-total>0,00 €</strong>
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
