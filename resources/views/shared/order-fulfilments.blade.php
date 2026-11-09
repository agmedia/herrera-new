@foreach ($fulfilments as $fulfilment)
    <div class="mt-1 text-xs text-slate-600" style="font-size:12px; margin-top:4px;" data-order-fulfilment="{{ $fulfilment['source'] }}">
        <strong>{{ $fulfilment['quantity'] }}@if (!empty($fulfilment['unit'])) {{ $fulfilment['unit'] }}@endif</strong> — {{ $fulfilment['label'] }}
    </div>
@endforeach
