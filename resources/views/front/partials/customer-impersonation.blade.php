@if (session()->has(\App\Services\User\CustomerImpersonationService::SESSION_KEY))
    <div role="status" class="border-b border-amber-300 bg-amber-50 text-amber-950" style="position:relative;z-index:50;background:#fffbeb;color:#78350f;border-bottom:1px solid #fcd34d;">
        <div class="container mx-auto flex flex-wrap items-center justify-between gap-3 px-4 py-3" style="display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:12px;padding:12px 24px;">
            <div>
                <strong>{{ __('Prijavljeni ste kao kupac:') }} {{ auth()->user()?->name }}</strong>
                <span class="ml-2 text-sm">{{ __('Pregledavate njegov račun, cijene i narudžbe.') }}</span>
            </div>
            <form method="POST" action="{{ route('front.impersonation.stop') }}">
                @csrf
                <button type="submit" class="rounded-lg border border-amber-400 bg-white px-4 py-2 text-sm font-semibold" style="padding:8px 16px;border:1px solid #d97706;border-radius:8px;background:white;font-weight:600;">{{ __('Vrati se u admin') }}</button>
            </form>
        </div>
    </div>
@endif
