@php
    $productDocuments = $product->getMedia('product_documents')
        ->filter(fn ($document) => \App\Support\Media\MediaUrl::hasUsableOriginal($document))
        ->sortBy('order_column');
    $supplierDocuments = collect(is_array(data_get($product->payload, 'supplier_documents')) ? data_get($product->payload, 'supplier_documents') : [])
        ->filter(fn ($document) => is_array($document) && ($document['source'] ?? '') === 'vayox')
        ->map(function ($document) {
            $document['url'] = \App\Services\Integrations\Media\VayoxDocumentClient::safeDocumentUrl((string) ($document['url'] ?? ''));
            return $document;
        })->filter(fn ($document) => $document['url'] !== null)->unique('url');
@endphp

@if ($productDocuments->isNotEmpty() || $supplierDocuments->isNotEmpty())
    <details id="product-documents" class="product-information-panel" data-product-documents open>
        <summary class="product-information-summary">
            <span class="product-information-summary-icon"><x-fa-icon name="file-lines" /></span>
            <span class="product-information-summary-label">{{ app()->getLocale() === 'hr' ? 'Tehnički dokumenti' : 'Technical documents' }}</span>
            <span class="product-information-summary-toggle" aria-hidden="true">
                <x-fa-icon name="plus" class="product-information-icon-plus" />
                <x-fa-icon name="minus" class="product-information-icon-minus" />
            </span>
        </summary>
        <ul class="product-information-content space-y-3">
            @foreach ($productDocuments as $document)
                <li>
                    <a class="inline-flex items-center gap-3 text-sm font-semibold text-cyan-700 underline underline-offset-4" href="{{ $document->getUrl() }}" target="_blank" rel="noopener noreferrer" data-product-document="{{ $document->id }}">
                        <x-fa-icon name="file-arrow-down" class="h-4 w-4 shrink-0" />
                        <span>{{ $document->name ?: $document->file_name }} <span class="text-xs font-normal text-slate-500">({{ strtoupper(pathinfo($document->file_name, PATHINFO_EXTENSION)) }}, {{ $document->human_readable_size }})</span></span>
                    </a>
                    @if (trim((string) $document->getCustomProperty('caption', '')) !== '')
                        <p class="mt-1 text-xs text-slate-600">{{ $document->getCustomProperty('caption') }}</p>
                    @endif
                </li>
            @endforeach
            @foreach ($supplierDocuments as $document)
                <li>
                    <a class="inline-flex items-center gap-3 text-sm font-semibold text-cyan-700 underline underline-offset-4" href="{{ $document['url'] }}" target="_blank" rel="noopener noreferrer" data-product-external-document="{{ $document['source'] }}">
                        <x-fa-icon name="file-pdf" class="h-4 w-4 shrink-0" />
                        <span>{{ trim((string) ($document['name'] ?? '')) ?: (app()->getLocale() === 'hr' ? 'Dokument dobavljača' : 'Supplier document') }} <span class="text-xs font-normal text-slate-500">(PDF)</span></span>
                    </a>
                    @if (trim((string) ($document['caption'] ?? '')) !== '')
                        <p class="mt-1 text-xs text-slate-600">{{ $document['caption'] }}</p>
                    @endif
                </li>
            @endforeach
        </ul>
    </details>
@endif
