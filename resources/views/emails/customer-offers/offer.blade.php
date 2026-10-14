<!doctype html>
<html lang="{{ $offer->locale }}"><head><meta charset="utf-8"></head><body style="font-family:Arial,sans-serif;color:#24343b">
<p>{{ __('customer_offers.mail_greeting', ['name' => data_get($offer->customer_snapshot, 'company')]) }}</p>
<h1 style="font-size:22px">{{ $offer->title }}</h1>
<p>{{ __('customer_offers.mail_body', ['number' => $offer->number, 'date' => $offer->valid_until->format('d.m.Y.')]) }}</p>
@if($offer->intro)<p>{{ $offer->intro }}</p>@endif
<p>{{ __('customer_offers.mail_closing') }}<br>{{ config('app.name') }}</p>
</body></html>
