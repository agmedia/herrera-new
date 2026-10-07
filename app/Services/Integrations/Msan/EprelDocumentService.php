<?php

namespace App\Services\Integrations\Msan;

use App\Models\Catalog\Product\Product;
use App\Models\Catalog\Product\ProductEnergyDeclaration;
use Symfony\Component\HttpKernel\Exception\HttpException;

class EprelDocumentService
{
    /** Only an exact, still-linked declaration can expose its public document. */
    public function url(Product $product, ProductEnergyDeclaration $declaration, string $document): ?string
    {
        if (! $this->eligible($product, $declaration, $document)) {
            return null;
        }

        return EprelClient::publicDocumentUrl(
            (string) $declaration->eprel_product_group,
            (string) $declaration->eprel_registration_number,
            $document,
        );
    }

    /** Compatibility for previously shared local URLs; no PDF is fetched or cached. */
    public function redirectUrl(Product $product, ProductEnergyDeclaration $declaration, string $document): string
    {
        return $this->url($product, $declaration, $document)
            ?? throw new HttpException(404, 'EPREL dokument nije dostupan.');
    }

    private function eligible(Product $product, ProductEnergyDeclaration $declaration, string $document): bool
    {
        return $product->exists && (bool) $product->is_active
            && $declaration->exists && (int) $declaration->product_id === (int) $product->getKey()
            && $declaration->source === ProductEnergyDeclaration::SOURCE_EPREL
            && data_get($declaration->payload, 'match') === 'exact'
            && EprelProductIdentity::matches($product, data_get($declaration->payload, 'product_identity'))
            && in_array($document, ['label', 'sheet'], true)
            && array_key_exists((string) $declaration->eprel_product_group, EprelClient::productGroupOptions())
            && EprelClient::isValidRegistrationNumber((string) $declaration->eprel_registration_number);
    }
}
