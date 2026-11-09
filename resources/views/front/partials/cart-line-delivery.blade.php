@if (!empty($line['delivery']['label']))
    <p class="mt-1 text-xs text-emerald-800">{{ __('Procjena isporuke') }}: {{ $line['delivery']['label'] }}</p>
    @if (!empty($line['delivery']['warehouse_date']))
        <p class="mt-0.5 text-xs text-slate-500">{{ __('Za osobno preuzimanje') }}: {{ \Carbon\Carbon::parse($line['delivery']['warehouse_date'])->format('d.m.Y.') }}, {{ __('uz telefonski dogovor') }}</p>
    @endif
@endif
