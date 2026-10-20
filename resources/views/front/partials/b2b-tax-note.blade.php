@if (($canViewPrices ?? app(\App\Services\Pricing\B2BAccessService::class)->canViewPrices(auth()->user())) && ($includesTax ?? ! (app(\App\Services\Pricing\B2BAccessService::class)->displaysNet(auth()->user()))) === false)
    <small class="block text-[10px] font-medium leading-tight text-slate-500" data-price-excludes-tax>{{ $taxNoteLabel ?? __('ui.b2b.pricing.excludes_tax') }}</small>
@elseif (app(\App\Services\Pricing\B2BAccessService::class)->b2cEnabled() && ($canViewPrices ?? true) && ($includesTax ?? ! app(\App\Services\Pricing\B2BAccessService::class)->displaysNet(auth()->user())))
    <small class="block text-[10px] font-medium leading-tight text-slate-500" data-price-includes-tax>{{ __('s PDV-om') }}</small>
@endif
