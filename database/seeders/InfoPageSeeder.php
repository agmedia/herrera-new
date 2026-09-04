<?php

namespace Database\Seeders;

use App\Models\Catalog\Category\Category;
use App\Models\Content\Page\InfoPage;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class InfoPageSeeder extends Seeder
{
    public function run(): void
    {
        $userId = User::query()->value('id');

        $records = [
            [
                'code' => 'shipping-returns',
                'layout' => 'default',
                'show_in_footer' => true,
                'published_at' => now()->subDays(30),
                'sort_order' => 10,
                'category_codes' => ['shipping-returns'],
                'translations' => [
                    'en' => [
                        'title' => 'Shipping & Returns',
                        'slug' => 'shipping-returns',
                        'excerpt' => 'Delivery zones, timing and return eligibility rules.',
                    ],
                    'hr' => [
                        'title' => 'Dostava i povrat',
                        'slug' => 'dostava-i-povrat',
                        'excerpt' => 'Zone dostave, rokovi i uvjeti za povrat proizvoda.',
                    ],
                ],
            ],
            [
                'code' => 'about-us',
                'layout' => 'default',
                'show_in_footer' => true,
                'published_at' => now()->subDays(40),
                'sort_order' => 20,
                'category_codes' => ['about'],
                'translations' => [
                    'en' => [
                        'title' => 'About Us',
                        'slug' => 'about-us',
                        'excerpt' => 'Who we are, how we source products, and what we value.',
                    ],
                    'hr' => [
                        'title' => 'O nama',
                        'slug' => 'o-nama',
                        'excerpt' => 'Tko smo, kako biramo proizvode i koje vrijednosti pratimo.',
                    ],
                ],
            ],
            [
                'code' => 'privacy-policy',
                'layout' => 'legal',
                'show_in_footer' => true,
                'published_at' => now()->subDays(25),
                'sort_order' => 30,
                'category_codes' => [],
                'translations' => [
                    'en' => [
                        'title' => 'Privacy Policy',
                        'slug' => 'privacy-policy',
                        'excerpt' => 'How personal data is collected and processed.',
                    ],
                    'hr' => [
                        'title' => 'Pravila privatnosti',
                        'slug' => 'pravila-privatnosti',
                        'excerpt' => 'Kako prikupljamo i obrađujemo osobne podatke.',
                    ],
                ],
            ],
            [
                'code' => 'size-guide-main',
                'layout' => 'default',
                'show_in_footer' => false,
                'published_at' => now()->subDays(5),
                'sort_order' => 80,
                'category_codes' => [],
                'translations' => [
                    'en' => [
                        'title' => 'Men Size Guide',
                        'slug' => 'size-guide-main',
                        'excerpt' => 'Reference measurements for men tops and jackets.',
                    ],
                    'hr' => [
                        'title' => 'Vodič veličina za muškarce',
                        'slug' => 'vodic-velicina-za-muskarce',
                        'excerpt' => 'Referentne mjere za muške majice i jakne.',
                    ],
                ],
            ],
        ];

        foreach ($records as $record) {
            $page = InfoPage::query()->updateOrCreate(
                ['code' => $record['code']],
                [
                    'layout' => $record['layout'],
                    'is_active' => true,
                    'show_in_footer' => (bool) $record['show_in_footer'],
                    'published_at' => $record['published_at'],
                    'sort_order' => (int) $record['sort_order'],
                    'payload' => null,
                    'created_by' => $userId,
                    'updated_by' => $userId,
                ]
            );

            foreach ($record['translations'] as $locale => $translation) {
                $title = $translation['title'];
                $slug = $translation['slug'] ?? Str::slug($title);

                $page->translations()->updateOrCreate(
                    ['locale' => $locale],
                    [
                        'title' => $title,
                        'slug' => $slug,
                        'excerpt' => $translation['excerpt'] ?? null,
                        'body_html' => '<p>'.$title.'</p><p>'.($translation['excerpt'] ?? '').'</p>',
                        'meta_title' => $title,
                        'meta_description' => $translation['excerpt'] ?? null,
                        'payload' => null,
                    ]
                );
            }

            $categoryIds = Category::query()
                ->where('scope', Category::SCOPE_PAGE)
                ->whereIn('code', $record['category_codes'])
                ->orderBy('id')
                ->pluck('id')
                ->all();

            $syncPayload = [];
            foreach ($categoryIds as $index => $categoryId) {
                $syncPayload[$categoryId] = [
                    'sort_order' => $index,
                    'is_primary' => $index === 0,
                ];
            }

            $page->categories()->sync($syncPayload);
        }
    }
}
