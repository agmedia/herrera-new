<?php

namespace Database\Seeders;

use App\Models\Catalog\Category\Category;
use App\Models\Content\Blog\BlogPost;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class BlogPostSeeder extends Seeder
{
    public function run(): void
    {
        $userId = User::query()->value('id');

        $records = [
            [
                'code' => 'rice-cooking-guide',
                'is_featured' => true,
                'published_at' => now()->subDays(12),
                'sort_order' => 10,
                'category_codes' => ['guides', 'recipes'],
                'translations' => [
                    'en' => [
                        'title' => 'Rice Cooking Guide: Perfect Texture Every Time',
                        'slug' => 'rice-cooking-guide-perfect-texture',
                        'excerpt' => 'Simple method for fluffy jasmine rice, with hydration and timing ratios.',
                    ],
                    'hr' => [
                        'title' => 'Vodič za kuhanje riže: savršena tekstura svaki put',
                        'slug' => 'vodic-za-kuhanje-rize-savrsena-tekstura',
                        'excerpt' => 'Jednostavna metoda za rastresitu jasmine rižu s preciznim omjerima.',
                    ],
                ],
            ],
            [
                'code' => 'green-tea-brewing-basics',
                'is_featured' => false,
                'published_at' => now()->subDays(7),
                'sort_order' => 20,
                'category_codes' => ['guides'],
                'translations' => [
                    'en' => [
                        'title' => 'Green Tea Brewing Basics',
                        'slug' => 'green-tea-brewing-basics',
                        'excerpt' => 'Water temperature and steep-time rules for daily brewing.',
                    ],
                    'hr' => [
                        'title' => 'Osnove pripreme zelenog čaja',
                        'slug' => 'osnove-pripreme-zelenog-caja',
                        'excerpt' => 'Temperatura vode i vrijeme namakanja za svakodnevnu pripremu.',
                    ],
                ],
            ],
            [
                'code' => 'quick-wok-noodles',
                'is_featured' => true,
                'published_at' => now()->subDays(3),
                'sort_order' => 30,
                'category_codes' => ['recipes'],
                'translations' => [
                    'en' => [
                        'title' => 'Quick Wok Noodles in 15 Minutes',
                        'slug' => 'quick-wok-noodles-15-minutes',
                        'excerpt' => 'Fast weekday noodle recipe with pantry sauces and crisp vegetables.',
                    ],
                    'hr' => [
                        'title' => 'Brzi wok rezanci za 15 minuta',
                        'slug' => 'brzi-wok-rezanci-15-minuta',
                        'excerpt' => 'Brzi recept za radni dan s umacima iz smočnice i hrskavim povrćem.',
                    ],
                ],
            ],
        ];

        foreach ($records as $record) {
            $post = BlogPost::query()->updateOrCreate(
                ['code' => $record['code']],
                [
                    'is_active' => true,
                    'is_featured' => (bool) $record['is_featured'],
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

                $post->translations()->updateOrCreate(
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
                ->where('scope', Category::SCOPE_BLOG)
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

            $post->categories()->sync($syncPayload);
        }
    }
}
