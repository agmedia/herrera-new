<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Sales\Order\Order;
use App\Services\Integrations\Gls\GlsShipmentService;
use Illuminate\Http\RedirectResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class OrderGlsController extends Controller
{
    public function send(Order $order, GlsShipmentService $gls): RedirectResponse
    {
        try {
            $shipment = $gls->send($order, auth()->id());

            return redirect()->back()->with('notify', [
                'type' => 'success',
                'message' => 'GLS naljepnica je generirana. Broj paketa: '.($shipment['parcel_number'] ?? '-'),
            ]);
        } catch (\Throwable $exception) {
            return redirect()->back()->with('notify', [
                'type' => 'error',
                'message' => $exception->getMessage(),
            ]);
        }
    }

    public function label(Order $order, GlsShipmentService $gls): StreamedResponse|RedirectResponse
    {
        try {
            return $gls->downloadLabel($order);
        } catch (\Throwable $exception) {
            return redirect()->back()->with('notify', [
                'type' => 'error',
                'message' => $exception->getMessage(),
            ]);
        }
    }
}
