@php
    $sourceUrl = trim((string) $product->image_url);
    $imageVersion = $sourceUrl !== ''
        ? substr(hash('sha256', $sourceUrl."\0".(string) $product->catalog_checksum), 0, 12)
        : null;
@endphp

<div class="flex h-16 w-16 shrink-0 items-center justify-center overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm" aria-hidden="{{ $sourceUrl === '' ? 'true' : 'false' }}">
    @if ($sourceUrl !== '')
        <img
            src="{{ route('admin.integrations.msan.products.image', ['product' => $product, 'v' => $imageVersion]) }}"
            alt="{{ __('Slika artikla :name', ['name' => $product->name]) }}"
            width="64"
            height="64"
            loading="lazy"
            decoding="async"
            fetchpriority="low"
            referrerpolicy="same-origin"
            draggable="false"
            class="h-full w-full object-contain p-1"
        >
    @else
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="h-7 w-7 text-slate-300" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" d="m2.25 15.75 5.159-5.159a2.25 2.25 0 0 1 3.182 0l5.159 5.159m-1.5-1.5 1.409-1.409a2.25 2.25 0 0 1 3.182 0l2.909 2.909m-18 3.75h16.5a1.5 1.5 0 0 0 1.5-1.5V6a1.5 1.5 0 0 0-1.5-1.5H3.75A1.5 1.5 0 0 0 2.25 6v12a1.5 1.5 0 0 0 1.5 1.5Zm10.5-11.25h.008v.008h-.008V8.25Z" />
        </svg>
    @endif
</div>
