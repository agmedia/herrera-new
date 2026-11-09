<?php

namespace App\Services\Shipping;

use App\Models\Catalog\Product\Product;
use App\Models\Sales\Order\OrderItem;
use Carbon\CarbonImmutable;

/** Fulfilment quantities are the customer's reserved quantities, never live stock. */
class OrderFulfilmentPresentation
{
    public function snapshot(array $inventory, array $delivery, int $quantity, ?string $unitOfMeasure = null): array
    {
        $local = max(0, (int) ($inventory['local_quantity'] ?? 0)) + max(0, (int) ($inventory['option_quantity'] ?? 0));
        $supplier = max(0, (int) ($inventory['supplier_quantity'] ?? 0));
        if ($quantity < 1 || $local + $supplier !== $quantity) {
            return [$this->row('order', $quantity, $delivery['label'] ?? null, $delivery['delivery_date'] ?? null, $unitOfMeasure)];
        }
        $rows = [];
        if ($local > 0) {
            $date = $supplier > 0 ? ($delivery['first_delivery_date'] ?? null) : ($delivery['delivery_date'] ?? null);
            $label = $supplier > 0 ? ($delivery['first_delivery_label'] ?? $this->dateLabel($date)) : ($delivery['label'] ?? null);
            $rows[] = $this->row('local', $local, $label, $date, $unitOfMeasure);
        }
        if ($supplier > 0) {
            $date = $delivery['delivery_date'] ?? null;
            $label = $delivery['supplier_delivery_label'] ?? ($local > 0 ? $this->dateLabel($date) : ($delivery['label'] ?? null));
            $rows[] = $this->row('supplier', $supplier, $label, $date, $unitOfMeasure);
        }

        return $rows;
    }

    public function forItem(OrderItem $item): array
    {
        $delivery = (array) data_get($item->payload, 'delivery', []);
        $unitOfMeasure = data_get($item->payload, 'unit_of_measure');
        $unitOfMeasure = is_string($unitOfMeasure) ? $unitOfMeasure : null;
        $saved = $delivery['fulfilments'] ?? null;
        if (is_array($saved) && count($saved) >= 1 && count($saved) <= 2) {
            $valid = array_all($saved, fn ($row) => is_array($row) && in_array($row['source'] ?? null, ['local', 'supplier', 'order'], true)
                && is_int($row['quantity'] ?? null) && $row['quantity'] > 0 && is_string($row['label'] ?? null));
            if ($valid && array_sum(array_column($saved, 'quantity')) === (int) $item->quantity) {
                return array_values(array_map(fn ($row) => $row + ['unit' => $this->unitLabel($unitOfMeasure)], $saved));
            }
        }

        // Historical orders are reconstructed only from their immutable saved data.
        return $this->snapshot((array) data_get($item->payload, 'inventory', []), $delivery, (int) $item->quantity, $unitOfMeasure);
    }

    private function row(string $source, int $quantity, mixed $label, mixed $date, ?string $unitOfMeasure): array
    {
        return ['source' => $source, 'quantity' => $quantity,
            'unit' => $this->unitLabel($unitOfMeasure),
            'label' => is_string($label) && $label !== '' ? $label : __('Rok isporuke na upit'),
            'delivery_date' => is_string($date) ? $date : null];
    }

    private function unitLabel(?string $unitOfMeasure): string
    {
        return Product::unitOptions()[$unitOfMeasure ?? ''] ?? '';
    }

    private function dateLabel(mixed $date): string
    {
        if (! is_string($date) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date)) {
            return __('Rok isporuke na upit');
        }

        try {
            return __('Procijenjena dostava :date', ['date' => CarbonImmutable::parse($date)->format('d.m.Y.')]);
        } catch (\Throwable) {
            return __('Rok isporuke na upit');
        }
    }
}
