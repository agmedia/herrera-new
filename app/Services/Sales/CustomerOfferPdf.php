<?php

namespace App\Services\Sales;

use App\Models\Sales\Offer\CustomerOffer;
use App\Services\Front\StoreSettingsService;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Facades\App;

class CustomerOfferPdf
{
    public function __construct(private readonly CustomerOfferImage $images) {}

    public function html(CustomerOffer $offer, bool $preview = false): string
    {
        $offer->loadMissing('items');
        $this->images->beginRender();
        $locale = app()->getLocale();
        App::setLocale($offer->locale);
        try {
            return view('admin.customer-offers.document', [
                'offer' => $offer, 'preview' => $preview,
                'pages' => $offer->items->chunk(4),
                'images' => $offer->items->mapWithKeys(fn ($item) => [$item->id => $this->images->dataUri($item->image_url)]),
                'branding' => app(StoreSettingsService::class)->branding(),
            ])->render();
        } finally {
            App::setLocale($locale);
        }
    }

    public function render(CustomerOffer $offer): string
    {
        $options = new Options;
        $options->set('isRemoteEnabled', false);
        $options->set('isPhpEnabled', false);
        $options->set('isJavascriptEnabled', false);
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('chroot', [storage_path('app'), public_path()]);
        $pdf = new Dompdf($options);
        $pdf->setPaper('A4', 'portrait');
        $pdf->loadHtml($this->html($offer), 'UTF-8');
        $pdf->render();
        // Number actual PDF pages outside the flowing document. Long terms must
        // never push a footer onto an extra page or display a false page count.
        $canvas = $pdf->getCanvas();
        $footer = $offer->number.' · '.__('customer_offers.page', [], $offer->locale).' {PAGE_NUM} / {PAGE_COUNT}'
            .($offer->status === 'draft' ? ' · '.__('customer_offers.status.draft', [], $offer->locale) : '');
        $canvas->page_text(24, $canvas->get_height() - 16, $footer, $pdf->getFontMetrics()->getFont('DejaVu Sans', 'normal'), 7, [0.44, 0.52, 0.55]);

        return $pdf->output();
    }
}
