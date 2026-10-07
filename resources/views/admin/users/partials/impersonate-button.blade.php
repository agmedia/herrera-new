@if (app(\App\Services\User\CustomerImpersonationService::class)->canStart(auth()->user(), $customer))
    <form method="POST" action="{{ route('admin.users.impersonate', ['user' => $customer->id]) }}">
        @csrf
        <button type="submit" class="rounded-lg border border-cyan-300 bg-cyan-50 px-3 py-2 text-xs font-semibold text-cyan-800 hover:bg-cyan-100" title="{{ __('Otvori web trgovinu prijavljen kao ovaj kupac') }}">{{ __('Prijavi se kao kupac') }}</button>
    </form>
@endif
