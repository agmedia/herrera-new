<section class="admin-panel p-5" aria-labelledby="product-group-prices-title">
    <h2 id="product-group-prices-title" class="admin-section-title">{{ __('Cijene grupa za jedan artikl') }}</h2>
    <p class="mt-1 text-sm text-slate-500">{{ __('Pronađite artikl i upišite ugovorenu cijenu za željene grupe. Prazna polja ne brišu postojeće cijene.') }}</p>
    <div class="mt-4 grid gap-3 md:grid-cols-2">
        <input type="search" wire:model.live.debounce.300ms="productSearch" placeholder="Šifra, SKU ili naziv artikla" aria-label="Pretraži artikl za cijene grupa" class="admin-search-input rounded-lg px-3 py-2">
        <select wire:model.live="groupProductId" aria-label="Odaberite artikl za cijene grupa" class="admin-search-input admin-select rounded-lg px-3 py-2"><option value="">{{ __('Odaberite artikl iz pretrage') }}</option>@foreach ($products as $product)<option value="{{ $product->id }}">{{ $product->sku ?: $product->code }} — {{ $product->translations->firstWhere('locale', app()->getLocale())?->name ?? $product->code }}</option>@endforeach</select>
    </div>
    @if ($groupProduct)
        <div class="mt-5 rounded-lg bg-slate-50 p-4"><h3 class="font-semibold">{{ $groupProduct['sku'] }} — {{ $groupProduct['name'] }}</h3><p class="mt-1 text-sm">{{ __('Osnovna cijena cjenika') }}: <strong>{{ $groupProduct['base_price'] }} EUR</strong></p></div>
        <form wire:submit="saveGroupPrices" class="mt-4" data-product-group-prices-form>
            <div class="overflow-x-auto"><table class="admin-items-table min-w-full text-sm"><thead><tr><th class="p-3 text-left">{{ __('Grupa kupaca') }}</th><th class="p-3 text-left">{{ __('Ugovorena cijena EUR') }}</th><th class="p-3 text-right">{{ __('Popust prema osnovnoj cijeni') }}</th></tr></thead><tbody>
                @foreach ($groups->where('is_active', true) as $group)
                    @php
                        $value = str_replace(',', '.', (string) ($groupPrices[$group->id] ?? ''));
                        $discount = is_numeric($value) && (float) $groupProduct['base_price'] > 0 ? (1 - (float) $value / (float) $groupProduct['base_price']) * 100 : null;
                    @endphp
                    <tr class="border-t border-slate-100" wire:key="product-group-price-{{ $groupProduct['id'] }}-{{ $group->id }}">
                        <td class="p-3 font-semibold">{{ $group->name }}</td>
                        <td class="p-3"><input type="text" inputmode="decimal" wire:model.blur="groupPrices.{{ $group->id }}" aria-label="Cijena za {{ $group->name }}" placeholder="Bez posebne cijene" @disabled($catalog->status !== 'draft' || !$canEditPricing) class="admin-search-input w-full max-w-xs rounded-lg px-3 py-2"></td>
                        <td class="p-3 text-right text-slate-500">{{ $discount !== null ? number_format($discount, 2, ',', '.').' %' : '—' }}</td>
                    </tr>
                @endforeach
            </tbody></table></div>
            <p class="mt-3 rounded-lg bg-cyan-50 p-3 text-sm text-slate-600">{{ __('Ovo su redovne cijene grupa. Pravilo popusta, akcijska, količinska ili individualna cijena može imati prednost. Konačnu cijenu konkretnog kupca provjerite u kartici „Provjera cijene”.') }}</p>
            @if ($catalog->status === 'draft' && $canEditPricing)<button type="submit" wire:loading.attr="disabled" class="admin-btn-primary mt-4">{{ __('Spremi cijene grupa u radnu verziju') }}</button>@else<p class="mt-4 text-sm text-slate-500">{{ __('Za izmjenu cijena napravite novu radnu kopiju cjenika.') }}</p>@endif
        </form>
    @endif
</section>
