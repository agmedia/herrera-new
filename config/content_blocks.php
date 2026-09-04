<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Content Block Types
    |--------------------------------------------------------------------------
    |
    | These are reusable block render-types. Frontend rendering can map each
    | type to its own blade partial.
    |
    */
    'types' => [
        'banner' => 'Banner (Static)',
        'products' => 'Products Block (Selected)',
        'categories' => 'Categories Block (Selected)',
        'manufacturers' => 'Manufacturers Block (Selected)',
        'blogs' => 'Blogs Block (Selected)',
        'hero_single' => 'Hero Single Banner',
        'hero_slider' => 'Hero Slider (multi banner)',
        'products_carousel' => 'Products Carousel',
        'category_products_carousel' => 'Category Products Carousel',
        'featured_categories' => 'Featured Categories',
        'popular_brands' => 'Popular Brands',
        'blogs_carousel' => 'Blogs Carousel',
        'five_star_reviews_carousel' => '5 Star Reviews Carousel',
        'blog_grid_3' => 'Blog Grid (3)',
        'cards_2' => 'Cards (2 Col)',
        'hero_main' => 'Hero Main',
        'split_message' => 'Split Message (2 Col)',
        'cards_3' => 'Cards (3 Col)',
        'rich_text' => 'Rich Text',
        'cta_banner' => 'CTA Banner',
        'featured_drop_panel' => 'Featured Drop Panel',
        'desktop_hero_banner' => 'Desktop Hero Banner',
        'full_width_image_slider' => 'Desktop Full Width Image Slider',
        'dual_image_cta' => 'Desktop Dual Image CTA',
        'material_craftsmanship' => 'Material & Craftsmanship Section',
        'category_editorial_tiles' => 'Category Editorial Tiles',
        'instagram_curated_grid' => 'Instagram Curated Grid',
        'mobile_hero_banner' => 'Mobile Hero Banner',
        'hero_highlights_strip' => 'Hero Highlights Strip',
        'dev_polishing' => 'Dev Polishing (RTE)',
        'custom' => 'Custom',
    ],

    /*
    |--------------------------------------------------------------------------
    | Placement Registry
    |--------------------------------------------------------------------------
    |
    | Global placements can be used directly.
    | Targeted placements can be combined with:
    | - target_type (category, blog_post, product, page, ...)
    | - target_ref  (slug or id)
    |
    */
    'placements' => [
        'home.hero' => 'Home Hero',
        'home.hero_benefits' => 'Home Hero Benefits',
        'home.before_products' => 'Home Before Products',
        'home.categories' => 'Home Categories',
        'home.after_products' => 'Home After Products',
        'home.bottom' => 'Home Bottom',
        'category.top' => 'Category Top',
        'category.bottom' => 'Category Bottom',
        'manufacturer.top' => 'Manufacturer Top',
        'manufacturer.bottom' => 'Manufacturer Bottom',
        'product.top' => 'Product Top',
        'product.bottom' => 'Product Bottom',
        'blog.top' => 'Blog Top',
        'blog.bottom' => 'Blog Bottom',
        'page.top' => 'Page Top',
        'page.bottom' => 'Page Bottom',
    ],

    /*
    |--------------------------------------------------------------------------
    | Cache
    |--------------------------------------------------------------------------
    */
    'cache' => [
        'ttl_seconds' => 3600,
        'version_key' => 'content_blocks:version',
    ],

    /*
    |--------------------------------------------------------------------------
    | Safe Front Route Resolution
    |--------------------------------------------------------------------------
    |
    | Route names allowed in block payloads for CTA generation.
    | If empty, all existing named routes are allowed.
    |
    */
    'route_whitelist' => [
        'home',
        'shop.*',
        'categories.*',
        'products.*',
        'manufacturers.*',
        'blog.*',
        'pages.*',
        'contact.*',
        'cart.*',
        'checkout.*',
        'account.*',
        'login',
        'register',
        'dashboard',
        'admin.*',
        'profile',
    ],

    /*
    |--------------------------------------------------------------------------
    | Per-Block View Overrides
    |--------------------------------------------------------------------------
    |
    | Block payload may set:
    | - view_override: "front.content-blocks.instances.some-file"
    |
    | You can also create code-based overrides that are auto-detected:
    | - front.content-blocks.instances.{block_code}
    |
    */
    'view_overrides' => [
        'prefix' => 'front.content-blocks.instances.',
    ],
];
