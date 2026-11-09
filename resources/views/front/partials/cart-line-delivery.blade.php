@if (!empty($line['delivery']['label']))
    <div data-cart-line-delivery="{{ $line['key'] }}">
        <p class="mt-1 text-xs text-emerald-800">{{ __('Procjena isporuke') }}: <span data-delivery-label>{{ $line['delivery']['label'] }}</span></p>
        <p class="mt-0.5 text-xs text-slate-500" data-delivery-pickup @if (empty($line['delivery']['warehouse_date'])) hidden @endif>{{ __('Za osobno preuzimanje') }}: <span data-delivery-warehouse-date>{{ !empty($line['delivery']['warehouse_date']) ? \Carbon\Carbon::parse($line['delivery']['warehouse_date'])->format('d.m.Y.') : '' }}</span>, {{ __('uz telefonski dogovor') }}</p>
    </div>
@endif
