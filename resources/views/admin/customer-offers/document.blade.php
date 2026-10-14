<!doctype html>
<html lang="{{ $offer->locale }}">
<head><meta charset="utf-8"><title>{{ $offer->number }} — {{ $offer->title }}</title>
<style>
@page { size: A4 portrait; margin: 26px 30px; }
* { box-sizing: border-box; }
body { margin:0; font-family:"DejaVu Sans",sans-serif; color:#26373f; font-size:10px; }
.page { page-break-after:always; }
.page:last-child { page-break-after:auto; }
.header { border-top:7px solid #008aa0; padding:15px 0 11px; }
.brand { font-size:23px; font-weight:bold; letter-spacing:2px; color:#00869b; }
.label { color:#00869b; font-size:9px; text-transform:uppercase; letter-spacing:1px; }
h1 { font-size:19px; line-height:1.25; margin:5px 0 5px; }
.meta { color:#63747d; line-height:1.6; }
table { width:100%; border-collapse:collapse; table-layout:fixed; }
.header td { vertical-align:top; }
.buyer { text-align:right; font-size:10px; line-height:1.55; }
.intro { margin:7px 0 12px; font-size:10px; line-height:1.6; }
.products td { width:50%; vertical-align:top; padding:0 7px 12px 0; }
.products td+td { padding-left:7px; padding-right:0; }
.card { border:1px solid #dbe5e8; padding:11px; height:290px; }
.photo { text-align:center; height:135px; margin-bottom:8px; background:#fff; }
.photo img { max-width:240px; max-height:135px; }
.photo-empty { color:#82939b; line-height:135px; font-size:10px; }
.name { font-size:11px; font-weight:bold; line-height:1.45; margin:0 0 4px; }
.sku { color:#6b7c83; font-size:8px; margin-bottom:5px; }
.description { font-size:9px; line-height:1.55; margin:5px 0; }
.option { color:#00869b; font-size:9px; }
.item-price { border-top:1px solid #e3eaed; margin-top:8px; padding-top:7px; line-height:1.6; }
.price { color:#00869b; font-size:15px; font-weight:bold; }
.summary { border-top:2px solid #008aa0; padding-top:9px; margin-top:3px; }
.summary td { vertical-align:top; }
.totals { text-align:right; font-size:10px; line-height:1.8; }
.total { font-size:15px; color:#00869b; font-weight:bold; }
.terms { font-size:8px; line-height:1.6; color:#63747d; padding-right:15px; }
.footer { border-top:1px solid #dbe5e8; padding-top:6px; margin-top:9px; color:#71848c; font-size:8px; }
@if($preview)
body { background:#edf3f5; padding:25px; }
.page { background:white; width:734px; min-height:1000px; margin:0 auto 24px; padding:26px 30px; box-shadow:0 2px 15px #cbd5da; }
@media print { body { background:white; padding:0; } .page { width:auto; min-height:0; margin:0; padding:0; box-shadow:none; } }
@endif
</style></head><body>
@foreach($pages as $pageIndex => $items)
<section class="page" data-offer-page="{{ $pageIndex + 1 }}">
<div class="header"><table><tr><td style="width:59%">
<div class="brand">{{ $branding['store_name'] ?? config('app.name') }}</div>
<div class="label">{{ __('customer_offers.offer') }} {{ $offer->number }}</div>
<h1>{{ $offer->title }}</h1>
<div class="meta">{{ __('customer_offers.issued') }} {{ $offer->priced_at->format('d.m.Y.') }} · {{ __('customer_offers.valid_until') }} {{ $offer->valid_until->format('d.m.Y.') }}</div>
</td><td class="buyer"><strong>{{ data_get($offer->customer_snapshot, 'company') }}</strong><br>
{{ data_get($offer->customer_snapshot, 'name') }}<br>{{ data_get($offer->customer_snapshot, 'address') }}<br>
{{ data_get($offer->customer_snapshot, 'postal_code') }} {{ data_get($offer->customer_snapshot, 'city') }}<br>
@if(data_get($offer->customer_snapshot, 'oib'))OIB: {{ data_get($offer->customer_snapshot, 'oib') }}<br>@endif
{{ data_get($offer->customer_snapshot, 'email') }}</td></tr></table></div>
@if($offer->intro && $pageIndex === 0)<p class="intro">{{ $offer->intro }}</p>@endif
<table class="products">
@foreach($items->chunk(2) as $pair)<tr>
@foreach($pair as $item)<td><div class="card">
<div class="photo">@if($images[$item->id] ?? null)<img src="{{ $images[$item->id] }}" alt="{{ $item->name }}">@else<span class="photo-empty">{{ __('customer_offers.no_image') }}</span>@endif</div>
<div class="name">{{ \Illuminate\Support\Str::limit($item->name, 100, '…') }}</div>
<div class="sku">{{ __('customer_offers.code') }}: {{ $item->sku ?: $item->code }}</div>
@if($item->option_label)<div class="option">{{ $item->option_label }}</div>@endif
<p class="description">{{ \Illuminate\Support\Str::limit($item->description, 220, '…') }}</p>
<div class="item-price"><span class="price">{{ number_format((float) $item->unit_net, 4, ',', '.') }} €</span> / {{ $item->unit }} {{ __('customer_offers.excluding_vat') }}<br>
{{ __('customer_offers.quantity') }}: {{ $item->quantity }} {{ $item->unit }} · {{ __('customer_offers.line_total') }}: <strong>{{ number_format((float) $item->line_net, 2, ',', '.') }} €</strong></div>
</div></td>@endforeach
@if($pair->count() === 1)<td></td>@endif
</tr>@endforeach</table>
@if($loop->last)<div class="summary"><table><tr><td style="width:60%" class="terms">
@if($offer->notes){{ $offer->notes }}<br>@endif
@if(data_get($offer->customer_snapshot, 'payment_terms_days') !== null){{ __('customer_offers.payment_terms', ['days' => data_get($offer->customer_snapshot, 'payment_terms_days')]) }}<br>@endif
{{ __('customer_offers.terms') }}
</td><td class="totals">{{ __('customer_offers.net_total') }}: {{ number_format((float) $offer->net_total, 2, ',', '.') }} €<br>
{{ __('customer_offers.vat') }}: {{ number_format((float) $offer->tax_total, 2, ',', '.') }} €<br>
<span class="total">{{ __('customer_offers.total') }}: {{ number_format((float) $offer->gross_total, 2, ',', '.') }} €</span></td></tr></table></div>@endif
@if($preview)<div class="footer">{{ $offer->number }} · {{ __('customer_offers.page') }} {{ $pageIndex + 1 }} / {{ $pages->count() }}@if($offer->status === 'draft') · {{ __('customer_offers.status.draft') }}@endif</div>@endif
</section>
@endforeach
</body></html>
