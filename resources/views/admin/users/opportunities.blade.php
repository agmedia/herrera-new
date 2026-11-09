<x-admin-layout :title="__('Prodajne prilike')">
    <div class="space-y-6">
        <section class="admin-panel p-5 sm:p-6">
            <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('Komercijala') }}</p>
            <h1 class="mt-2 text-2xl font-semibold text-slate-900">{{ __('Prodajne prilike za partnera') }}</h1>
            <p class="mt-2 max-w-4xl text-sm text-slate-600">{{ __('Najprodavaniji artikli cijele tvrtke u posljednjih 90 dana bez povezane kupnje odabranog partnera u posljednjih 365 dana. Poredak daje prednost broju poznatih kupaca, zatim neto prodanoj količini. Kategorijom možete suziti prijedloge.') }}</p>
            <p class="mt-2 text-xs text-slate-500">{{ __('Izvor su izdani ERP računi, POS računi i odobrenja. Otpremnice se ne zbrajaju ponovno. Storna i odobrenja umanjuju neto količine. Anonimna POS prodaja ulazi u količinu, a ne u broj poznatih kupaca. Podaci drugih kupaca nisu prikazani.') }}</p>
            @if (auth()->user()->isA('superadmin') || auth()->user()->can('integrations.eracuni.manage'))
            <form method="POST" action="{{ route('admin.users.opportunities.refresh') }}" class="mt-4">
                @csrf
                <button class="rounded-xl border border-slate-300 px-4 py-2 text-sm font-semibold">{{ __('Osvježi ERP pregled') }}</button>
            </form>
            @endif
            @if (session('status'))<p role="status" class="mt-3 rounded-xl bg-cyan-50 p-3 text-sm text-cyan-900">{{ session('status') }}</p>@endif
            @if ($latestAttempt?->status === 'running')<p role="status" class="mt-3 text-sm text-cyan-800">{{ __('ERP pregled je u obradi. Osvježite ovu stranicu nakon dovršetka.') }}</p>@endif
            @if ($latestAttempt?->status === 'failed')<p role="status" class="mt-3 text-sm text-amber-800">{{ $latestAttempt->message }}</p>@endif
            @if ($snapshot)
                <p class="mt-3 text-xs text-slate-500">{{ __('Presjek') }}: {{ $snapshot->as_of }} · {{ __('Dokumenti') }}: {{ number_format($snapshot->document_count, 0, ',', '.') }} · {{ __('Stavke bez jednoznačnog artikla u katalogu') }}: {{ number_format($snapshot->unmapped_lines, 0, ',', '.') }}.</p>
                @if ($snapshot->message)<p role="status" class="mt-2 rounded-xl bg-amber-50 p-3 text-sm text-amber-900">{{ $snapshot->message }}</p>@endif
                @if ($snapshot->as_of !== today()->toDateString())<p class="mt-2 text-sm text-amber-800">{{ __('Prikazan je stariji ERP presjek. Nove potvrđene webshop narudžbe odabranog partnera izuzimaju se odmah; za ostalu prodaju osvježite ERP pregled.') }}</p>@endif
            @elseif ($latestAttempt?->status === 'running')
                <p role="status" class="mt-3 rounded-xl bg-cyan-50 p-3 text-sm text-cyan-900">{{ __('Prvi potpuni ERP pregled je u obradi. Prijedlozi će biti dostupni nakon dovršetka.') }}</p>
            @else
                <p class="mt-3 rounded-xl bg-slate-50 p-3 text-sm text-slate-700">{{ __('Još nema potpunog ERP pregleda. Pokrenite osvježavanje prije prikaza prodajnih prilika.') }}</p>
            @endif
        </section>
        <form method="GET" class="admin-panel grid gap-4 p-5 sm:grid-cols-2 sm:p-6 xl:grid-cols-[minmax(240px,2fr)_minmax(200px,1fr)_140px_auto] xl:items-end">
            <label class="min-w-0 text-sm font-semibold text-slate-600">{{ __('Partner') }}
                <select name="user_id" data-tom-select data-tom-placeholder="{{ __('Pretražite i odaberite partnera') }}" class="mt-2 admin-select block w-full rounded-xl border-slate-300 text-sm" required>
                    <option value="">{{ __('Odaberite partnera') }}</option>
                    @foreach ($customers as $customer)<option value="{{ $customer->id }}" @selected($selectedCustomer?->id === $customer->id)>{{ trim((string) $customer->b2bAccount?->company_name) ?: $customer->name }}</option>@endforeach
                </select>
            </label>
            <label class="min-w-0 text-sm font-semibold text-slate-600">{{ __('Kategorija') }}
                <select name="category_id" class="mt-2 block w-full rounded-xl border-slate-300 text-sm">
                    <option value="">{{ __('Sve kategorije') }}</option>
                    @foreach ($categories as $category)<option value="{{ $category->id }}" @selected($categoryId === $category->id)>{{ $category->translations->firstWhere('locale', 'hr')?->name ?: $category->code }}</option>@endforeach
                </select>
            </label>
            <label class="text-sm font-semibold text-slate-600">{{ __('Po stranici') }}
                <select name="per_page" class="mt-2 block w-full rounded-xl border-slate-300 text-sm">
                    @foreach ($pageSizes as $size)<option value="{{ $size }}" @selected($perPage === $size)>{{ $size }}</option>@endforeach
                </select>
            </label>
            <button class="rounded-xl bg-cyan-700 px-4 py-2 text-sm font-semibold text-white">{{ __('Prikaži prilike') }}</button>
        </form>
        @if ($products)
            <section class="admin-panel overflow-hidden">
                <div class="flex flex-wrap items-start justify-between gap-4 border-b border-slate-200 p-5">
                    <div class="min-w-0 flex-1">
                        <h2 class="break-words text-lg font-semibold">{{ $selectedCustomer->customerDisplayName() }}</h2>
                        <p class="mt-1 text-sm font-semibold text-cyan-800">{{ number_format($products->total(), 0, ',', '.') }} {{ __('prodajnih prilika') }}@if ($products->total()) · {{ __('Prikaz') }} {{ $products->firstItem() }}–{{ $products->lastItem() }}@endif</p>
                        <p class="mt-2 text-xs text-slate-500">{{ __('Nakon nove potvrđene kupnje artikl nestaje iz prijedloga, a sljedeći po rangu zauzima njegovo mjesto.') }}</p>
                    </div>
                    <div class="flex flex-col items-start gap-1 sm:items-end">
                        <a href="{{ route('admin.users.opportunities.export', ['user_id' => $selectedCustomer->id, 'category_id' => $categoryId ?: null, 'per_page' => $perPage]) }}" class="inline-flex items-center gap-2 rounded-xl bg-cyan-700 px-4 py-2.5 text-sm font-semibold text-white hover:bg-cyan-800" data-opportunities-export>
                            <svg class="h-4 w-4" viewBox="0 0 20 20" fill="none" aria-hidden="true"><path d="M10 3v9m-3-3 3 3 3-3M4 13v4h12v-4" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
                            {{ __('Preuzmi Excel') }}
                        </a>
                        <span class="text-xs text-slate-500">{{ __('Sve filtrirane prilike, ne samo ova stranica.') }}</span>
                    </div>
                </div>
                <div class="overflow-x-auto"><table class="w-full min-w-[760px] text-left text-sm">
                    <thead class="bg-slate-50 text-xs text-slate-500"><tr><th class="w-16 p-4 text-right">#</th><th class="p-4">{{ __('Artikl') }}</th><th class="p-4">{{ __('Šifra / SKU / EAN') }}</th><th class="p-4 text-right">{{ __('Poznati kupci · 90 dana') }}</th><th class="p-4 text-right">{{ __('Neto količina · 90 dana') }}</th></tr></thead>
                    <tbody>
                    @forelse ($products as $product)
                        @php
                            $translation = $product->translations->firstWhere('locale', 'hr') ?: $product->translations->first();
                            $name = $translation?->name ?: $product->code;
                            $thumbnail = \App\Support\Media\LegacyCatalogImage::first($product, ['thumb_100x100']);
                            $categoryNames = $product->categories->map(fn ($category) => ($category->translations->firstWhere('locale', 'hr') ?: $category->translations->first())?->name ?: $category->code)->implode(', ');
                        @endphp
                        <tr class="border-t border-slate-200 align-top hover:bg-slate-50/60">
                            <td class="p-4 text-right text-xs tabular-nums text-slate-400">{{ $products->firstItem() + $loop->index }}</td>
                            <td class="p-4"><div class="flex min-w-64 items-start gap-3">
                                <div class="relative h-14 w-14 shrink-0 overflow-hidden rounded-lg border border-slate-200 bg-slate-50" data-opportunity-thumbnail>
                                    <span class="absolute inset-0 flex items-center justify-center text-slate-400" data-opportunity-image-fallback aria-label="{{ __('Slika nije dostupna') }}"><svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" aria-hidden="true"><rect x="3" y="3" width="18" height="18" rx="3" stroke="currentColor" stroke-width="1.5"/><circle cx="8" cy="8" r="1.5" fill="currentColor"/><path d="m4 18 5-5 3 3 4-5 5 7" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/></svg></span>
                                    @if ($thumbnail)<img src="{{ $thumbnail }}" alt="{{ $name }}" loading="lazy" decoding="async" width="56" height="56" class="relative h-full w-full bg-white object-contain p-1" onerror="this.hidden=true" data-opportunity-product-image>@endif
                                </div>
                                <div class="min-w-0"><p class="font-semibold">@if ($translation?->slug)<a href="{{ route('products.show', ['slug' => $translation->slug]) }}" target="_blank" rel="noopener" class="text-cyan-800 hover:underline">{{ $name }}</a>@else{{ $name }}@endif</p>@if ($categoryNames)<p class="mt-1 max-w-md text-xs text-slate-500">{{ $categoryNames }}</p>@endif</div>
                            </div></td>
                            <td class="p-4 font-mono text-xs"><span class="font-semibold text-slate-800">{{ $product->code }}</span>@if ($product->sku && $product->sku !== $product->code)<span class="mt-1 block text-slate-500">SKU: {{ $product->sku }}</span>@endif @if ($product->barcode)<span class="mt-1 block whitespace-nowrap text-slate-500">EAN: {{ $product->barcode }}</span>@endif</td>
                            <td class="p-4 text-right font-semibold tabular-nums">{{ number_format($product->opportunity_buyers, 0, ',', '.') }}</td>
                            <td class="p-4 text-right tabular-nums">{{ number_format($product->opportunity_quantity, 2, ',', '.') }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="p-8 text-center text-slate-600">{{ __('Za ovaj presjek i odabranu kategoriju nema novih prijedloga.') }}</td></tr>
                    @endforelse
                    </tbody>
                </table></div>
                <div class="p-5">{{ $products->links() }}</div>
            </section>
        @endif
    </div>
</x-admin-layout>
