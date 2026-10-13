@php
    $blogCardTranslation = $blogCardPost->translations->firstWhere('locale', $blogCardLocale)
        ?? $blogCardPost->translations->firstWhere('locale', $blogCardFallbackLocale);
    $blogCardTitle = (string) ($blogCardTranslation?->title ?: $blogCardPost->code);
    $blogCardImage = \App\Support\Media\HerreraBlogImage::first($blogCardPost);
@endphp
<article class="herrera-blog-card">
    <a href="{{ route('blog.show', ['slug' => $blogCardTranslation?->slug ?: $blogCardPost->id]) }}">
        <div class="herrera-blog-card-image aspect-[3/2]">
            @if ($blogCardImage)
                <img src="{{ $blogCardImage }}" alt="{{ $blogCardTitle }}" width="1536" height="1024" loading="lazy" decoding="async">
            @else
                <span>{{ __('ui.product.no_image') }}</span>
            @endif
        </div>
        <div class="herrera-blog-card-copy">
            @if ($blogCardInList ?? false)
                <h2>{{ $blogCardTitle }}</h2>
            @else
                <h3>{{ $blogCardTitle }}</h3>
            @endif
            <p>{{ $blogCardTranslation?->excerpt ?: __('ui.blog.excerpt_fallback') }}</p>
        </div>
    </a>
</article>
