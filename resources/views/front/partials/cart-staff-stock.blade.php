<div class="mt-2 text-xs text-slate-600" data-cart-staff-stock>
    <p>{{ __('Vlastita zaliha') }}: <strong>{{ $staffStock['own'] }}</strong></p>
    <p>{{ __('Zaliha dobavljača') }}: <strong>{{ $staffStock['supplier'] ?? '—' }}</strong></p>
    @if ($staffStock['supplier'] === null)
        <p>{{ __('Zaliha dobavljača nije dostupna po varijanti.') }}</p>
    @endif
</div>
