@php
    $reviewCount = (int) ($count ?? 0);
    $reviewAverage = max(0, min(5, (float) ($average ?? 0)));
    $reviewHref = trim((string) ($href ?? ''));
    $reviewCompact = (string) ($size ?? 'default') === 'compact';
    $reviewClass = trim((string) ($class ?? ''));
    $reviewCountLabel = trans_choice('ui.product.reviews_count', $reviewCount, ['count' => $reviewCount]);
    $reviewAriaLabel = __('ui.product.reviews_summary_aria', [
        'count' => $reviewCount,
        'rating' => number_format($reviewAverage, 1, '.', ''),
    ]);
    $reviewTag = $reviewHref !== '' ? 'a' : 'div';
@endphp

@if ($reviewCount > 0)
    @once
        @push('head')
            <link rel="stylesheet" href="{{ asset('front-theme/styles/product-review-summary.css') }}?v={{ filemtime(public_path('front-theme/styles/product-review-summary.css')) }}">
        @endpush
    @endonce

    <{{ $reviewTag }}
        @if ($reviewHref !== '') href="{{ $reviewHref }}" @endif
        class="product-review-summary {{ $reviewCompact ? 'is-compact' : '' }} {{ $reviewHref !== '' ? 'is-link' : '' }} {{ $reviewClass }}"
        aria-label="{{ $reviewAriaLabel }}"
    >
        <span class="product-review-stars" aria-hidden="true">
            @for ($star = 1; $star <= 5; $star++)
                @php
                    $starFill = $reviewAverage - ($star - 1);
                    $starClass = $starFill >= .75 ? 'is-full' : ($starFill >= .25 ? 'is-half' : 'is-empty');
                @endphp
                <span class="product-review-star {{ $starClass }}">★</span>
            @endfor
        </span>
        <span class="product-review-count">{{ $reviewCountLabel }}</span>
    </{{ $reviewTag }}>
@endif
