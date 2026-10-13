@if (session()->has(\App\Services\User\CustomerImpersonationService::SESSION_KEY))
    @php
        $customerOrderingContext = app(\App\Services\User\CustomerImpersonationService::class)->validatedContext(request());
        $customerOrderingStaffPreview = $customerOrderingContext['staff_impersonation'] ?? null;
    @endphp
    <div role="status" class="border-b border-amber-300 bg-amber-50 text-amber-950" style="position:relative;z-index:50;background:#fffbeb;color:#78350f;border-bottom:1px solid #fcd34d;">
        <div class="container mx-auto flex flex-wrap items-center justify-between gap-3 px-4 py-3" style="display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:12px;padding:12px 24px;">
            <div>
                <strong>{{ __('Radite za kupca:') }} {{ auth()->user()?->customerDisplayName() }}</strong>
                @if (auth()->user()?->customerDisplayName() !== auth()->user()?->name)<span class="ml-2 text-sm">{{ __('Kontakt') }}: {{ auth()->user()?->name }}</span>@endif
                <span class="ml-2 text-sm">{{ __('Koriste se cijene odabranog kupca, a narudžbe se spremaju na njegov račun.') }}</span>
                @if ($customerOrderingStaffPreview)
                    <p class="mt-1 text-sm">{{ __('Administratorski pregled') }} · {{ __('Administrator') }}: {{ $customerOrderingStaffPreview['actor']->name }} · {{ __('Komercijalist') }}: {{ $customerOrderingContext['actor']->name }}</p>
                @endif
            </div>
            <div class="flex flex-wrap items-center gap-2" style="display:flex;flex-wrap:wrap;align-items:center;gap:8px;">
                <a href="{{ route('shop.index') }}" class="rounded-lg border border-amber-400 bg-white px-4 py-2 text-sm font-semibold" style="padding:8px 16px;border:1px solid #d97706;border-radius:8px;background:white;font-weight:600;">{{ __('Dodaj artikle') }}</a>
                <form method="POST" action="{{ route('front.impersonation.stop') }}">
                    @csrf
                    <button type="submit" class="rounded-lg border border-amber-400 bg-white px-4 py-2 text-sm font-semibold" style="padding:8px 16px;border:1px solid #d97706;border-radius:8px;background:white;font-weight:600;">{{ $customerOrderingStaffPreview ? __('Vrati se na komercijalista') : __('Vrati se u admin') }}</button>
                </form>
            </div>
        </div>
    </div>
@endif
