<?php

namespace Database\Seeders;

use App\Models\Content\ContentBlock;
use App\Models\Content\ContentBlockSlot;
use App\Models\User;
use Illuminate\Database\Seeder;
use RuntimeException;

class ContentBlockSlotSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            ContentBlockSeeder::class,
        ]);

        $userId = User::query()->value('id');
        $missingBlockCodes = [];

        $records = [
            [
                'block_code' => 'home-hero-slider',
                'placement' => 'home.hero',
                'target_type' => null,
                'target_ref' => null,
                'sort_order' => 0,
                'is_active' => true,
            ],
            [
                'block_code' => 'home-hero-main',
                'placement' => 'home.hero',
                'target_type' => null,
                'target_ref' => null,
                'sort_order' => 5,
                'is_active' => true,
            ],
            [
                'block_code' => 'home-split-message',
                'placement' => 'home.before_products',
                'target_type' => null,
                'target_ref' => null,
                'sort_order' => 10,
                'is_active' => true,
            ],
            [
                'block_code' => 'home-products-carousel',
                'placement' => 'home.after_products',
                'target_type' => null,
                'target_ref' => null,
                'sort_order' => 10,
                'is_active' => true,
            ],
            [
                'block_code' => 'home-blog-grid-3',
                'placement' => 'home.after_products',
                'target_type' => null,
                'target_ref' => null,
                'sort_order' => 20,
                'is_active' => true,
            ],
            [
                'block_code' => 'home-cards-3',
                'placement' => 'home.after_products',
                'target_type' => null,
                'target_ref' => null,
                'sort_order' => 30,
                'is_active' => true,
            ],
            [
                'block_code' => 'dev-polishing-note',
                'placement' => 'home.bottom',
                'target_type' => null,
                'target_ref' => null,
                'sort_order' => 30,
                'is_active' => true,
            ],
            [
                'block_code' => 'category-rich-intro',
                'placement' => 'category.top',
                'target_type' => 'category',
                'target_ref' => 'food',
                'sort_order' => 0,
                'is_active' => true,
            ],
            [
                'block_code' => 'category-rich-intro',
                'placement' => 'category.bottom',
                'target_type' => 'category',
                'target_ref' => 'gift-boxes',
                'sort_order' => 10,
                'is_active' => true,
            ],
            [
                'block_code' => 'blog-cta-banner',
                'placement' => 'blog.bottom',
                'target_type' => 'blog_post',
                'target_ref' => 'quick-wok-noodles',
                'sort_order' => 0,
                'is_active' => true,
            ],
            [
                'block_code' => 'blog-cta-banner',
                'placement' => 'page.bottom',
                'target_type' => null,
                'target_ref' => null,
                'sort_order' => 10,
                'is_active' => true,
            ],
        ];

        foreach ($records as $record) {
            $blockId = ContentBlock::query()
                ->where('code', $record['block_code'])
                ->value('id');

            if (!$blockId) {
                $missingBlockCodes[] = (string) $record['block_code'];
                continue;
            }

            ContentBlockSlot::query()->updateOrCreate(
                [
                    'content_block_id' => $blockId,
                    'placement' => $record['placement'],
                    'target_type' => $record['target_type'],
                    'target_ref' => $record['target_ref'],
                ],
                [
                    'sort_order' => (int) $record['sort_order'],
                    'is_active' => (bool) $record['is_active'],
                    'starts_at' => null,
                    'ends_at' => null,
                    'created_by' => $userId,
                    'updated_by' => $userId,
                ]
            );
        }

        if ($missingBlockCodes !== []) {
            $codes = implode(', ', array_unique($missingBlockCodes));
            throw new RuntimeException('ContentBlockSlotSeeder missing block codes: '.$codes);
        }
    }
}
