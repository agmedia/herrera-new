<?php

namespace App\Services\Integrations\Gls;

use App\Models\Sales\Order\Order;
use App\Support\GlsShipping;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class GlsShipmentService
{
    public function __construct(
        private readonly GlsApiService $api
    ) {}

    public function connectorEnabled(): bool
    {
        return $this->api->enabledInSettings();
    }

    /**
     * @return array<string, mixed>
     */
    public function send(Order $order, ?int $userId = null): array
    {
        $order->refresh();

        if (! GlsShipping::isGlsShippingMethod((string) $order->shipping_method_code)) {
            throw new InvalidArgumentException('Ova narudžba nema GLS dostavu.');
        }

        $request = [];

        try {
            $this->api->assertEnabled();
            $request = $this->buildPrintLabelsRequest($order);
            $response = $this->api->printLabels($request);
            $this->assertNoApiErrors($response['PrintLabelsErrorList'] ?? []);

            $shipmentInfo = $this->extractShipmentInfo($response);
            $labelContents = $this->decodeBinaryPayload($response['Labels'] ?? []);
            $labelPath = $this->storeLabel($order, $labelContents, (string) ($shipmentInfo['parcel_number'] ?? ''));

            $state = $this->extractState($order);
            $state['last_request'] = Arr::except($request, ['Password']);
            $state['last_response'] = Arr::except($response, ['Labels']);
            $state['last_error'] = null;
            $state['shipment'] = array_merge($shipmentInfo, [
                'label_path' => $labelPath,
                'sent_at' => now()->toIso8601String(),
                'sent_by' => $userId,
                'mode' => $this->api->getSettings()['gls_api_mode'] ?? 'test',
            ]);

            $this->persistState($order, $state, 'gls_shipment_sent', 'GLS pošiljka je kreirana iz administracije.', $userId);

            return $state['shipment'];
        } catch (\Throwable $exception) {
            $state = $this->extractState($order);
            $state['last_request'] = Arr::except($request, ['Password']);
            $state['last_error'] = [
                'message' => $exception->getMessage(),
                'at' => now()->toIso8601String(),
                'by' => $userId,
            ];

            $this->persistState($order, $state, 'gls_shipment_failed', 'Kreiranje GLS pošiljke nije uspjelo.', $userId);

            throw $exception;
        }
    }

    public function downloadLabel(Order $order): StreamedResponse
    {
        $state = $this->extractState($order);
        $shipment = is_array($state['shipment'] ?? null) ? $state['shipment'] : [];
        $path = trim((string) ($shipment['label_path'] ?? ''));

        if ($path === '' || ! Storage::disk('local')->exists($path)) {
            throw new RuntimeException('GLS PDF naljepnica nije pronađena za ovu narudžbu.');
        }

        $parcelNumber = trim((string) ($shipment['parcel_number'] ?? ''));
        $filename = $parcelNumber !== ''
            ? 'gls-label-'.$parcelNumber.'.pdf'
            : 'gls-label-order-'.$order->id.'.pdf';

        return Storage::disk('local')->download($path, $filename, ['Content-Type' => 'application/pdf']);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildPrintLabelsRequest(Order $order): array
    {
        $settings = $this->api->getSettings();
        $clientNumber = trim((string) ($settings['gls_api_client_number'] ?? ''));
        $pickupCountryCode = strtoupper(trim((string) ($settings['gls_api_pickup_country_code'] ?? 'HR')));
        [$pickupStreet, $pickupHouseNumber] = $this->splitStreetAndHouseNumber((string) ($settings['gls_api_pickup_street'] ?? ''));
        [$deliveryStreet, $deliveryHouseNumber] = $this->splitStreetAndHouseNumber((string) $order->shipping_address_line_1);

        $recipientName = trim((string) $order->shipping_first_name.' '.(string) $order->shipping_last_name);
        if ($recipientName === '') {
            $recipientName = trim((string) ($order->customer_name ?: $order->shipping_company ?: $order->billing_company ?: 'Kupac'));
        }

        $parcel = [
            'ClientNumber' => $clientNumber,
            'ClientReference' => (string) $order->order_number,
            'Content' => $this->buildParcelContent($order),
            'Count' => 1,
            'DeliveryAddress' => [
                'Name' => $recipientName,
                'ContactName' => trim((string) ($order->shipping_company ?: $recipientName)),
                'ContactPhone' => (string) ($order->customer_phone ?? ''),
                'ContactEmail' => (string) ($order->customer_email ?? ''),
                'Street' => $deliveryStreet,
                'HouseNumber' => $deliveryHouseNumber,
                'HouseNumberInfo' => (string) ($order->shipping_address_line_2 ?? ''),
                'City' => (string) ($order->shipping_city ?? ''),
                'ZipCode' => (string) ($order->shipping_postal_code ?? ''),
                'CountryIsoCode' => strtoupper((string) ($order->shipping_country_code ?: 'HR')),
            ],
            'PickupAddress' => [
                'Name' => (string) ($settings['gls_api_pickup_name'] ?? ''),
                'ContactName' => (string) (($settings['gls_api_pickup_contact_name'] ?? '') ?: ($settings['gls_api_pickup_name'] ?? '')),
                'ContactPhone' => (string) ($settings['gls_api_pickup_contact_phone'] ?? ''),
                'ContactEmail' => (string) ($settings['gls_api_pickup_contact_email'] ?? ''),
                'Street' => $pickupStreet,
                'HouseNumber' => $pickupHouseNumber,
                'HouseNumberInfo' => (string) ($settings['gls_api_pickup_address_line_2'] ?? ''),
                'City' => (string) ($settings['gls_api_pickup_city'] ?? ''),
                'ZipCode' => (string) ($settings['gls_api_pickup_postal_code'] ?? ''),
                'CountryIsoCode' => $pickupCountryCode !== '' ? $pickupCountryCode : 'HR',
            ],
            'ServiceList' => $this->buildServiceList($order),
        ];

        if ($this->isCashOnDelivery($order)) {
            $parcel['CODAmount'] = round((float) $order->grand_total, 2);
        }

        return [
            'ParcelList' => [$parcel],
            'PrintPosition' => max(1, min(4, (int) ($settings['gls_api_print_position'] ?? 1))),
            'ShowPrintDialog' => (bool) ($settings['gls_api_show_print_dialog'] ?? false),
            'TypeOfPrinter' => trim((string) ($settings['gls_api_printer_type'] ?? 'A4_2x2')),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function buildServiceList(Order $order): array
    {
        if (! GlsShipping::isGlsDpmShippingMethod((string) $order->shipping_method_code)) {
            return [];
        }

        $payload = is_array($order->payload) ? $order->payload : [];
        $point = data_get($payload, 'shipping.gls_dpm');
        $point = is_array($point) ? $point : [];
        $locationId = trim((string) ($point['id'] ?? ''));

        if ($locationId === '') {
            throw new InvalidArgumentException('Za GLS paketomat/ParcelShop potrebno je odabrati lokaciju u checkoutu.');
        }

        return [[
            'Code' => 'PSD',
            'PSDParameter' => ['StringValue' => $locationId],
        ]];
    }

    private function assertNoApiErrors(mixed $errors): void
    {
        if (! is_array($errors) || $errors === []) {
            return;
        }

        $messages = collect($errors)
            ->map(function ($error): string {
                if (! is_array($error)) {
                    return trim((string) $error);
                }

                return collect($error)
                    ->filter(fn ($value): bool => is_scalar($value) && trim((string) $value) !== '')
                    ->map(fn ($value): string => trim((string) $value))
                    ->unique()
                    ->implode(' / ');
            })
            ->filter()
            ->values()
            ->all();

        throw new RuntimeException($messages !== [] ? implode(' | ', $messages) : 'GLS je vratio grešku bez detalja.');
    }

    /**
     * @param  array<string, mixed>  $response
     * @return array<string, mixed>
     */
    private function extractShipmentInfo(array $response): array
    {
        $info = collect($response['PrintLabelsInfoList'] ?? [])->first(fn ($row): bool => is_array($row));
        if (! is_array($info)) {
            throw new RuntimeException('GLS odgovor ne sadrži PrintLabelsInfoList.');
        }

        $parcelId = (int) ($info['ParcelId'] ?? 0);
        $parcelNumber = trim((string) ($info['ParcelNumber'] ?? ''));
        if ($parcelId <= 0 || $parcelNumber === '') {
            throw new RuntimeException('GLS odgovor ne sadrži ParcelId ili ParcelNumber.');
        }

        return [
            'client_reference' => (string) ($info['ClientReference'] ?? ''),
            'parcel_id' => $parcelId,
            'parcel_number' => $parcelNumber,
        ];
    }

    private function decodeBinaryPayload(mixed $payload): string
    {
        if (! is_array($payload) || $payload === []) {
            throw new RuntimeException('GLS nije vratio PDF naljepnicu.');
        }

        $binary = '';
        foreach ($payload as $byte) {
            if (! is_numeric($byte)) {
                throw new RuntimeException('GLS je vratio neispravan binarni sadržaj naljepnice.');
            }
            $binary .= chr((int) $byte);
        }

        return $binary;
    }

    private function storeLabel(Order $order, string $contents, string $parcelNumber): string
    {
        $suffix = trim($parcelNumber) !== '' ? $parcelNumber : 'order-'.$order->id;
        $path = sprintf(
            'gls/labels/%s/%s/order-%d-%s.pdf',
            now()->format('Y'),
            now()->format('m'),
            $order->id,
            preg_replace('/[^A-Za-z0-9\\-]+/', '-', $suffix) ?: 'label'
        );
        Storage::disk('local')->put($path, $contents);

        return $path;
    }

    /**
     * @return array{0:string,1:string}
     */
    private function splitStreetAndHouseNumber(string $value): array
    {
        $normalized = trim($value);
        if ($normalized === '') {
            return ['', ''];
        }

        if (preg_match('/^(.*?)[\\s,]+(\\d+[A-Za-z0-9\\-\\/]*)$/u', $normalized, $matches) === 1) {
            return [trim((string) ($matches[1] ?? '')), trim((string) ($matches[2] ?? ''))];
        }

        return [$normalized, ''];
    }

    private function buildParcelContent(Order $order): string
    {
        return mb_substr('Narudžba '.$order->order_number.' / '.max(1, (int) $order->item_qty).' artikala', 0, 100);
    }

    private function isCashOnDelivery(Order $order): bool
    {
        return in_array(strtolower(trim((string) $order->payment_method_code)), ['cod', 'cash_on_delivery'], true);
    }

    /**
     * @return array<string, mixed>
     */
    private function extractState(Order $order): array
    {
        $payload = is_array($order->payload) ? $order->payload : [];
        $state = $payload['gls'] ?? [];

        return is_array($state) ? $state : [];
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function persistState(Order $order, array $state, string $event, string $message, ?int $userId): void
    {
        $payload = is_array($order->payload) ? $order->payload : [];
        $payload['gls'] = $state;

        $order->payload = $payload;
        $order->updated_by = $userId;
        $order->save();

        $order->history()->create([
            'from_status_id' => $order->status_id,
            'to_status_id' => $order->status_id,
            'changed_by' => $userId,
            'comment' => $message,
            'payload' => [
                'origin' => 'gls',
                'event' => $event,
                'parcel_number' => data_get($state, 'shipment.parcel_number'),
                'has_error' => is_array($state['last_error'] ?? null),
            ],
        ]);

        $activity = activity('orders')
            ->performedOn($order)
            ->event($event)
            ->withProperties([
                'parcel_number' => data_get($state, 'shipment.parcel_number'),
                'parcel_id' => data_get($state, 'shipment.parcel_id'),
                'has_error' => is_array($state['last_error'] ?? null),
            ]);
        if (auth()->user()) {
            $activity->causedBy(auth()->user());
        }
        $activity->log($message);
    }
}
