<?php

namespace Database\Seeders;

use App\Models\Content\Support\Faq;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class FaqSeeder extends Seeder
{
    public function run(): void
    {
        $userId = User::query()->value('id');

        $records = [
            [
                'code' => 'faq-shipping-time',
                'group_code' => 'shipping',
                'is_featured' => true,
                'sort_order' => 10,
                'translations' => [
                    'en' => [
                        'question' => 'How long does shipping take?',
                        'slug' => 'how-long-does-shipping-take',
                        'answer_html' => '<p>Orders are dispatched in 24 hours on business days. Delivery is typically 1-3 business days in Croatia.</p>',
                    ],
                    'hr' => [
                        'question' => 'Koliko traje dostava?',
                        'slug' => 'koliko-traje-dostava',
                        'answer_html' => '<p>Narudžbe šaljemo unutar 24h radnim danom. Dostava je obično 1-3 radna dana unutar Hrvatske.</p>',
                    ],
                ],
            ],
            [
                'code' => 'faq-payment-methods',
                'group_code' => 'payments',
                'is_featured' => false,
                'sort_order' => 20,
                'translations' => [
                    'en' => [
                        'question' => 'Which payment methods are available?',
                        'slug' => 'which-payment-methods-are-available',
                        'answer_html' => '<p>Card payment, bank transfer, and cash on delivery are available depending on shipping zone.</p>',
                    ],
                    'hr' => [
                        'question' => 'Koje metode plaćanja su dostupne?',
                        'slug' => 'koje-metode-placanja-su-dostupne',
                        'answer_html' => '<p>Dostupno je kartično plaćanje, bankovni prijenos i pouzeće, ovisno o zoni dostave.</p>',
                    ],
                ],
            ],
            [
                'code' => 'faq-returns',
                'group_code' => 'returns',
                'is_featured' => true,
                'sort_order' => 30,
                'translations' => [
                    'en' => [
                        'question' => 'Can I return an opened product?',
                        'slug' => 'can-i-return-an-opened-product',
                        'answer_html' => '<p>Opened products cannot be returned unless there is a quality defect. Contact support with order details.</p>',
                    ],
                    'hr' => [
                        'question' => 'Mogu li vratiti otvoreni proizvod?',
                        'slug' => 'mogu-li-vratiti-otvoreni-proizvod',
                        'answer_html' => '<p>Otvoreni proizvodi se ne vraćaju osim u slučaju greške kvalitete. Kontaktirajte podršku s podacima narudžbe.</p>',
                    ],
                ],
            ],
        ];

        foreach ($records as $record) {
            $faq = Faq::query()->updateOrCreate(
                ['code' => $record['code']],
                [
                    'group_code' => $record['group_code'],
                    'is_active' => true,
                    'is_featured' => (bool) $record['is_featured'],
                    'sort_order' => (int) $record['sort_order'],
                    'payload' => null,
                    'created_by' => $userId,
                    'updated_by' => $userId,
                ]
            );

            foreach ($record['translations'] as $locale => $translation) {
                $question = $translation['question'];
                $slug = $translation['slug'] ?? Str::slug($question);

                $faq->translations()->updateOrCreate(
                    ['locale' => $locale],
                    [
                        'question' => $question,
                        'slug' => $slug,
                        'answer_html' => $translation['answer_html'] ?? null,
                        'meta_title' => $question,
                        'meta_description' => strip_tags((string) ($translation['answer_html'] ?? '')),
                        'payload' => null,
                    ]
                );
            }
        }
    }
}

