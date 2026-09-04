<?php

namespace Database\Seeders;

use App\Models\Catalog\Action\CatalogAction;
use App\Models\Catalog\Category\Category;
use App\Models\Catalog\Manufacturer\Manufacturer;
use App\Models\Catalog\Product\Product;
use App\Models\User;
use Illuminate\Database\Seeder;

class CatalogActionSeeder extends Seeder
{
    public function run(): void
    {
        $userId = User::query()->value('id');

        $categoryFoodId = Category::query()
            ->where('scope', Category::SCOPE_CATALOG)
            ->where('code', 'food')
            ->value('id');

        $productTeaId = Product::query()
            ->where('code', 'green-tea-sencha-100g')
            ->value('id');

        $productRiceId = Product::query()
            ->where('code', 'rice-premium-jasmine-1kg')
            ->value('id');

        $giftProductId = Product::query()
            ->where('code', 'bamboo-spatula-set')
            ->value('id');

        $manufacturerId = Manufacturer::query()
            ->where('code', 'ag-nature')
            ->value('id');

        $records = [
            [
                'code' => 'promo-rice-10',
                'scope' => CatalogAction::SCOPE_PRODUCT,
                'type' => CatalogAction::TYPE_PERCENTAGE,
                'discount_value' => 10,
                'target_type' => CatalogAction::TARGET_CATEGORY,
                'target_ids' => array_filter([(int) $categoryFoodId]),
                'priority' => 20,
                'is_exclusive' => false,
                'is_active' => true,
                'translations' => [
                    'en' => ['title' => 'Food Category 10% Off', 'description' => 'Applies to products in food category.'],
                    'hr' => ['title' => 'Hrana 10% popusta', 'description' => 'Vrijedi za artikle u kategoriji hrane.'],
                ],
            ],
            [
                'code' => 'coupon-tea-2',
                'scope' => CatalogAction::SCOPE_PRODUCT,
                'type' => CatalogAction::TYPE_FIXED,
                'discount_value' => 2.00,
                'target_type' => CatalogAction::TARGET_PRODUCT,
                'target_ids' => array_filter([(int) $productTeaId]),
                'coupon_code' => 'TEA2',
                'priority' => 40,
                'is_exclusive' => true,
                'is_active' => true,
                'translations' => [
                    'en' => ['title' => 'Tea Coupon Fixed Discount', 'description' => 'Use code TEA2 for fixed discount.'],
                    'hr' => ['title' => 'Čaj kupon fiksni popust', 'description' => 'Koristi kod TEA2 za fiksni popust.'],
                ],
            ],
            [
                'code' => 'b2g1-rice',
                'scope' => CatalogAction::SCOPE_CART,
                'type' => CatalogAction::TYPE_BUY_X_GET_Y,
                'target_type' => CatalogAction::TARGET_PRODUCT,
                'target_ids' => array_filter([(int) $productRiceId]),
                'buy_qty' => 2,
                'get_qty' => 1,
                'priority' => 15,
                'is_exclusive' => false,
                'is_active' => true,
                'translations' => [
                    'en' => ['title' => 'Buy 2 Get 1 Rice', 'description' => 'Cart rule prepared for checkout engine.'],
                    'hr' => ['title' => 'Kupi 2 uzmi 1 rižu', 'description' => 'Pravilo košarice pripremljeno za checkout logiku.'],
                ],
            ],
            [
                'code' => 'gift-over-80',
                'scope' => CatalogAction::SCOPE_CART,
                'type' => CatalogAction::TYPE_GIFT_ON_AMOUNT,
                'target_type' => CatalogAction::TARGET_MANUFACTURER,
                'target_ids' => array_filter([(int) $manufacturerId]),
                'min_subtotal' => 80,
                'gift_product_id' => $giftProductId ? (int) $giftProductId : null,
                'priority' => 10,
                'is_exclusive' => false,
                'is_active' => true,
                'translations' => [
                    'en' => ['title' => 'Gift Over 80', 'description' => 'Gift action when cart reaches threshold.'],
                    'hr' => ['title' => 'Poklon iznad 80', 'description' => 'Poklon akcija kada košarica prijeđe prag.'],
                ],
            ],
        ];

        foreach ($records as $record) {
            $action = CatalogAction::query()->updateOrCreate(
                ['code' => $record['code']],
                [
                    'scope' => $record['scope'],
                    'type' => $record['type'],
                    'discount_value' => $record['discount_value'] ?? null,
                    'target_type' => $record['target_type'],
                    'audience_type' => CatalogAction::AUDIENCE_ALL,
                    'coupon_code' => $record['coupon_code'] ?? null,
                    'min_subtotal' => $record['min_subtotal'] ?? null,
                    'buy_qty' => $record['buy_qty'] ?? null,
                    'get_qty' => $record['get_qty'] ?? null,
                    'gift_product_id' => $record['gift_product_id'] ?? null,
                    'priority' => (int) ($record['priority'] ?? 0),
                    'is_exclusive' => (bool) ($record['is_exclusive'] ?? false),
                    'is_active' => (bool) ($record['is_active'] ?? true),
                    'payload' => null,
                    'updated_by' => $userId,
                    'created_by' => $userId,
                ]
            );

            foreach ($record['translations'] as $locale => $translation) {
                $action->translations()->updateOrCreate(
                    ['locale' => $locale],
                    [
                        'title' => $translation['title'],
                        'description' => $translation['description'] ?? null,
                        'badge' => null,
                        'payload' => null,
                    ]
                );
            }

            $action->targets()->delete();
            foreach (array_values($record['target_ids'] ?? []) as $index => $targetId) {
                $action->targets()->create([
                    'target_type' => $record['target_type'],
                    'target_id' => (int) $targetId,
                    'sort_order' => $index,
                ]);
            }
        }
    }
}

