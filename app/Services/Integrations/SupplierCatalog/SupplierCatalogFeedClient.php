<?php

namespace App\Services\Integrations\SupplierCatalog;

use App\Services\Integrations\Stock\BraytronFeedClient;
use App\Services\Integrations\Stock\StockSyncSettingsService;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use SimpleXMLElement;
use Throwable;

class SupplierCatalogFeedClient
{
    public const SUPPLIERS = ['videx' => 'Videx', 'dpm' => 'DPM', 'braytron' => 'Braytron'];

    public function fetch(string $supplier): array
    {
        abort_unless(isset(self::SUPPLIERS[$supplier]), 404);
        try {
            $settings = app(StockSyncSettingsService::class);
            if (! $settings->configured($supplier)) {
                throw new RuntimeException;
            }
            if ($supplier === 'braytron') {
                $body = app(BraytronFeedClient::class)->body();
            } else {
                $response = Http::connectTimeout(10)->timeout(60)->withOptions([
                    'allow_redirects' => false,
                    'progress' => static function ($total, $downloaded): void {
                        if ($total > 50 * 1024 * 1024 || $downloaded > 50 * 1024 * 1024) {
                            throw new RuntimeException;
                        }
                    },
                ])->get($settings->connection($supplier)['url']);
                if (! $response->successful()) {
                    throw new RuntimeException;
                }
                $body = $response->body();
            }

            return $this->parse($body, $supplier);
        } catch (Throwable) {
            throw new RuntimeException($supplier === 'braytron'
                ? 'Braytron nije dostupan. Dobavljač dopušta jedan dohvat u tri sata; prethodni pregled je sačuvan.'
                : 'Dohvat kataloga nije uspio. Provjerite izvor u postavkama zaliha. Prethodni pregled je sačuvan.');
        }
    }

    public function parse(string $body, string $supplier): array
    {
        if (! isset(self::SUPPLIERS[$supplier]) || strlen($body) > 50 * 1024 * 1024
            || preg_match('/<!\s*ENTITY\b|<!\s*DOCTYPE\b[^>]*\[/i', $body)) {
            throw new RuntimeException('Nevaljan katalog.');
        }
        $previous = libxml_use_internal_errors(true);
        try {
            $xml = simplexml_load_string($body, SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA | LIBXML_COMPACT);
            if (! $xml) {
                throw new RuntimeException('Nevaljan XML.');
            }
            [$root, $path] = match ($supplier) {
                'videx' => ['yml_catalog', './shop/offers/offer'],
                'dpm' => ['offers', './PRODUCT'],
                'braytron' => ['Stoklar', './Stok'],
            };
            $rows = $xml->xpath($path) ?: [];
            if ($xml->getName() !== $root || $rows === []) {
                throw new RuntimeException('Katalog je prazan ili nema očekivanu strukturu.');
            }
            $categories = [];
            foreach ($xml->xpath('./shop/categories/category') ?: [] as $category) {
                $categories[(string) $category['id']] = trim((string) $category);
            }
            $result = [];
            foreach ($rows as $row) {
                $item = $this->normalize($row, $supplier, $categories);
                if ($item['external_id'] === '' || $item['sku'] === '' || $item['name'] === ''
                    || mb_strlen($item['external_id']) > 160 || mb_strlen($item['sku']) > 120) {
                    throw new RuntimeException('Katalog sadrži artikl bez ispravne šifre ili naziva.');
                }
                $key = 'id:'.$item['external_id'];
                if (isset($result[$key]) && $result[$key] !== $item) {
                    throw new RuntimeException('Katalog sadrži proturječne artikle.');
                }
                $result[$key] = $item;
            }

            return array_values($result);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    private function normalize(SimpleXMLElement $row, string $supplier, array $categories): array
    {
        $images = $specifications = [];
        $barcode = $currency = null;
        $categoryKey = 'uncategorized';
        $categoryName = 'Bez kategorije';
        if ($supplier === 'videx') {
            $id = (string) $row['id'];
            $sku = (string) $row->vendorCode;
            $name = (string) $row->name;
            $description = (string) $row->description;
            $price = $row->prices->price[0] ?? null;
            $currency = strtoupper(trim((string) ($price['currencyId'] ?? $price['currency'] ?? ''))) ?: null;
            $quantity = (string) $row->stock_quantity;
            $categoryKey = trim((string) $row->categoryId) ?: 'uncategorized';
            $categoryName = $categories[$categoryKey] ?? $categoryKey;
            foreach ($row->picture as $picture) {
                $images[] = (string) $picture;
            }
            foreach ($row->param as $param) {
                $label = trim((string) $param['name']);
                if (preg_match('/ean|barcode|штрих|bar.?code/i', $label)) {
                    $barcode = trim((string) $param) ?: null;
                }
                if ($label !== '' && trim((string) $param) !== '') {
                    $specifications[] = ['label' => mb_substr($label, 0, 255), 'value' => mb_substr(trim((string) $param), 0, 255)];
                }
            }
        } elseif ($supplier === 'dpm') {
            $id = $sku = (string) $row['code'];
            $name = (string) $row->name_hr;
            $description = (string) $row->desc_hr;
            $price = (string) $row->price_EUR;
            $currency = 'EUR';
            $barcode = trim((string) $row->ean) ?: null;
            // Keep parity with the existing supplier-stock integration.
            $quantity = trim((string) $row->BOX_QTY) ?: '0';
            foreach ($row->xpath('./photo/main|./photo/i') ?: [] as $photo) {
                $images[] = (string) $photo['url'];
            }
        } else {
            $id = $sku = (string) $row->ProductCode;
            $name = (string) $row->ProductName;
            $description = (string) $row->Description;
            $price = (string) $row->NetPrice;
            $quantity = (string) $row->Quantity;
            $currency = strtoupper(trim((string) $row->Currency)) ?: null;
            for ($i = 1; $i <= 10; $i++) {
                $images[] = (string) $row->{'Image'.$i};
            }
        }
        $rawPrice = str_replace(',', '.', trim((string) $price));
        $price = preg_match('/^\d+(?:\.\d{1,4})?$/D', $rawPrice) && (float) $rawPrice <= 99999999 ? $rawPrice : null;
        $rawQuantity = str_replace(',', '.', trim($quantity));
        if (! preg_match('/^\d+(?:\.\d+)?$/D', $rawQuantity) || (float) $rawQuantity > 2147483647) {
            throw new RuntimeException('Nevaljana zaliha.');
        }

        return [
            'external_id' => trim($id), 'sku' => trim($sku), 'name' => mb_substr(trim($name), 0, 255),
            'barcode' => $barcode && mb_strlen($barcode) <= 80 ? $barcode : null,
            'description' => $description, 'source_price' => $price, 'currency' => $currency,
            'quantity' => (int) ((float) $rawQuantity), 'category_key' => mb_substr($categoryKey, 0, 160),
            'category_name' => mb_substr($categoryName, 0, 255), 'specifications' => $specifications,
            'images' => array_slice(array_values(array_unique(array_filter(array_map('trim', $images), static fn ($url) => filter_var($url, FILTER_VALIDATE_URL)
                && in_array(strtolower(parse_url($url, PHP_URL_SCHEME) ?? ''), ['http', 'https'], true)
                && ! parse_url($url, PHP_URL_USER) && ! parse_url($url, PHP_URL_PASS)))), 0, 15),
        ];
    }
}
