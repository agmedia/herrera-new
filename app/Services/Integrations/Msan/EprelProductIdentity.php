<?php

namespace App\Services\Integrations\Msan;

use App\Models\Catalog\Product\Product;
use JsonException;

class EprelProductIdentity
{
    public static function fingerprint(Product $product): string
    {
        // Identitet čitamo iz već učitanog artikla, bez dodatnih upita i tajni.
        $identity = [
            'code' => trim((string) $product->code),
            'sku' => trim((string) $product->sku),
            'barcode' => trim((string) $product->barcode),
            'manufacturer_id' => (int) $product->manufacturer_id,
            'opencart_model' => data_get($product->payload, 'opencart.model'),
            'opencart_ean' => data_get($product->payload, 'opencart.ean'),
        ];

        return 'v1:'.hash('sha256', json_encode(
            $identity,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        ));
    }

    public static function matches(Product $product, mixed $fingerprint): bool
    {
        if (! is_string($fingerprint) || preg_match('/^v1:[a-f0-9]{64}$/D', $fingerprint) !== 1) {
            return false;
        }

        try {
            return hash_equals($fingerprint, self::fingerprint($product));
        } catch (JsonException) {
            return false;
        }
    }
}
