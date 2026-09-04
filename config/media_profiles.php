<?php

use App\Models\Catalog\Category\Category;
use App\Models\Catalog\Manufacturer\Manufacturer;
use App\Models\Catalog\Product\Product;
use App\Models\Content\Blog\BlogPost;
use App\Models\Content\ContentBlock;

return [
    /*
    |--------------------------------------------------------------------------
    | Conversion Presets
    |--------------------------------------------------------------------------
    |
    | Keep conversion keys explicit (thumb_100x100, card_360x240...) so generated
    | files are easy to recognize and predictable.
    |
    */
    'presets' => [
        'thumb_100x100' => [
            'fit' => 'crop',
            'width' => 100,
            'height' => 100,
            'quality' => 86,
            'format' => null, // keep original format
        ],
        'icon_96x96' => [
            'fit' => 'crop',
            'width' => 96,
            'height' => 96,
            'quality' => 86,
            'format' => null,
        ],
        'card_360x240' => [
            'fit' => 'crop',
            'width' => 360,
            'height' => 240,
            'quality' => 86,
            'format' => null,
        ],
        'square_360x360' => [
            'fit' => 'crop',
            'width' => 360,
            'height' => 360,
            'quality' => 86,
            'format' => null,
        ],
        'square_540w' => [
            'fit' => 'max',
            'width' => 540,
            'quality' => 82,
            'format' => null,
        ],
        'square_720w' => [
            'fit' => 'max',
            'width' => 720,
            'quality' => 82,
            'format' => null,
        ],
        'square_1080w' => [
            'fit' => 'max',
            'width' => 1080,
            'quality' => 84,
            'format' => null,
        ],
        'card_480w' => [
            'fit' => 'max',
            'width' => 480,
            'quality' => 84,
            'format' => null,
        ],
        'card_720w' => [
            'fit' => 'max',
            'width' => 720,
            'quality' => 76,
            'format' => null,
        ],
        'card_320w' => [
            'fit' => 'max',
            'width' => 320,
            'quality' => 82,
            'format' => null,
        ],
        'card_192w' => [
            'fit' => 'max',
            'width' => 192,
            'quality' => 76,
            'format' => null,
        ],
        'detail_960x960' => [
            'fit' => 'contain',
            'width' => 960,
            'height' => 960,
            'quality' => 88,
            'format' => null,
        ],
        'hero_1440x480' => [
            'fit' => 'crop',
            'width' => 1440,
            'height' => 480,
            'quality' => 86,
            'format' => null,
        ],
        'hero_1440w' => [
            'fit' => 'max',
            'width' => 1440,
            'quality' => 86,
            'format' => null,
        ],
        'hero_1200w' => [
            'fit' => 'max',
            'width' => 1200,
            'quality' => 80,
            'format' => null,
        ],
        'hero_1360w' => [
            'fit' => 'max',
            'width' => 1360,
            'quality' => 82,
            'format' => null,
        ],
        'hero_1600w' => [
            'fit' => 'max',
            'width' => 1600,
            'quality' => 82,
            'format' => null,
        ],
        'hero_1920w' => [
            'fit' => 'max',
            'width' => 1920,
            'quality' => 84,
            'format' => null,
        ],
        'hero_2560w' => [
            'fit' => 'max',
            'width' => 2560,
            'quality' => 84,
            'format' => null,
        ],
        'hero_960w' => [
            'fit' => 'max',
            'width' => 960,
            'quality' => 66,
            'format' => null,
        ],
        'hero_800w' => [
            'fit' => 'max',
            'width' => 800,
            'quality' => 60,
            'format' => null,
        ],
        'hero_720w' => [
            'fit' => 'max',
            'width' => 720,
            'quality' => 70,
            'format' => null,
        ],
        'hero_540w' => [
            'fit' => 'max',
            'width' => 540,
            'quality' => 66,
            'format' => null,
        ],
        'cover_900x1200' => [
            'fit' => 'crop',
            'width' => 900,
            'height' => 1200,
            'quality' => 70,
            'format' => null,
        ],
        'cover_1200x1600' => [
            'fit' => 'crop',
            'width' => 1200,
            'height' => 1600,
            'quality' => 78,
            'format' => null,
        ],
        'cover_1600x2133' => [
            'fit' => 'crop',
            'width' => 1600,
            'height' => 2133,
            'quality' => 80,
            'format' => null,
        ],
        'cover_680x900' => [
            'fit' => 'crop',
            'width' => 680,
            'height' => 900,
            'quality' => 68,
            'format' => null,
        ],
        'cover_520x700' => [
            'fit' => 'crop',
            'width' => 520,
            'height' => 700,
            'quality' => 64,
            'format' => null,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Model Media Profiles
    |--------------------------------------------------------------------------
    */
    'models' => [
        Product::class => [
            'label' => 'Product',
            'main_collection' => 'product_main',
            'collections' => [
                'product_main' => [
                    'label' => 'Main Image',
                    'single_file' => true,
                    'max_upload_kb' => 8192,
                    'accept_mime_types' => ['image/jpeg', 'image/png', 'image/webp', 'image/avif'],
                    'conversions' => ['thumb_100x100', 'card_360x240', 'card_320w', 'card_480w', 'card_720w', 'detail_960x960'],
                    'preview_conversion' => 'card_360x240',
                ],
                'product_gallery' => [
                    'label' => 'Gallery',
                    'single_file' => false,
                    'max_upload_kb' => 8192,
                    'accept_mime_types' => ['image/jpeg', 'image/png', 'image/webp', 'image/avif'],
                    'conversions' => ['thumb_100x100', 'card_360x240', 'card_320w', 'card_480w', 'card_720w', 'detail_960x960'],
                    'preview_conversion' => 'card_360x240',
                ],
                'product_documents' => [
                    'label' => 'Documents',
                    'single_file' => false,
                    'max_upload_kb' => 8192,
                    'accept_mime_types' => [
                        'application/pdf',
                        'application/msword',
                        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                        'application/vnd.ms-excel',
                        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                        'application/zip',
                    ],
                    'conversions' => [],
                    'preview_conversion' => null,
                    'promote_to_main' => false,
                ],
                'product_energy_label' => [
                    'label' => 'Službena energetska oznaka',
                    'single_file' => true,
                    'max_upload_kb' => 12288,
                    'accept_mime_types' => ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'],
                    'conversions' => [],
                    'preview_conversion' => null,
                    'promote_to_main' => false,
                ],
                'product_information_sheet' => [
                    'label' => 'Informacijski list proizvoda (PIS)',
                    'single_file' => true,
                    'max_upload_kb' => 12288,
                    'accept_mime_types' => ['application/pdf'],
                    'conversions' => [],
                    'preview_conversion' => null,
                    'promote_to_main' => false,
                ],
            ],
        ],
        BlogPost::class => [
            'label' => 'Blog Post',
            'main_collection' => 'blog_cover',
            'collections' => [
                'blog_cover' => [
                    'label' => 'Cover Image',
                    'single_file' => true,
                    'max_upload_kb' => 8192,
                    'accept_mime_types' => ['image/jpeg', 'image/png', 'image/webp', 'image/avif'],
                    'conversions' => ['thumb_100x100', 'card_360x240', 'hero_1440x480', 'cover_1600x2133', 'cover_1200x1600', 'cover_900x1200', 'cover_680x900', 'cover_520x700'],
                    'preview_conversion' => 'card_360x240',
                ],
                'blog_gallery' => [
                    'label' => 'Gallery',
                    'single_file' => false,
                    'max_upload_kb' => 8192,
                    'accept_mime_types' => ['image/jpeg', 'image/png', 'image/webp', 'image/avif'],
                    'conversions' => ['thumb_100x100', 'card_360x240', 'detail_960x960'],
                    'preview_conversion' => 'card_360x240',
                ],
            ],
        ],
        Category::class => [
            'label' => 'Category',
            'collections' => [
                'category_icon' => [
                    'label' => 'Icon Image',
                    'single_file' => true,
                    'max_upload_kb' => 4096,
                    'accept_mime_types' => ['image/jpeg', 'image/png', 'image/webp', 'image/avif', 'image/svg+xml'],
                    'conversions' => ['icon_96x96', 'thumb_100x100', 'card_192w', 'card_320w', 'square_540w'],
                    'preview_conversion' => 'icon_96x96',
                ],
                'category_banner' => [
                    'label' => 'Banner Image',
                    'single_file' => true,
                    'max_upload_kb' => 8192,
                    'accept_mime_types' => ['image/jpeg', 'image/png', 'image/webp', 'image/avif'],
                    'conversions' => ['card_360x240', 'hero_1440x480'],
                    'preview_conversion' => 'card_360x240',
                ],
            ],
        ],
        Manufacturer::class => [
            'label' => 'Manufacturer',
            'collections' => [
                'manufacturer_logo' => [
                    'label' => 'Logo Image',
                    'single_file' => true,
                    'max_upload_kb' => 4096,
                    'accept_mime_types' => ['image/jpeg', 'image/png', 'image/webp', 'image/avif', 'image/svg+xml'],
                    'conversions' => ['icon_96x96', 'thumb_100x100'],
                    'preview_conversion' => 'icon_96x96',
                ],
                'manufacturer_banner' => [
                    'label' => 'Banner Image',
                    'single_file' => true,
                    'max_upload_kb' => 8192,
                    'accept_mime_types' => ['image/jpeg', 'image/png', 'image/webp', 'image/avif'],
                    'conversions' => ['card_360x240', 'hero_1440x480'],
                    'preview_conversion' => 'card_360x240',
                ],
            ],
        ],
        ContentBlock::class => [
            'label' => 'Content Block',
            'collections' => [
                'block_background' => [
                    'label' => 'Block Background',
                    'single_file' => true,
                    'max_upload_kb' => 8192,
                    'accept_mime_types' => ['image/jpeg', 'image/png', 'image/webp', 'image/avif'],
                    'conversions' => ['card_360x240', 'hero_1440x480', 'hero_2560w', 'hero_1920w', 'hero_1600w', 'hero_1440w', 'hero_1360w', 'hero_1200w', 'hero_960w', 'hero_800w', 'hero_720w', 'hero_540w'],
                    'preview_conversion' => 'card_360x240',
                ],
                'block_slides' => [
                    'label' => 'Block Slides',
                    'single_file' => false,
                    'excluded_content_block_types' => ['category_products_carousel'],
                    'only_keep_latest' => 30,
                    'max_upload_kb' => 12288,
                    'accept_mime_types' => ['image/jpeg', 'image/png', 'image/webp', 'image/avif'],
                    'conversions' => ['card_360x240', 'hero_1440x480', 'hero_2560w', 'hero_1920w', 'hero_1600w', 'hero_1440w', 'hero_1360w', 'hero_1200w', 'hero_960w', 'hero_800w', 'hero_720w', 'hero_540w'],
                    'preview_conversion' => 'card_360x240',
                ],
                'block_slides_mobile' => [
                    'label' => 'Mobile Slider Slides',
                    'content_block_types' => ['full_width_image_slider', 'desktopfullwidthimageslider'],
                    'single_file' => false,
                    'only_keep_latest' => 30,
                    'max_upload_kb' => 12288,
                    'accept_mime_types' => ['image/jpeg', 'image/png', 'image/webp', 'image/avif'],
                    'conversions' => ['square_360x360', 'square_540w', 'square_720w', 'square_1080w'],
                    'preview_conversion' => 'square_360x360',
                ],
            ],
        ],
    ],
];
