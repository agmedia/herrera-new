@if (app(\App\Services\User\CustomerImpersonationService::class)->canStart(auth()->user(), $customer))
    @php
        $canOrderForCustomer = app(\App\Services\Pricing\B2BAccessService::class)->canViewPrices($customer);
    @endphp
    <form method="POST" action="{{ route('admin.users.impersonate', ['user' => $customer->id]) }}">
        @csrf
        <button type="submit" class="rounded-lg border border-cyan-300 bg-cyan-50 px-3 py-2 text-xs font-semibold text-cyan-800 hover:bg-cyan-100" title="{{ $canOrderForCustomer ? __('Otvori trgovinu s cijenama ovog kupca i izradi narudžbu na njegov račun') : __('Otvori web trgovinu prijavljen kao ovaj kupac') }}">{{ $canOrderForCustomer ? __('Naruči za kupca') : __('Prijavi se kao kupac') }}</button>
    </form>
@elseif (app(\App\Services\User\StaffImpersonationService::class)->canStart(auth()->user(), $customer))
    <form method="POST" action="{{ route('admin.users.staff-impersonation.start', ['user' => $customer->id]) }}">
        @csrf
        <button type="submit" class="rounded-lg border border-cyan-300 bg-cyan-50 px-3 py-2 text-xs font-semibold text-cyan-800 hover:bg-cyan-100" title="{{ __('Otvori administraciju s ovlastima ovog voditelja narudžbi') }}">{{ __('Prijavi se kao') }}</button>
    </form>
@endif
