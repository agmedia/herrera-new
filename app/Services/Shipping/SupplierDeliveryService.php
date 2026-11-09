<?php

namespace App\Services\Shipping;

use App\Models\Catalog\Product\Product;
use App\Models\Catalog\Product\ProductOptionValue;
use App\Models\Integrations\SupplierDeliverySchedule;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class SupplierDeliveryService
{
    private ?Collection $schedules = null;

    /** Calendar days, including weekends. Same-day supplier orders are included. */
    public function nextOrder(SupplierDeliverySchedule $schedule, CarbonImmutable $date): ?CarbonImmutable
    {
        if (! $schedule->is_active) {
            return null;
        }
        $candidates = [];
        if ($schedule->next_order_date && $schedule->next_order_date->toDateString() >= $date->toDateString()) {
            $candidates[] = $date->setDateFrom($schedule->next_order_date)->startOfDay();
        }
        if ($schedule->weekday !== null) {
            $candidates[] = $date->startOfDay()->addDays(((int) $schedule->weekday - $date->dayOfWeekIso + 7) % 7);
        }
        foreach (range(0, 1) as $offset) {
            $month = $date->startOfMonth()->addMonths($offset);
            foreach ($schedule->monthly_days ?? [] as $day) {
                $candidate = $month->day(min(max(1, (int) $day), $month->daysInMonth));
                if ($candidate->toDateString() >= $date->toDateString()) {
                    $candidates[] = $candidate;
                }
            }
        }

        return collect($candidates)->sortBy(fn ($candidate) => $candidate->getTimestamp())->first();
    }

    /** Public result deliberately contains no stock quantities. */
    public function estimate(Product $product, int $quantity = 1, ?ProductOptionValue $option = null, ?CarbonImmutable $at = null, bool $pickup = false, ?string $countryCode = null): array
    {
        $date = ($at ?? CarbonImmutable::now(config('supplier-delivery.timezone')))->setTimezone(config('supplier-delivery.timezone'))->startOfDay();
        $countryCode = app(CustomerDeliveryDestination::class)->country(auth()->user(), $countryCode);
        $customerDays = $pickup ? 0 : (int) ($countryCode === 'HR'
            ? config('supplier-delivery.customer_days', 2)
            : config('supplier-delivery.international_customer_days', 5));
        $quantity = max(1, $quantity);
        $local = max(0, (int) ($option?->stock_qty ?? $product->stock_qty));
        $supplier = $option || data_get($product->payload, 'clearance.status') === 'active' || data_get($product->payload, 'supplier_catalog_retirement.pending') ? 0 : max(0, (int) $product->supplier_stock_qty);
        if (! $product->is_active || $quantity > $local + $supplier) {
            return $this->unknown('unavailable', __('ui.product.unavailable'));
        }
        $localDate = $date->addDays($customerDays);
        $localEstimate = $this->result('local', $date, $localDate, null, $date, false, $pickup);
        $context = ['country_code' => $countryCode, 'customer_transport_days' => $customerDays];
        if ($local >= $quantity) {
            return $localEstimate + $context;
        }
        $schedule = $this->scheduleFor($product);
        $orderDate = $schedule ? $this->nextOrder($schedule, $date) : null;
        if (! $orderDate) {
            $result = $this->unknown('inquiry', __('Rok isporuke na upit')) + $context;
            if ($local > 0) {
                $result += ['first_delivery_date' => $localEstimate['delivery_date'], 'first_delivery_label' => $localEstimate['label'], 'first_delivery_days' => $localEstimate['days'], 'supplier_delivery_label' => $result['label']];
                $result['partial_shipments'] = true;
            }

            return $result;
        }
        $monthDay = $date->format('m-d');
        $buffer = $monthDay >= '12-20' || $monthDay <= '01-08' ? (int) config('supplier-delivery.holiday_buffer_days', 5) : 0;
        $warehouseDate = $orderDate->addDays($schedule->lead_days + $buffer);
        $deliveryDate = $warehouseDate->addDays($customerDays);
        $result = $this->result($local > 0 ? 'mixed' : 'supplier', $date, $deliveryDate, $orderDate, $warehouseDate, $local > 0, $pickup) + $context;
        $result['supplier'] = $schedule->supplier;
        if ($local > 0) {
            $result['first_delivery_date'] = $localDate->toDateString();
            $result['first_delivery_label'] = $localEstimate['label'];
            $result['first_delivery_days'] = $localEstimate['days'];
            $result['supplier_delivery_label'] = $result['label'];
            $result['label'] = __('Dio pošiljke za :days dana, ostatak :rest', ['days' => $customerDays, 'rest' => $result['label']]);
        }

        return $result;
    }

    public function scheduleFor(Product $product): ?SupplierDeliverySchedule
    {
        $schedules = $this->schedules ??= SupplierDeliverySchedule::query()->get()->keyBy('supplier');
        $candidates = [data_get($product->payload, 'delivery.supplier'), data_get($product->payload, 'supplier_catalog.supplier'), data_get($product->payload, 'supplier_availability.supplier')];
        $manufacturer = $product->manufacturer;
        if ($manufacturer) {
            $candidates[] = $manufacturer->code;
            foreach ($manufacturer->translations as $translation) {
                $candidates[] = $translation->name;
            }
        }
        foreach (array_filter($candidates) as $candidate) {
            $normalized = Str::slug((string) $candidate);
            foreach ($schedules as $schedule) {
                if (in_array($normalized, array_map(fn ($alias) => Str::slug($alias), [$schedule->supplier, $schedule->name, ...($schedule->aliases ?? [])]), true)) {
                    return $schedule;
                }
            }
        }

        return null;
    }

    public function localDeliveryLabel(?string $countryCode = null): string
    {
        $countryCode = app(CustomerDeliveryDestination::class)->country(auth()->user(), $countryCode);
        $days = (int) ($countryCode === 'HR' ? config('supplier-delivery.customer_days', 2) : config('supplier-delivery.international_customer_days', 5));

        return $days === 2 ? __('stock.local_delivery') : __('Dostava do :days dana', ['days' => $days]);
    }

    private function unknown(string $status, string $label): array
    {
        return ['status' => $status, 'label' => $label, 'delivery_date' => null, 'days' => null, 'partial_shipments' => false];
    }

    private function result(string $status, CarbonImmutable $from, CarbonImmutable $delivery, ?CarbonImmutable $order, CarbonImmutable $warehouse, bool $partial, bool $pickup): array
    {
        $days = (int) $from->diffInDays($delivery);

        return [
            'status' => $status, 'days' => $days,
            'label' => $status === 'local' && ! $pickup ? ($days === 2 ? __('stock.local_delivery') : __('Dostava do :days dana', ['days' => $days])) : ($days === 0 ? __('Preuzimanje uz dogovor') : __('oko :days dana (:date)', ['days' => $days, 'date' => $delivery->format('d.m.')])),
            'delivery_date' => $delivery->toDateString(), 'supplier_order_date' => $order?->toDateString(), 'warehouse_date' => $warehouse->toDateString(),
            'partial_shipments' => $partial, 'pickup' => $pickup, 'calculated_at' => $from->toDateString(),
        ];
    }
}
