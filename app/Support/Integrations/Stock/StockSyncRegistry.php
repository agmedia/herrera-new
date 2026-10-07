<?php

namespace App\Support\Integrations\Stock;

use InvalidArgumentException;

class StockSyncRegistry
{
    public static function all(): array
    {
        $jobs = [
            'videx' => ['Videx / Allegro', '2 2/8 * * *', true, 'sku', 'updateQuantityAllegro', 10237960],
            'brock' => ['Brock', '35 8/12 * * *', false, 'ean', null, 9547447],
            'enovalite' => ['Enovalite', '27 1/8 * * *', true, 'sku', 'updateQuantityEnovalite', 9008368],
            'vayox' => ['Vayox', '0 4/8 * * *', true, 'ean', 'updateQuantityVayox', 9007531],
            'dpm' => ['DPM', '0 0/8 * * *', false, 'ean', 'updateQuantityDpm', 9003865],
            'master' => ['Master', '23 2/8 * * *', true, 'sku', 'updateQuantityMaster', 9003841],
            'braytron' => ['Braytron', '24 5/8 * * *', true, 'sku', 'updateQuantity', 7874194],
            'eracuni' => ['e-Računi / Herrera', '11 1/2 * * *', true, 'model', 'updateQuantityEracuni', 7139596],
        ];
        $result = [];
        foreach ($jobs as $key => [$label, $schedule, $enabled, $match, $action, $easycronId]) {
            $result[$key] = [
                'label' => $label, 'schedule' => $schedule, 'enabled' => $enabled,
                'match' => $match, 'target' => $key === 'eracuni' ? 'stock_qty' : 'supplier_stock_qty',
                'legacy_path' => $action ? '/admin/index.php?route=extension/module/agm_api/'.$action : '/updateqty_brock.php',
                'easycron_id' => $easycronId,
            ];
        }

        return $result;
    }

    public static function get(string $supplier): array
    {
        return self::all()[$supplier] ?? throw new InvalidArgumentException('Nepoznata integracija zaliha.');
    }
}
