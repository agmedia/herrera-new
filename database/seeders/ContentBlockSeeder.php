<?php

namespace Database\Seeders;

use App\Models\Content\ContentBlock;
use App\Models\User;
use Illuminate\Database\Seeder;

class ContentBlockSeeder extends Seeder
{
    public function run(): void
    {
        $userId = User::query()->value('id');

        $records = [
            [
                'code' => 'home-hero-slider',
                'name' => 'Home Hero Slider',
                'type' => 'hero_slider',
                'is_active' => true,
                'payload' => [
                    'slides' => [
                        ['title' => 'Season essentials', 'subtitle' => 'Soft launch promo and featured picks.', 'url' => '/shop', 'label' => 'Shop now'],
                        ['title' => 'Bestsellers this week', 'subtitle' => 'Popular items from active orders.', 'url' => '/shop', 'label' => 'See bestsellers'],
                        ['title' => 'Fast local delivery', 'subtitle' => 'Simple checkout and clear shipping windows.', 'url' => '/pages/shipping-returns', 'label' => 'Delivery info'],
                    ],
                ],
                'translations' => [
                    'en' => [
                        'title' => 'Home Hero Slider',
                        'subtitle' => 'Main sliding banners for homepage.',
                        'body_html' => null,
                        'cta_label' => null,
                        'cta_url' => null,
                    ],
                    'hr' => [
                        'title' => 'Početni slider bannera',
                        'subtitle' => 'Glavni klizni banneri na početnoj.',
                        'body_html' => null,
                        'cta_label' => null,
                        'cta_url' => null,
                    ],
                ],
            ],
            [
                'code' => 'home-products-carousel',
                'name' => 'Home Products Carousel',
                'type' => 'products_carousel',
                'is_active' => true,
                'payload' => [
                    'source' => 'query',
                    'limit' => 10,
                    'sort' => 'newest',
                    'category_ids' => [],
                    'manufacturer_ids' => [],
                ],
                'translations' => [
                    'en' => [
                        'title' => 'Featured products',
                        'subtitle' => 'Latest active products, ready for a carousel slot.',
                        'body_html' => null,
                        'cta_label' => 'View all products',
                        'cta_url' => '/shop',
                    ],
                    'hr' => [
                        'title' => 'Izdvojeni proizvodi',
                        'subtitle' => 'Najnoviji aktivni proizvodi spremni za carousel slot.',
                        'body_html' => null,
                        'cta_label' => 'Pogledaj sve proizvode',
                        'cta_url' => '/shop',
                    ],
                ],
            ],
            [
                'code' => 'home-blog-grid-3',
                'name' => 'Home Blog Grid 3',
                'type' => 'blog_grid_3',
                'is_active' => true,
                'payload' => [
                    'source' => 'query',
                    'limit' => 3,
                    'sort' => 'newest',
                    'category_ids' => [],
                ],
                'translations' => [
                    'en' => [
                        'title' => 'Latest guides and blog posts',
                        'subtitle' => 'Three blog cards with short excerpt.',
                        'body_html' => null,
                        'cta_label' => 'Read blog',
                        'cta_url' => '/blog',
                    ],
                    'hr' => [
                        'title' => 'Najnoviji vodiči i blog objave',
                        'subtitle' => 'Tri blog kartice s kratkim sažetkom.',
                        'body_html' => null,
                        'cta_label' => 'Čitaj blog',
                        'cta_url' => '/blog',
                    ],
                ],
            ],
            [
                'code' => 'home-hero-main',
                'name' => 'Home Hero Main',
                'type' => 'hero_main',
                'is_active' => true,
                'payload' => [
                    'theme' => 'soft-slate',
                    'layout' => 'hero_center',
                ],
                'translations' => [
                    'en' => [
                        'title' => 'Weekly picks for your pantry',
                        'subtitle' => 'Fresh arrivals, curated bundles and practical essentials for fast shopping.',
                        'body_html' => '<p>Build your cart in minutes with highlighted products and clear shipping info.</p>',
                        'cta_label' => 'Shop now',
                        'cta_url' => '/shop',
                    ],
                    'hr' => [
                        'title' => 'Tjedni izbor za vašu smočnicu',
                        'subtitle' => 'Novi artikli, kurirani paketi i praktične osnove za brzu kupnju.',
                        'body_html' => '<p>Složite košaricu u par minuta uz istaknute proizvode i jasne informacije o dostavi.</p>',
                        'cta_label' => 'Kreni u kupnju',
                        'cta_url' => '/shop',
                    ],
                ],
            ],
            [
                'code' => 'home-split-message',
                'name' => 'Home Split Message',
                'type' => 'split_message',
                'is_active' => true,
                'payload' => [
                    'theme' => 'soft-emerald',
                    'icon_left' => 'truck',
                    'icon_right' => 'shield',
                ],
                'translations' => [
                    'en' => [
                        'title' => 'Reliable delivery and clear returns',
                        'subtitle' => 'Simple checkout, transparent shipping windows and no hidden terms.',
                        'body_html' => '<p>Support team can resolve most order issues within one working day.</p>',
                        'cta_label' => 'Delivery info',
                        'cta_url' => '/pages/shipping-returns',
                    ],
                    'hr' => [
                        'title' => 'Pouzdana dostava i jasni povrati',
                        'subtitle' => 'Jednostavna naplata, transparentni rokovi dostave i bez skrivenih uvjeta.',
                        'body_html' => '<p>Podrška rješava većinu upita vezanih uz narudžbe unutar jednog radnog dana.</p>',
                        'cta_label' => 'Informacije o dostavi',
                        'cta_url' => '/pages/dostava-i-povrat',
                    ],
                ],
            ],
            [
                'code' => 'home-cards-3',
                'name' => 'Home Cards Three',
                'type' => 'cards_3',
                'is_active' => true,
                'payload' => [
                    'theme' => 'soft-neutral',
                    'cards' => [
                        ['icon' => 'sparkles', 'title' => 'Fresh picks'],
                        ['icon' => 'scale', 'title' => 'Fair prices'],
                        ['icon' => 'clock', 'title' => 'Fast dispatch'],
                    ],
                ],
                'translations' => [
                    'en' => [
                        'title' => 'Why customers stay',
                        'subtitle' => 'Practical benefits focused on speed, value and clarity.',
                        'body_html' => '<p>Show short highlights with CTA links to key category pages.</p>',
                        'cta_label' => 'View catalog',
                        'cta_url' => '/categories',
                    ],
                    'hr' => [
                        'title' => 'Zašto nam se kupci vraćaju',
                        'subtitle' => 'Korisne prednosti fokusirane na brzinu, vrijednost i jasnoću.',
                        'body_html' => '<p>Prikažite kratke naglaske s CTA linkovima prema ključnim kategorijama.</p>',
                        'cta_label' => 'Pogledaj katalog',
                        'cta_url' => '/kategorije',
                    ],
                ],
            ],
            [
                'code' => 'category-rich-intro',
                'name' => 'Category Intro Rich Text',
                'type' => 'rich_text',
                'is_active' => true,
                'payload' => [
                    'theme' => 'paper',
                    'align' => 'left',
                ],
                'translations' => [
                    'en' => [
                        'title' => 'Category intro section',
                        'subtitle' => 'Use this slot to explain filtering, product quality and shipping notes.',
                        'body_html' => '<p>This content appears above category product listing. Keep it concise and useful for SEO.</p>',
                        'cta_label' => 'Browse products',
                        'cta_url' => '/categories',
                    ],
                    'hr' => [
                        'title' => 'Uvodni tekst kategorije',
                        'subtitle' => 'Koristite ovaj slot za objašnjenje filtera, kvalitete i napomena o dostavi.',
                        'body_html' => '<p>Ovaj sadržaj se prikazuje iznad liste proizvoda kategorije. Neka bude kratak i SEO koristan.</p>',
                        'cta_label' => 'Pregledaj proizvode',
                        'cta_url' => '/kategorije',
                    ],
                ],
            ],
            [
                'code' => 'blog-cta-banner',
                'name' => 'Blog CTA Banner',
                'type' => 'cta_banner',
                'is_active' => true,
                'payload' => [
                    'theme' => 'soft-cyan',
                    'priority' => 'marketing',
                ],
                'translations' => [
                    'en' => [
                        'title' => 'Need help choosing products?',
                        'subtitle' => 'Ask support for quick suggestions based on your basket and budget.',
                        'body_html' => '<p>Useful for content pages where readers can move directly into catalog flow.</p>',
                        'cta_label' => 'Contact support',
                        'cta_url' => '/contact',
                    ],
                    'hr' => [
                        'title' => 'Trebate pomoć pri izboru proizvoda?',
                        'subtitle' => 'Javite se podršci za brze preporuke prema košarici i budžetu.',
                        'body_html' => '<p>Korisno na sadržajnim stranicama gdje čitatelj prelazi u katalog.</p>',
                        'cta_label' => 'Kontaktiraj podršku',
                        'cta_url' => '/kontakt',
                    ],
                ],
            ],
            [
                'code' => 'dev-polishing-note',
                'name' => 'Dev Polishing Note',
                'type' => 'dev_polishing',
                'is_active' => true,
                'payload' => [
                    'theme' => 'dev',
                    'rte' => 'ace',
                ],
                'translations' => [
                    'en' => [
                        'title' => 'Developer polishing block',
                        'subtitle' => 'Use Ace editor for hand-crafted snippets and deployment notes.',
                        'body_html' => '<p><strong>Tip:</strong> Keep HTML snippets small and move repeated structures into templates.</p>',
                        'cta_label' => 'Open docs',
                        'cta_url' => '/docs',
                    ],
                    'hr' => [
                        'title' => 'Developer polishing blok',
                        'subtitle' => 'Koristite Ace editor za ručne HTML isječke i napomene za deployment.',
                        'body_html' => '<p><strong>Savjet:</strong> HTML neka bude kratak, a ponovljive strukture prebacite u template.</p>',
                        'cta_label' => 'Otvori dokumentaciju',
                        'cta_url' => '/docs',
                    ],
                ],
            ],
        ];

        foreach ($records as $record) {
            $block = ContentBlock::query()->updateOrCreate(
                ['code' => $record['code']],
                [
                    'name' => $record['name'],
                    'type' => $record['type'],
                    'is_active' => (bool) $record['is_active'],
                    'payload' => $record['payload'] ?? null,
                    'created_by' => $userId,
                    'updated_by' => $userId,
                ]
            );

            foreach ($record['translations'] as $locale => $translation) {
                $block->translations()->updateOrCreate(
                    ['locale' => $locale],
                    [
                        'title' => $translation['title'] ?? null,
                        'subtitle' => $translation['subtitle'] ?? null,
                        'body_html' => $translation['body_html'] ?? null,
                        'cta_label' => $translation['cta_label'] ?? null,
                        'cta_url' => $translation['cta_url'] ?? null,
                        'payload' => $translation['payload'] ?? null,
                    ]
                );
            }
        }
    }
}
