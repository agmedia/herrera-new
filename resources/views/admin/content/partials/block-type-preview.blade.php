@props(['type' => 'custom', 'size' => 'sm'])

@php
    $typeKey = (string) $type;
@endphp

<div class="cb-preview cb-preview--{{ $size }} cb-preview--{{ $typeKey }}" aria-hidden="true">
    @if ($typeKey === 'banner')
        <div class="cb-box cb-hero-media"></div>
        <div class="cb-line cb-w-85"></div>
        <div class="cb-line cb-w-60"></div>
        <div class="cb-pill cb-w-35"></div>
    @elseif ($typeKey === 'products' || $typeKey === 'products_carousel' || $typeKey === 'category_products_carousel')
        <div class="cb-cards3">
            @for ($i = 0; $i < 3; $i++)
                <div class="cb-card-mini">
                    <div class="cb-box cb-mini-media"></div>
                    <div class="cb-line cb-w-70"></div>
                    <div class="cb-line cb-w-45"></div>
                    <div class="cb-pill cb-w-55"></div>
                </div>
            @endfor
        </div>
    @elseif ($typeKey === 'five_star_reviews_carousel')
        <div class="cb-cards3">
            @for ($i = 0; $i < 3; $i++)
                <div class="cb-card-mini">
                    <div class="cb-line cb-w-40"></div>
                    <div class="cb-line cb-w-85"></div>
                    <div class="cb-line cb-w-75"></div>
                    <div class="cb-line cb-w-50"></div>
                </div>
            @endfor
        </div>
    @elseif ($typeKey === 'categories' || $typeKey === 'featured_categories' || $typeKey === 'manufacturers' || $typeKey === 'popular_brands' || $typeKey === 'blogs' || $typeKey === 'blog_grid_3')
        <div class="cb-cards3">
            @for ($i = 0; $i < 3; $i++)
                <div class="cb-card-mini">
                    <div class="cb-box cb-mini-media"></div>
                    <div class="cb-line cb-w-75"></div>
                    <div class="cb-line cb-w-55"></div>
                </div>
            @endfor
        </div>
    @elseif ($typeKey === 'hero_slider')
        <div class="cb-cards3">
            @for ($i = 0; $i < 3; $i++)
                <div class="cb-card-mini">
                    <div class="cb-box cb-mini-media"></div>
                    <div class="cb-line cb-w-75"></div>
                    <div class="cb-line cb-w-50"></div>
                </div>
            @endfor
        </div>
    @elseif ($typeKey === 'cards_2')
        <div class="cb-split">
            @for ($i = 0; $i < 2; $i++)
                <div class="cb-card-mini">
                    <div class="cb-box cb-mini-media"></div>
                    <div class="cb-line cb-w-75"></div>
                    <div class="cb-line cb-w-55"></div>
                </div>
            @endfor
        </div>
    @elseif ($typeKey === 'hero_single' || $typeKey === 'hero_main')
        <div class="cb-box cb-hero-media"></div>
        <div class="cb-line cb-w-80"></div>
        <div class="cb-line cb-w-55"></div>
        <div class="cb-pill cb-w-35"></div>
    @elseif ($typeKey === 'desktop_hero_banner')
        <div class="cb-line cb-w-55"></div>
        <div class="cb-line cb-w-90"></div>
        <div class="cb-line cb-w-70"></div>
        <div class="cb-line cb-w-60"></div>
        <div class="cb-banner">
            <div class="cb-pill cb-w-35"></div>
            <div class="cb-pill cb-w-40"></div>
        </div>
    @elseif ($typeKey === 'full_width_image_slider')
        <div class="cb-box cb-hero-media"></div>
        <div class="cb-banner">
            <div class="cb-pill cb-w-20"></div>
            <div class="cb-pill cb-w-20"></div>
            <div class="cb-pill cb-w-20"></div>
        </div>
    @elseif ($typeKey === 'dual_image_cta')
        <div class="cb-split">
            @for ($i = 0; $i < 2; $i++)
                <div class="cb-card-mini">
                    <div class="cb-box cb-mini-media"></div>
                    <div class="cb-line cb-w-60"></div>
                    <div class="mt-2 flex gap-2">
                        <div class="cb-pill cb-w-30"></div>
                        <div class="cb-pill cb-w-30"></div>
                    </div>
                </div>
            @endfor
        </div>
    @elseif ($typeKey === 'mobile_hero_banner')
        <div class="cb-box cb-hero-media"></div>
        <div class="cb-line cb-w-75"></div>
        <div class="cb-line cb-w-60"></div>
        <div class="cb-pill cb-w-30"></div>
    @elseif ($typeKey === 'hero_highlights_strip')
        <div class="cb-cards3">
            @for ($i = 0; $i < 3; $i++)
                <div class="cb-card-mini">
                    <div class="cb-pill cb-w-20"></div>
                    <div class="cb-line cb-w-70"></div>
                    <div class="cb-line cb-w-55"></div>
                </div>
            @endfor
        </div>
    @elseif ($typeKey === 'split_message')
        <div class="cb-split">
            <div>
                <div class="cb-line cb-w-85"></div>
                <div class="cb-line cb-w-70"></div>
                <div class="cb-line cb-w-55"></div>
                <div class="cb-pill cb-w-45"></div>
            </div>
            <div class="cb-box cb-split-media"></div>
        </div>
    @elseif ($typeKey === 'cards_3')
        <div class="cb-cards3">
            @for ($i = 0; $i < 3; $i++)
                <div class="cb-card-mini">
                    <div class="cb-box cb-mini-media"></div>
                    <div class="cb-line cb-w-75"></div>
                    <div class="cb-line cb-w-50"></div>
                </div>
            @endfor
        </div>
    @elseif ($typeKey === 'rich_text')
        <div class="cb-line cb-w-90"></div>
        <div class="cb-line cb-w-85"></div>
        <div class="cb-line cb-w-80"></div>
        <div class="cb-line cb-w-65"></div>
        <div class="cb-line cb-w-72"></div>
        <div class="cb-line cb-w-58"></div>
    @elseif ($typeKey === 'cta_banner')
        <div class="cb-banner">
            <div>
                <div class="cb-line cb-w-85"></div>
                <div class="cb-line cb-w-60"></div>
            </div>
            <div class="cb-pill cb-w-30"></div>
        </div>
    @elseif ($typeKey === 'featured_drop_panel')
        <div class="cb-banner">
            <div>
                <div class="cb-line cb-w-55"></div>
                <div class="cb-line cb-w-85"></div>
                <div class="cb-line cb-w-65"></div>
            </div>
            <div>
                <div class="cb-pill cb-w-35"></div>
                <div class="cb-pill cb-w-30 mt-2"></div>
            </div>
        </div>
    @elseif ($typeKey === 'dev_polishing')
        <div class="cb-line cb-w-85"></div>
        <div class="cb-line cb-w-60"></div>
        <div class="cb-box cb-hero-media"></div>
        <div class="cb-banner">
            <div class="cb-line cb-w-70"></div>
            <div class="cb-pill cb-w-35"></div>
        </div>
    @else
        <div class="cb-line cb-w-85"></div>
        <div class="cb-line cb-w-70"></div>
        <div class="cb-line cb-w-55"></div>
        <div class="cb-box cb-custom"></div>
    @endif
</div>
