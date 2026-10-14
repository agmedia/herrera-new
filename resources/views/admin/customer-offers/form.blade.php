<x-admin-layout :title="$offer->exists ? $offer->number : __('customer_offers.create')">
@push('page-styles')
    @livewireStyles
@endpush
@php
    $editable = (! $offer->exists || $offer->status === 'draft') && ! data_get($offer->customer_snapshot, 'demo', false) && app(\App\Services\Sales\CustomerOfferAccess::class)->can(auth()->user(), 'manage');
    $initialItems = old('items', $offer->exists ? $offer->items->map(fn ($item) => ['product_id' => $item->product_id, 'product_option_value_id' => $item->product_option_value_id, 'name' => $item->name, 'sku' => $item->sku, 'option_label' => $item->option_label, 'description' => $item->description, 'image_url' => $item->image_url, 'quantity' => $item->quantity])->values()->all() : []);
@endphp
<div class="space-y-5">
<div class="flex flex-wrap items-center justify-between gap-4"><div><a href="{{ route('admin.customer-offers.index') }}" class="text-sm text-cyan-700">← {{ __('customer_offers.title') }}</a><h1 class="mt-2 text-2xl font-semibold">{{ $offer->exists ? $offer->number : __('customer_offers.create') }}</h1>
@if($offer->exists)<p class="mt-1 text-sm text-slate-500">{{ __('customer_offers.status.'.$offer->status) }} · {{ __('customer_offers.priced_at') }} {{ $offer->priced_at->format('d.m.Y. H:i') }}</p>@endif</div>
@if($offer->exists)<div class="flex gap-2"><a class="rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-semibold" target="_blank" href="{{ route('admin.customer-offers.preview', $offer) }}">{{ __('customer_offers.preview') }}</a><a class="rounded-lg bg-cyan-700 px-4 py-2 text-sm font-semibold text-white" href="{{ route('admin.customer-offers.pdf', ['offer' => $offer, 'download' => 1]) }}">{{ __('customer_offers.download_pdf') }}</a></div>@endif</div>
@if(session('status'))<p role="status" class="rounded-lg border border-cyan-200 bg-cyan-50 p-4 text-sm text-cyan-900">{{ session('status') }}</p>@endif
@if($errors->any())<div role="alert" class="rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-800">@foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif
@if(! $editable)<p class="rounded-lg border border-slate-200 bg-slate-50 p-4 text-sm">{{ __('customer_offers.immutable') }}</p>@endif
<form method="POST" action="{{ $offer->exists ? route('admin.customer-offers.update', $offer) : route('admin.customer-offers.store') }}" class="space-y-5"
      x-data="{
        customerId: @js((string) old('customer_user_id', $offer->customer_user_id ?? '')),
        customerName: @js((string) old('customer_display_name', data_get($offer->customer_snapshot, 'company', ''))),
        customerSearch: '', customers: [], customerLoading: false, customerSearched: false, customerError: '', customerRequest: 0,
        productSearch: '', products: [], productSearched: false, productError: '', productRequest: 0, items: @js($initialItems), loading: false,
        async findCustomers() {
            const request = ++this.customerRequest;
            this.customers = []; this.customerError = ''; this.customerSearched = false; this.customerLoading = false;
            if (this.customerSearch.trim().length < 2) return;
            this.customerLoading = true;
            try {
                const response = await fetch(@js(route('admin.customer-offers.customers')) + '?search=' + encodeURIComponent(this.customerSearch), {headers: {'Accept':'application/json'}});
                if (!response.ok || response.redirected) throw new Error('search');
                const rows = await response.json();
                if (!Array.isArray(rows)) throw new Error('search');
                if (request === this.customerRequest) { this.customers = rows; this.customerSearched = true; }
            } catch { if (request === this.customerRequest) this.customerError = @js(__('customer_offers.search_failed')); }
            finally { if (request === this.customerRequest) this.customerLoading = false; }
        },
        chooseCustomer(customer) { if (this.customerId !== String(customer.id) && this.items.length && !window.confirm(@js(__('customer_offers.change_customer_confirm')))) return; if (this.customerId !== String(customer.id)) this.items = []; ++this.customerRequest; ++this.productRequest; this.customerId = String(customer.id); this.customerName = customer.name + ' · ' + customer.email; this.customers = []; this.customerSearch = ''; this.customerSearched = false; this.customerError = ''; this.customerLoading = false; this.products = []; this.productSearch = ''; this.productSearched = false; this.productError = ''; this.loading = false; },
        async findProducts() {
            const request = ++this.productRequest;
            this.products = []; this.productError = ''; this.productSearched = false; this.loading = false;
            if (!this.customerId || this.productSearch.trim().length < 2) return;
            this.loading = true;
            try {
                const response = await fetch(@js(route('admin.customer-offers.products')) + '?customer_user_id=' + this.customerId + '&search=' + encodeURIComponent(this.productSearch), {headers: {'Accept':'application/json'}});
                if (!response.ok || response.redirected) throw new Error('search');
                const rows = await response.json();
                if (!Array.isArray(rows)) throw new Error('search');
                if (request === this.productRequest) { this.products = rows; this.productSearched = true; }
            } catch { if (request === this.productRequest) this.productError = @js(__('customer_offers.search_failed')); }
            finally { if (request === this.productRequest) this.loading = false; }
        },
        add(product) { const existing = this.items.find(item => Number(item.product_id) === Number(product.product_id) && Number(item.product_option_value_id || 0) === Number(product.product_option_value_id || 0)); if (existing) { existing.quantity = Number(existing.quantity) + Number(product.quantity); } else { this.items.push({...product}); } this.products = []; this.productSearch = ''; }
      }">
@csrf @if($offer->exists)@method('PUT')@endif
<fieldset @disabled(! $editable) class="space-y-5">
<div class="grid gap-5 rounded-xl border border-slate-200 bg-white p-5 lg:grid-cols-2">
<div class="space-y-2"><label for="offer-customer-search" class="block text-sm font-semibold">{{ __('customer_offers.customer') }}</label><input type="hidden" name="customer_user_id" x-model="customerId"><input type="hidden" name="customer_display_name" x-model="customerName"><p x-cloak x-show="customerId" x-text="customerName" class="rounded-lg bg-cyan-50 p-3 text-sm font-semibold text-cyan-900"></p>
<input id="offer-customer-search" type="search" x-model="customerSearch" @input.debounce.350ms="findCustomers()" class="w-full rounded-lg border-slate-300 text-sm" placeholder="{{ __('customer_offers.search_customers') }}" autocomplete="off">
<p class="text-xs text-slate-500">{{ __('customer_offers.customer_search_hint') }}</p>
<p x-cloak x-show="customerLoading" role="status" class="text-sm text-slate-500">{{ __('customer_offers.loading') }}</p>
<p x-cloak x-show="customerError" x-text="customerError" role="alert" class="text-sm text-red-700"></p>
<p x-cloak x-show="customerSearched && !customers.length && !customerError" role="status" class="text-sm text-slate-500">{{ __('customer_offers.no_customers') }}</p>
<div class="space-y-1" x-cloak x-show="customers.length"><template x-for="customer in customers" :key="customer.id"><button type="button" @click="chooseCustomer(customer)" class="block w-full rounded-lg border border-slate-200 p-3 text-left text-sm hover:bg-cyan-50"><strong x-text="customer.name"></strong><span class="ml-2 text-slate-500" x-text="customer.email"></span></button></template></div></div>
<div class="space-y-3"><label class="block text-sm font-semibold">{{ __('customer_offers.offer_title') }}<input name="title" maxlength="160" required value="{{ old('title', $offer->title) }}" class="mt-2 w-full rounded-lg border-slate-300 text-sm"></label><div class="grid grid-cols-2 gap-3"><label class="block text-sm font-semibold">{{ __('customer_offers.valid_until') }}<input name="valid_until" type="date" required min="{{ now()->format('Y-m-d') }}" value="{{ old('valid_until', $offer->valid_until?->format('Y-m-d')) }}" class="mt-2 w-full rounded-lg border-slate-300 text-sm"></label><label class="block text-sm font-semibold">{{ __('customer_offers.language') }}<select name="locale" class="mt-2 w-full rounded-lg border-slate-300 text-sm"><option value="hr" @selected(old('locale', $offer->locale) === 'hr')>Hrvatski</option><option value="en" @selected(old('locale', $offer->locale) === 'en')>English</option></select></label></div></div>
<label class="block text-sm font-semibold lg:col-span-2">{{ __('customer_offers.intro') }}<textarea name="intro" maxlength="500" rows="2" class="mt-2 w-full rounded-lg border-slate-300 text-sm">{{ old('intro', $offer->intro) }}</textarea></label>
</div>
<div class="rounded-xl border border-slate-200 bg-white p-5"><h2 class="text-lg font-semibold">{{ __('customer_offers.products') }}</h2><p class="mt-1 text-sm text-slate-500">{{ __('customer_offers.pricing_hint') }}</p>
<input type="search" aria-label="{{ __('customer_offers.search_products') }}" x-model="productSearch" @input.debounce.350ms="findProducts()" :disabled="!customerId" @disabled(! old('customer_user_id', $offer->customer_user_id)) class="mt-4 w-full rounded-lg border-slate-300 text-sm disabled:bg-slate-50 disabled:text-slate-400" placeholder="{{ __('customer_offers.search_products') }}" autocomplete="off"><p x-cloak x-show="loading" role="status" class="mt-2 text-sm text-slate-500">{{ __('customer_offers.loading') }}</p>
<p x-cloak x-show="productError" x-text="productError" role="alert" class="mt-2 text-sm text-red-700"></p>
<p x-cloak x-show="productSearched && !products.length && !productError" role="status" class="mt-2 text-sm text-slate-500">{{ __('customer_offers.no_products') }}</p>
<div class="mt-2 grid gap-2 lg:grid-cols-2" x-cloak x-show="products.length"><template x-for="product in products" :key="product.product_id + ':' + product.product_option_value_id"><button @click="add(product)" type="button" class="flex gap-3 rounded-lg border border-slate-200 p-3 text-left text-sm hover:bg-cyan-50"><img x-show="product.image_url" :src="product.image_url" alt="" class="h-16 w-16 object-contain"><span><strong x-text="product.name"></strong><span class="mt-1 block text-xs text-slate-500" x-text="product.sku + (product.option_label ? ' · ' + product.option_label : '')"></span></span></button></template></div>
<p x-cloak x-show="!items.length" class="mt-5 rounded-lg border border-dashed border-slate-300 p-6 text-center text-sm text-slate-500">{{ __('customer_offers.add_products') }}</p>
<div class="mt-5 space-y-4"><template x-for="(item, index) in items" :key="index"><article class="grid gap-4 rounded-lg border border-slate-200 p-4 sm:grid-cols-[120px_1fr_110px]">
<div><img x-show="item.image_url" :src="item.image_url" alt="" class="h-28 w-28 object-contain"></div><div><strong class="text-sm" x-text="item.name || item.product_id"></strong><p class="mt-1 text-xs text-slate-500" x-text="(item.sku || '') + (item.option_label ? ' · ' + item.option_label : '')"></p><input type="hidden" :name="'items['+index+'][product_id]'" :value="item.product_id"><input type="hidden" :name="'items['+index+'][product_option_value_id]'" :value="item.product_option_value_id || ''"><label class="mt-3 block text-xs font-semibold">{{ __('customer_offers.description') }}<textarea rows="3" maxlength="500" :name="'items['+index+'][description]'" x-model="item.description" class="mt-1 w-full rounded-lg border-slate-300 text-sm"></textarea></label></div>
<div><label class="block text-xs font-semibold">{{ __('customer_offers.quantity') }}<input type="number" min="1" max="99999" required :name="'items['+index+'][quantity]'" x-model.number="item.quantity" class="mt-2 w-full rounded-lg border-slate-300 text-sm"></label><button type="button" @click="items.splice(index, 1)" class="mt-4 text-xs font-semibold text-red-600">{{ __('customer_offers.remove') }}</button></div>
</article></template></div></div>
<div class="rounded-xl border border-slate-200 bg-white p-5"><label class="block text-sm font-semibold">{{ __('customer_offers.notes') }}<textarea name="notes" maxlength="1000" rows="3" class="mt-2 w-full rounded-lg border-slate-300 text-sm">{{ old('notes', $offer->notes) }}</textarea></label></div>
@if($editable)<button type="submit" class="rounded-lg bg-cyan-700 px-5 py-3 text-sm font-semibold text-white">{{ __('customer_offers.save') }}</button>@endif
</fieldset></form>
@if($offer->exists)
<section class="rounded-xl border border-slate-200 bg-white p-5"><div class="flex flex-wrap justify-between gap-4"><div><h2 class="font-semibold">{{ __('customer_offers.summary') }}</h2><p class="mt-1 text-sm text-slate-500">{{ __('customer_offers.net_total') }} {{ number_format((float) $offer->net_total, 2, ',', '.') }} € · {{ __('customer_offers.vat') }} {{ number_format((float) $offer->tax_total, 2, ',', '.') }} €</p></div><p class="text-xl font-bold text-cyan-700">{{ __('customer_offers.total') }} {{ number_format((float) $offer->gross_total, 2, ',', '.') }} €</p></div>
@if($offer->status === 'draft' && ! data_get($offer->customer_snapshot, 'demo', false) && app(\App\Services\Sales\CustomerOfferAccess::class)->can(auth()->user(), 'send'))<form method="POST" action="{{ route('admin.customer-offers.send', $offer) }}" class="mt-5 border-t border-slate-200 pt-5">@csrf<label class="flex items-center gap-2 text-sm"><input type="checkbox" name="confirm_send" value="1" required class="rounded border-slate-300 text-cyan-700">{{ __('customer_offers.confirm_send', ['email' => data_get($offer->customer_snapshot, 'email')]) }}</label><button class="mt-3 rounded-lg bg-cyan-700 px-4 py-2 text-sm font-semibold text-white">{{ __('customer_offers.send') }}</button><p class="mt-2 text-xs text-slate-500">{{ __('customer_offers.send_hint') }}</p></form>@endif
@if($offer->deliveries->isNotEmpty())<div class="mt-5 border-t border-slate-200 pt-4"><h3 class="text-sm font-semibold">{{ __('customer_offers.delivery_history') }}</h3>@foreach($offer->deliveries as $delivery)<p class="mt-2 text-xs text-slate-500">{{ $delivery->created_at->format('d.m.Y. H:i') }} · {{ $delivery->recipient }} · {{ __('customer_offers.delivery.'.$delivery->status) }}</p>@endforeach</div>@endif
</section>@endif
</div>
{{-- This plain Blade form uses Alpine without rendering a Livewire component. --}}
@livewireScripts
</x-admin-layout>
