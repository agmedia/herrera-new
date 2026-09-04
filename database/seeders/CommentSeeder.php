<?php

namespace Database\Seeders;

use App\Models\Catalog\Product\Product;
use App\Models\Content\Blog\BlogPost;
use App\Models\Content\Page\InfoPage;
use App\Models\Content\Support\Comment;
use App\Models\Content\Support\Faq;
use App\Models\User;
use Illuminate\Database\Seeder;

class CommentSeeder extends Seeder
{
    public function run(): void
    {
        $user = User::query()->first();
        $reviewer = User::query()->skip(1)->first() ?: $user;

        $product = Product::query()->first();
        $blogPost = BlogPost::query()->first();
        $infoPage = InfoPage::query()->first();
        $faq = Faq::query()->first();

        $records = [
            [
                'commentable' => $product,
                'locale' => 'hr',
                'author_name' => 'Ivan Kupac',
                'author_email' => 'ivan@example.test',
                'body' => 'Odličan proizvod, kupujem ponovno.',
                'rating' => 5,
                'status' => Comment::STATUS_APPROVED,
            ],
            [
                'commentable' => $blogPost,
                'locale' => 'en',
                'author_name' => 'Emma Reader',
                'author_email' => 'emma@example.test',
                'body' => 'Helpful guide, can you add one for brown rice too?',
                'rating' => 4,
                'status' => Comment::STATUS_PENDING,
            ],
            [
                'commentable' => $faq,
                'locale' => 'hr',
                'author_name' => 'Maja',
                'author_email' => 'maja@example.test',
                'body' => 'Hvala, odgovor je jasan i koristan.',
                'rating' => null,
                'status' => Comment::STATUS_APPROVED,
            ],
            [
                'commentable' => $infoPage,
                'locale' => 'en',
                'author_name' => 'Spam Bot',
                'author_email' => 'bot@example.test',
                'body' => 'Visit our unrelated website now!',
                'rating' => null,
                'status' => Comment::STATUS_SPAM,
            ],
        ];

        foreach ($records as $record) {
            if (!$record['commentable']) {
                continue;
            }

            Comment::query()->updateOrCreate(
                [
                    'commentable_type' => $record['commentable']::class,
                    'commentable_id' => $record['commentable']->getKey(),
                    'author_email' => $record['author_email'],
                ],
                [
                    'user_id' => $user?->id,
                    'author_name' => $record['author_name'],
                    'author_email' => $record['author_email'],
                    'locale' => $record['locale'],
                    'body' => $record['body'],
                    'rating' => $record['rating'],
                    'status' => $record['status'],
                    'is_featured' => false,
                    'reviewed_by' => $record['status'] === Comment::STATUS_PENDING ? null : $reviewer?->id,
                    'reviewed_at' => $record['status'] === Comment::STATUS_PENDING ? null : now()->subHours(2),
                    'payload' => null,
                ]
            );
        }
    }
}

