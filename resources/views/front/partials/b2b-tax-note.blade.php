@if (($canViewPrices ?? app(\App\Services\Pricing\B2BAccessService::class)->canViewPrices(auth()->user())) && ($includesTax ?? ! ((bool) config('commerce.b2b_only') && (bool) config('commerce.b2b_display_net', true))) === false)
    <small class="block text-[10px] font-medium leading-tight text-slate-500" data-price-excludes-tax>{{ $taxNoteLabel ?? __('ui.b2b.pricing.excludes_tax') }}</small>
@endif
