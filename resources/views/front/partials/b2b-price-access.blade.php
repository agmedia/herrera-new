@if (empty($compact) || auth()->check())
<div class="b2b-price-access text-sm leading-relaxed text-slate-700" data-b2b-price-access>
    @auth
        <p>{{ __('ui.b2b.pricing.approval_required') }}</p>
        @if (empty($compact))
            <a href="{{ route('account.dashboard') }}" class="mt-2 inline-block font-semibold text-cyan-800 underline underline-offset-2">{{ __('ui.b2b.pricing.account') }}</a>
        @endif
    @else
        <p>{{ __('ui.b2b.pricing.login_required') }}</p>
        @if (empty($compact))
            <div class="mt-2 flex flex-wrap gap-x-4 gap-y-2">
                <a href="{{ route('front.auth.login') }}" class="font-semibold text-cyan-800 underline underline-offset-2">{{ __('ui.b2b.pricing.login') }}</a>
                <a href="{{ route('front.auth.b2b-register') }}" class="font-semibold text-cyan-800 underline underline-offset-2">{{ __('ui.b2b.pricing.register') }}</a>
            </div>
        @endif
    @endauth
</div>
@endif
