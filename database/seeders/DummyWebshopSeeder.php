<?php

namespace Database\Seeders;

use App\Models\Catalog\Action\CatalogAction;
use App\Models\Catalog\Attribute\Attribute;
use App\Models\Catalog\Category\Category;
use App\Models\Catalog\Manufacturer\Manufacturer;
use App\Models\Catalog\Option\Option;
use App\Models\Catalog\Option\OptionValue;
use App\Models\Catalog\Product\Product;
use App\Models\Catalog\Product\ProductOptionValue;
use App\Models\Content\Blog\BlogPost;
use App\Models\Content\ContentBlock;
use App\Models\Content\ContentBlockSlot;
use App\Models\Content\Page\InfoPage;
use App\Models\Content\Support\Comment;
use App\Models\Content\Support\Faq;
use App\Models\Sales\Order\Order;
use App\Models\Settings\Local\Language;
use App\Models\Settings\Local\OrderStatus;
use App\Models\Settings\Local\PaymentMethod;
use App\Models\Settings\Local\ShippingMethod;
use App\Models\User;
use App\Models\User\CustomerGroup;
use App\Models\User\LoyaltyTransaction;
use App\Models\User\UserAddress;
use App\Models\User\UserProfile;
use App\Models\User\UserTrackingEvent;
use App\Services\Settings\SystemSettingsService;
use Faker\Generator;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Silber\Bouncer\BouncerFacade as Bouncer;

class DummyWebshopSeeder extends Seeder
{
    private const TARGET_USERS = 500;

    private const TARGET_CATALOG_CATEGORIES = 78;

    private const TARGET_BLOG_CATEGORIES = 14;

    private const TARGET_PAGE_CATEGORIES = 8;

    private const TARGET_PRODUCTS = 1000;

    private const TARGET_MANUFACTURERS = 30;

    private const TARGET_BLOG_POSTS = 200;

    private const TARGET_INFO_PAGES = 24;

    private const TARGET_FAQS = 20;

    private const TARGET_COMMENTS = 500;

    private const TARGET_ORDERS = 3000;

    private const TARGET_CONTENT_BLOCKS = 24;

    private const TARGET_CONTENT_SLOTS = 60;

    private const TARGET_OPTIONS = 12;

    private const TARGET_ATTRIBUTES = 60;

    private const TARGET_USER_TRACKING_EVENTS = 6000;

    /**
     * @var array<int, string>
     */
    private array $locales = [];

    /**
     * @var array<int, int>
     */
    private array $adminUserIds = [];

    /**
     * @var array<int, int>
     */
    private array $customerGroupIds = [];

    private Generator $faker;

    public function run(): void
    {
        $this->faker = fake();
        $this->locales = $this->resolveLocales();

        $this->call([
            RoleSeeder::class,
            CustomerGroupSeeder::class,
            UserSedder::class,
            SettingsLocalSeeder::class,
            SystemSettingsSeeder::class,
        ]);

        $this->note('Enabling feature switches.');
        $this->enableAllFeatureFlags();

        $this->note('Seeding users and user groups.');
        [$allUserIds, $customerUserIds] = $this->seedUsersAndGroups();

        $this->note('Seeding categories.');
        $categoriesByScope = $this->seedCategories();

        $this->note('Seeding manufacturers, attributes and options.');
        $manufacturerIds = $this->seedManufacturers();
        $attributeIds = $this->seedAttributes();
        $optionPool = $this->seedOptions();

        $this->note('Seeding products and catalog relations.');
        $productIds = $this->seedProducts(
            catalogCategoryIds: $categoriesByScope['catalog'],
            manufacturerIds: $manufacturerIds,
            attributeIds: $attributeIds,
            optionPool: $optionPool
        );

        $this->note('Seeding actions and discounts.');
        $this->seedActions(
            productIds: $productIds,
            categoryIds: $categoriesByScope['catalog'],
            manufacturerIds: $manufacturerIds,
            customerUserIds: $customerUserIds
        );

        $this->note('Seeding blog, info pages and FAQs.');
        $blogIds = $this->seedBlogPosts($categoriesByScope['blog']);
        $pageIds = $this->seedInfoPages($categoriesByScope['page']);
        $faqIds = $this->seedFaqs();

        $this->note('Seeding content blocks and slots.');
        $this->seedContentBlocksAndSlots(
            categoryCodesByScope: $this->categoryCodesByScope(),
            productCodes: $this->codesForModel(Product::class, 'code', self::TARGET_PRODUCTS),
            blogCodes: $this->codesForModel(BlogPost::class, 'code', self::TARGET_BLOG_POSTS),
            pageCodes: $this->codesForModel(InfoPage::class, 'code', self::TARGET_INFO_PAGES)
        );

        $this->note('Seeding orders.');
        $this->seedOrders($productIds, $customerUserIds);

        $this->note('Seeding comments.');
        $this->seedComments($productIds, $blogIds, $pageIds, $faqIds, $customerUserIds);

        $this->note('Seeding user tracking entries.');
        $this->seedUserTracking($customerUserIds, $productIds, $blogIds, $pageIds);

        $loyaltyEnabled = (bool) app(SystemSettingsService::class)->get(
            'user_loyalty_enabled',
            (bool) config('user_features.flags.user_loyalty_enabled', false)
        );
        if ($loyaltyEnabled) {
            $this->note('Seeding loyalty entries.');
            $this->seedLoyaltyTransactions();
        }

        $this->note('Dummy webshop data seeding complete.');
    }

    /**
     * @return array{0: array<int, int>, 1: array<int, int>}
     */
    private function seedUsersAndGroups(): array
    {
        $this->ensureExtraCustomerGroups();

        $filip = User::query()->firstOrCreate(
            ['email' => 'filip@agmedia.hr'],
            ['name' => 'Filip Jankoski', 'password' => 'majamaja001', 'email_verified_at' => now()]
        );

        $tomislav = User::query()->firstOrCreate(
            ['email' => 'tomislav@agmedia.hr'],
            ['name' => 'Tomislav Juresa', 'password' => 'bakanal', 'email_verified_at' => now()]
        );

        Bouncer::assign('superadmin')->to($filip);
        Bouncer::assign('superadmin')->to($tomislav);

        $adminUser = User::query()->firstOrCreate(
            ['email' => 'admin@agshop.local'],
            ['name' => 'admin', 'password' => 'admin', 'email_verified_at' => now()]
        );
        Bouncer::assign('admin')->to($adminUser);

        $editorUser = User::query()->firstOrCreate(
            ['email' => 'editor@agshop.local'],
            ['name' => 'editor', 'password' => 'editor', 'email_verified_at' => now()]
        );
        Bouncer::assign('editor')->to($editorUser);

        $customerUser = User::query()->firstOrCreate(
            ['email' => 'customer@agshop.local'],
            ['name' => 'customer', 'password' => 'customer', 'email_verified_at' => now()]
        );
        Bouncer::assign('customer')->to($customerUser);

        $existingCount = (int) User::query()->count();
        $toCreate = max(0, self::TARGET_USERS - $existingCount);
        $nextIndex = $this->nextIndexByPattern(
            values: User::query()->where('email', 'like', 'demo.user%')->pluck('email')->all(),
            pattern: '/^demo\.user(\d{4})@example\.test$/'
        );

        for ($i = 0; $i < $toCreate; $i++) {
            $seq = $nextIndex + $i;
            $first = $this->faker->firstName();
            $last = $this->faker->lastName();

            $user = User::query()->create([
                'name' => trim($first.' '.$last),
                'email' => sprintf('demo.user%04d@example.test', $seq),
                'password' => 'password',
                'email_verified_at' => now()->subDays(random_int(0, 180)),
            ]);

            if ($i < 10) {
                Bouncer::assign('admin')->to($user);
            } elseif ($i < 25) {
                Bouncer::assign('editor')->to($user);
            } else {
                Bouncer::assign('customer')->to($user);
            }
        }

        $this->ensureUserProfilesAndAddresses();
        $this->ensureUserGroupMemberships();

        $this->adminUserIds = User::query()
            ->whereHas('roles', function ($query): void {
                $query->whereIn('name', ['superadmin', 'admin']);
            })
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        if ($this->adminUserIds === []) {
            $this->adminUserIds = User::query()
                ->orderBy('id')
                ->limit(2)
                ->pluck('id')
                ->map(fn ($id): int => (int) $id)
                ->all();
        }

        $customerUserIds = User::query()
            ->whereHas('roles', fn ($query) => $query->where('name', 'customer'))
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        if ($customerUserIds === []) {
            $customerUserIds = User::query()
                ->whereNotIn('id', $this->adminUserIds)
                ->pluck('id')
                ->map(fn ($id): int => (int) $id)
                ->all();
        }

        $allUserIds = User::query()
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        return [$allUserIds, $customerUserIds];
    }

    private function ensureExtraCustomerGroups(): void
    {
        $groups = [
            ['code' => 'newsletter', 'name' => 'Newsletter', 'description' => 'Subscribed newsletter audience.', 'sort_order' => 40],
            ['code' => 'wholesale', 'name' => 'Wholesale', 'description' => 'Wholesale buyers.', 'sort_order' => 50],
            ['code' => 'promo', 'name' => 'Promo Segment', 'description' => 'Promotional segment for actions.', 'sort_order' => 60],
        ];

        foreach ($groups as $group) {
            CustomerGroup::query()->updateOrCreate(
                ['code' => $group['code']],
                $group + ['is_active' => true, 'is_default' => false, 'payload' => null]
            );
        }

        $this->customerGroupIds = CustomerGroup::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    private function ensureUserProfilesAndAddresses(): void
    {
        User::query()
            ->orderBy('id')
            ->chunkById(100, function (Collection $users): void {
                /** @var User $user */
                foreach ($users as $user) {
                    $nameParts = preg_split('/\s+/', trim((string) $user->name)) ?: [];
                    $firstName = $nameParts[0] ?? 'Demo';
                    $lastName = $nameParts[1] ?? 'User';

                    UserProfile::query()->updateOrCreate(
                        ['user_id' => $user->id],
                        [
                            'first_name' => $firstName,
                            'last_name' => $lastName,
                            'phone' => '+38591'.random_int(1000000, 9999999),
                            'company' => $this->faker->boolean(25) ? $this->faker->company() : null,
                            'oib' => str_pad((string) random_int(1, 99999999999), 11, '0', STR_PAD_LEFT),
                            'gender' => $this->faker->randomElement(['male', 'female', 'other']),
                            'newsletter_opt_in' => $this->faker->boolean(62),
                            'bio' => $this->faker->sentence(12),
                        ]
                    );

                    UserAddress::query()->updateOrCreate(
                        ['user_id' => $user->id, 'type' => UserAddress::TYPE_BILLING],
                        [
                            'first_name' => $firstName,
                            'last_name' => $lastName,
                            'company' => $this->faker->boolean(20) ? $this->faker->company() : null,
                            'phone' => '+38591'.random_int(1000000, 9999999),
                            'address_line_1' => $this->faker->streetAddress(),
                            'postal_code' => (string) random_int(10000, 53296),
                            'city' => $this->faker->city(),
                            'country_code' => 'HR',
                            'is_default' => true,
                        ]
                    );

                    UserAddress::query()->updateOrCreate(
                        ['user_id' => $user->id, 'type' => UserAddress::TYPE_SHIPPING],
                        [
                            'first_name' => $firstName,
                            'last_name' => $lastName,
                            'phone' => '+38591'.random_int(1000000, 9999999),
                            'address_line_1' => $this->faker->streetAddress(),
                            'postal_code' => (string) random_int(10000, 53296),
                            'city' => $this->faker->city(),
                            'country_code' => 'HR',
                            'is_default' => true,
                        ]
                    );
                }
            });
    }

    private function ensureUserGroupMemberships(): void
    {
        if ($this->customerGroupIds === []) {
            return;
        }

        $retailId = CustomerGroup::query()->where('code', 'retail')->value('id');
        $candidateGroupIds = array_values(array_filter($this->customerGroupIds, fn (int $id): bool => $id !== (int) $retailId));

        User::query()
            ->whereHas('roles', fn ($query) => $query->where('name', 'customer'))
            ->orderBy('id')
            ->chunkById(100, function (Collection $users) use ($retailId, $candidateGroupIds): void {
                /** @var User $user */
                foreach ($users as $user) {
                    $assignIds = [];
                    if ($retailId) {
                        $assignIds[] = (int) $retailId;
                    }

                    if ($candidateGroupIds !== [] && $this->chance(55)) {
                        $assignIds[] = $candidateGroupIds[array_rand($candidateGroupIds)];
                    }

                    if ($candidateGroupIds !== [] && $this->chance(20)) {
                        $assignIds[] = $candidateGroupIds[array_rand($candidateGroupIds)];
                    }

                    $assignIds = array_values(array_unique(array_map('intval', $assignIds)));
                    if ($assignIds !== []) {
                        $user->customerGroups()->syncWithoutDetaching($assignIds);
                    }
                }
            });
    }

    /**
     * @return array{catalog: array<int, int>, blog: array<int, int>, page: array<int, int>}
     */
    private function seedCategories(): array
    {
        $catalog = $this->seedCategoriesForScope(Category::SCOPE_CATALOG, self::TARGET_CATALOG_CATEGORIES);
        $blog = $this->seedCategoriesForScope(Category::SCOPE_BLOG, self::TARGET_BLOG_CATEGORIES);
        $page = $this->seedCategoriesForScope(Category::SCOPE_PAGE, self::TARGET_PAGE_CATEGORIES);

        return [
            'catalog' => $catalog,
            'blog' => $blog,
            'page' => $page,
        ];
    }

    /**
     * @return array<int, int>
     */
    private function seedCategoriesForScope(string $scope, int $target): array
    {
        $categories = Category::query()
            ->where('scope', $scope)
            ->orderBy('id')
            ->get();

        $toCreate = max(0, $target - $categories->count());
        $nextIndex = $this->nextIndexByPattern(
            values: Category::query()
                ->where('scope', $scope)
                ->where('code', 'like', 'demo-'.$scope.'-cat-%')
                ->pluck('code')
                ->all(),
            pattern: '/^demo\-'.preg_quote($scope, '/').'\-cat\-(\d{3})$/'
        );

        for ($i = 0; $i < $toCreate; $i++) {
            $seq = $nextIndex + $i;
            $code = sprintf('demo-%s-cat-%03d', $scope, $seq);
            $parent = $this->pickCategoryParent($scope, $categories);
            $creator = $this->randomId($this->adminUserIds);

            $category = new Category([
                'scope' => $scope,
                'code' => $code,
                'is_active' => true,
                'show_in_menu' => true,
                'sort_order' => ($seq * 10),
                'payload' => $scope === Category::SCOPE_PAGE
                    ? ['show_in_footer' => $this->chance(60)]
                    : ['seed' => 'demo'],
                'created_by' => $creator,
                'updated_by' => $creator,
            ]);

            if ($parent) {
                $category->appendToNode($parent)->save();
            } else {
                $category->saveAsRoot();
            }

            foreach ($this->locales as $locale) {
                $name = $this->categoryName($scope, $seq, $locale);
                $category->translations()->updateOrCreate(
                    ['locale' => $locale],
                    [
                        'scope' => $scope,
                        'name' => $name,
                        'slug' => $code.'-'.$locale,
                        'description' => $this->faker->sentence(14),
                        'meta_title' => $name,
                        'meta_description' => $this->faker->sentence(16),
                        'payload' => null,
                    ]
                );
            }

            $categories->push($category->refresh());
        }

        return Category::query()
            ->where('scope', $scope)
            ->orderBy('id')
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    private function pickCategoryParent(string $scope, Collection $categories): ?Category
    {
        if ($categories->isEmpty()) {
            return null;
        }

        $rootChance = match ($scope) {
            Category::SCOPE_PAGE => 65,
            Category::SCOPE_BLOG => 40,
            default => 25,
        };

        if ($this->chance($rootChance)) {
            return null;
        }

        $roots = $categories->where('parent_id', null)->values();
        if ($roots->isNotEmpty() && $this->chance(80)) {
            /** @var Category $root */
            $root = $roots->random();

            return $root;
        }

        /** @var Category $any */
        $any = $categories->random();

        return $any;
    }

    /**
     * @return array<int, int>
     */
    private function seedManufacturers(): array
    {
        $existingCount = (int) Manufacturer::query()->count();
        $toCreate = max(0, self::TARGET_MANUFACTURERS - $existingCount);
        $nextIndex = $this->nextIndexByPattern(
            values: Manufacturer::query()->where('code', 'like', 'demo-manufacturer-%')->pluck('code')->all(),
            pattern: '/^demo\-manufacturer\-(\d{3})$/'
        );

        for ($i = 0; $i < $toCreate; $i++) {
            $seq = $nextIndex + $i;
            $code = sprintf('demo-manufacturer-%03d', $seq);
            $creator = $this->randomId($this->adminUserIds);

            $manufacturer = Manufacturer::query()->updateOrCreate(
                ['code' => $code],
                [
                    'is_active' => true,
                    'is_featured' => $this->chance(22),
                    'sort_order' => $seq * 10,
                    'payload' => ['seed' => 'demo'],
                    'created_by' => $creator,
                    'updated_by' => $creator,
                ]
            );

            foreach ($this->locales as $locale) {
                $name = $locale === 'hr'
                    ? 'Proizvodac '.$seq
                    : 'Manufacturer '.$seq;

                $manufacturer->translations()->updateOrCreate(
                    ['locale' => $locale],
                    [
                        'name' => $name,
                        'slug' => $code.'-'.$locale,
                        'description' => $this->faker->sentence(14),
                        'meta_title' => $name,
                        'meta_description' => $this->faker->sentence(16),
                        'payload' => null,
                    ]
                );
            }
        }

        return Manufacturer::query()
            ->orderBy('id')
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    /**
     * @return array<int, int>
     */
    private function seedAttributes(): array
    {
        $groupCodes = ['material', 'origin', 'diet', 'season', 'audience', 'finish', 'format', 'line', 'feature', 'texture'];
        $groupNames = [
            'en' => ['Material', 'Origin', 'Diet', 'Season', 'Audience', 'Finish', 'Format', 'Line', 'Feature', 'Texture'],
            'hr' => ['Materijal', 'Podrijetlo', 'Prehrana', 'Sezona', 'Publika', 'Zavrsna obrada', 'Format', 'Linija', 'Znacajka', 'Tekstura'],
        ];

        $existingCount = (int) Attribute::query()->count();
        $toCreate = max(0, self::TARGET_ATTRIBUTES - $existingCount);
        $nextIndex = $this->nextIndexByPattern(
            values: Attribute::query()->where('code', 'like', 'demo-attribute-%')->pluck('code')->all(),
            pattern: '/^demo\-attribute\-(\d{3})$/'
        );

        for ($i = 0; $i < $toCreate; $i++) {
            $seq = $nextIndex + $i;
            $code = sprintf('demo-attribute-%03d', $seq);
            $groupIndex = ($seq - 1) % count($groupCodes);
            $groupCode = $groupCodes[$groupIndex];
            $creator = $this->randomId($this->adminUserIds);

            $attribute = Attribute::query()->updateOrCreate(
                ['code' => $code],
                [
                    'group_code' => $groupCode,
                    'type' => $this->chance(35) ? Attribute::TYPE_MULTI : Attribute::TYPE_SELECT,
                    'is_active' => true,
                    'sort_order' => $seq * 10,
                    'payload' => ['seed' => 'demo'],
                    'created_by' => $creator,
                    'updated_by' => $creator,
                ]
            );

            foreach ($this->locales as $locale) {
                $groupName = $groupNames[$locale][$groupIndex] ?? ($groupNames['en'][$groupIndex] ?? ucfirst($groupCode));
                $name = ($locale === 'hr' ? 'Atribut' : 'Attribute').' '.$seq;
                $attribute->translations()->updateOrCreate(
                    ['locale' => $locale],
                    [
                        'group_name' => $groupName,
                        'name' => $name,
                        'slug' => $code.'-'.$locale,
                        'description' => $this->faker->sentence(10),
                        'payload' => null,
                    ]
                );
            }
        }

        return Attribute::query()->orderBy('id')->pluck('id')->map(fn ($id): int => (int) $id)->all();
    }

    /**
     * @return array<int, array{option_id:int,value_ids:array<int,int>}>
     */
    private function seedOptions(): array
    {
        $existingCount = (int) Option::query()->count();
        $toCreate = max(0, self::TARGET_OPTIONS - $existingCount);
        $nextIndex = $this->nextIndexByPattern(
            values: Option::query()->where('code', 'like', 'demo-option-%')->pluck('code')->all(),
            pattern: '/^demo\-option\-(\d{2})$/'
        );

        for ($i = 0; $i < $toCreate; $i++) {
            $seq = $nextIndex + $i;
            $code = sprintf('demo-option-%02d', $seq);
            $creator = $this->randomId($this->adminUserIds);

            $option = Option::query()->updateOrCreate(
                ['code' => $code],
                [
                    'type' => $this->faker->randomElement(Option::availableTypes()),
                    'is_active' => true,
                    'sort_order' => $seq * 10,
                    'payload' => ['seed' => 'demo'],
                    'created_by' => $creator,
                    'updated_by' => $creator,
                ]
            );

            foreach ($this->locales as $locale) {
                $name = ($locale === 'hr' ? 'Opcija' : 'Option').' '.$seq;
                $option->translations()->updateOrCreate(
                    ['locale' => $locale],
                    [
                        'name' => $name,
                        'slug' => $code.'-'.$locale,
                        'description' => $this->faker->sentence(8),
                        'payload' => null,
                    ]
                );
            }
        }

        $options = Option::query()->with('values')->orderBy('id')->get();
        foreach ($options as $option) {
            $valueTarget = 8;
            $missing = max(0, $valueTarget - $option->values->count());
            $nextValueIndex = $this->nextIndexByPattern(
                values: OptionValue::query()
                    ->where('option_id', $option->id)
                    ->where('code', 'like', $option->code.'-v%')
                    ->pluck('code')
                    ->all(),
                pattern: '/^'.preg_quote($option->code, '/').'\-v(\d{2})$/'
            );

            for ($i = 0; $i < $missing; $i++) {
                $seq = $nextValueIndex + $i;
                $valueCode = sprintf('%s-v%02d', $option->code, $seq);
                $creator = $this->randomId($this->adminUserIds);

                $value = OptionValue::query()->updateOrCreate(
                    ['option_id' => $option->id, 'code' => $valueCode],
                    [
                        'is_active' => true,
                        'sort_order' => $seq * 10,
                        'payload' => ['seed' => 'demo'],
                        'created_by' => $creator,
                        'updated_by' => $creator,
                    ]
                );

                foreach ($this->locales as $locale) {
                    $name = ($locale === 'hr' ? 'Vrijednost' : 'Value').' '.$seq;
                    $value->translations()->updateOrCreate(
                        ['locale' => $locale],
                        [
                            'name' => $name,
                            'slug' => $valueCode.'-'.$locale,
                            'payload' => null,
                        ]
                    );
                }
            }
        }

        return Option::query()
            ->with(['values:id,option_id'])
            ->orderBy('id')
            ->get(['id'])
            ->map(function (Option $option): array {
                return [
                    'option_id' => (int) $option->id,
                    'value_ids' => $option->values->pluck('id')->map(fn ($id): int => (int) $id)->all(),
                ];
            })
            ->all();
    }

    /**
     * @param  array<int, int>  $catalogCategoryIds
     * @param  array<int, int>  $manufacturerIds
     * @param  array<int, int>  $attributeIds
     * @param  array<int, array{option_id:int,value_ids:array<int,int>}>  $optionPool
     * @return array<int, int>
     */
    private function seedProducts(
        array $catalogCategoryIds,
        array $manufacturerIds,
        array $attributeIds,
        array $optionPool
    ): array {
        $existingCount = (int) Product::query()->count();
        $toCreate = max(0, self::TARGET_PRODUCTS - $existingCount);
        $nextIndex = $this->nextIndexByPattern(
            values: Product::query()->where('code', 'like', 'demo-product-%')->pluck('code')->all(),
            pattern: '/^demo\-product\-(\d{4})$/'
        );

        for ($i = 0; $i < $toCreate; $i++) {
            $seq = $nextIndex + $i;
            $code = sprintf('demo-product-%04d', $seq);
            $sku = sprintf('DSP-%05d', $seq);
            $creator = $this->randomId($this->adminUserIds);
            $price = round((float) $this->faker->randomFloat(2, 1.8, 320), 2);
            $stock = random_int(0, 280);

            $product = Product::query()->updateOrCreate(
                ['code' => $code],
                [
                    'sku' => $sku,
                    'is_active' => $this->chance(96),
                    'manufacturer_id' => $manufacturerIds !== [] && $this->chance(82)
                        ? $this->randomId($manufacturerIds)
                        : null,
                    'base_price' => $price,
                    'stock_qty' => $stock,
                    'payload' => [
                        'seed' => 'demo',
                        'barcode' => str_pad((string) random_int(1, 9999999999999), 13, '0', STR_PAD_LEFT),
                        'weight_kg' => round((float) $this->faker->randomFloat(2, 0.1, 3.4), 2),
                    ],
                    'created_by' => $creator,
                    'updated_by' => $creator,
                ]
            );

            foreach ($this->locales as $locale) {
                $name = $locale === 'hr'
                    ? 'Demo proizvod '.$seq
                    : 'Demo product '.$seq;

                $excerpt = $locale === 'hr'
                    ? 'Demo opis za proizvod '.$seq.'.'
                    : 'Demo excerpt for product '.$seq.'.';

                $product->translations()->updateOrCreate(
                    ['locale' => $locale],
                    [
                        'name' => $name,
                        'slug' => $code.'-'.$locale,
                        'excerpt' => $excerpt,
                        'description' => '<p>'.$excerpt.'</p><p>'.$this->faker->sentence(24).'</p>',
                        'meta_title' => $name,
                        'meta_description' => $excerpt,
                        'payload' => null,
                    ]
                );
            }

            if ($catalogCategoryIds !== []) {
                $categoryCount = random_int(1, min(3, count($catalogCategoryIds)));
                $picked = $this->pickMany($catalogCategoryIds, $categoryCount);
                $syncPayload = [];
                foreach (array_values($picked) as $pos => $categoryId) {
                    $syncPayload[(int) $categoryId] = [
                        'sort_order' => $pos,
                        'is_primary' => $pos === 0,
                    ];
                }
                $product->categories()->sync($syncPayload);
            }
        }

        $productIds = Product::query()
            ->orderByDesc('id')
            ->limit(self::TARGET_PRODUCTS)
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        $this->seedProductAttributes($productIds, $attributeIds);
        $this->seedProductOptions($productIds, $optionPool);

        return $productIds;
    }

    /**
     * @param  array<int, int>  $productIds
     * @param  array<int, int>  $attributeIds
     */
    private function seedProductAttributes(array $productIds, array $attributeIds): void
    {
        if ($productIds === [] || $attributeIds === []) {
            return;
        }

        $targetCount = (int) floor(count($productIds) * 0.7);
        $selectedProductIds = $this->pickMany($productIds, $targetCount);
        $products = Product::query()->whereIn('id', $selectedProductIds)->get()->keyBy('id');

        foreach ($selectedProductIds as $productId) {
            /** @var Product|null $product */
            $product = $products->get((int) $productId);
            if (! $product) {
                continue;
            }

            $take = random_int(2, min(5, count($attributeIds)));
            $pickedAttributes = $this->pickMany($attributeIds, $take);
            $syncPayload = [];
            foreach (array_values($pickedAttributes) as $index => $attributeId) {
                $syncPayload[(int) $attributeId] = ['sort_order' => $index];
            }

            $product->attributes()->sync($syncPayload);
        }
    }

    /**
     * @param  array<int, int>  $productIds
     * @param  array<int, array{option_id:int,value_ids:array<int,int>}>  $optionPool
     */
    private function seedProductOptions(array $productIds, array $optionPool): void
    {
        if ($productIds === [] || $optionPool === []) {
            return;
        }

        $targetCount = (int) floor(count($productIds) * 0.5);
        $selectedProductIds = $this->pickMany($productIds, $targetCount);
        $products = Product::query()->whereIn('id', $selectedProductIds)->get()->keyBy('id');

        foreach ($selectedProductIds as $productId) {
            /** @var Product|null $product */
            $product = $products->get((int) $productId);
            if (! $product) {
                continue;
            }

            $maxPick = min(7, count($optionPool));
            $minPick = min(3, $maxPick);
            $optionCount = random_int($minPick, $maxPick);
            $pickedOptions = $this->pickMany($optionPool, $optionCount);

            $syncPayload = [];
            foreach (array_values($pickedOptions) as $index => $pickedOption) {
                $syncPayload[(int) $pickedOption['option_id']] = [
                    'sort_order' => $index,
                    'is_required' => $this->chance(58),
                ];
            }

            $product->options()->sync($syncPayload);
            ProductOptionValue::query()->where('product_id', $product->id)->delete();

            $sortOrder = 0;
            foreach ($pickedOptions as $pickedOption) {
                $valueIds = array_values(array_map('intval', $pickedOption['value_ids'] ?? []));
                if ($valueIds === []) {
                    continue;
                }

                $valuePick = $this->pickMany($valueIds, random_int(1, min(2, count($valueIds))));
                foreach ($valuePick as $valueId) {
                    ProductOptionValue::query()->create([
                        'product_id' => $product->id,
                        'option_value_id' => (int) $valueId,
                        'parent_option_value_id' => null,
                        'mode' => 'single',
                        'sku' => ($product->sku ?: 'DSP-'.$product->id).'-OV'.$valueId,
                        'stock_qty' => max(0, ((int) $product->stock_qty) + random_int(-12, 18)),
                        'price_override' => round(((float) $product->base_price) * ($this->faker->randomFloat(4, 0.9, 1.2)), 2),
                        'sort_order' => $sortOrder++,
                        'is_active' => true,
                        'combination_hash' => hash('sha256', 's:'.$valueId),
                        'payload' => ['seed' => 'demo'],
                        'created_by' => $this->randomId($this->adminUserIds),
                        'updated_by' => $this->randomId($this->adminUserIds),
                    ]);
                }
            }
        }
    }

    /**
     * @param  array<int, int>  $productIds
     * @param  array<int, int>  $categoryIds
     * @param  array<int, int>  $manufacturerIds
     * @param  array<int, int>  $customerUserIds
     */
    private function seedActions(
        array $productIds,
        array $categoryIds,
        array $manufacturerIds,
        array $customerUserIds
    ): void {
        if ($productIds === []) {
            return;
        }

        $productTargeted = $this->pickMany($productIds, max(1, (int) floor(count($productIds) * 0.3)));
        $chunks = array_chunk($productTargeted, 4);

        foreach ($chunks as $idx => $chunk) {
            $seq = $idx + 1;
            $type = $this->chance(75) ? CatalogAction::TYPE_PERCENTAGE : CatalogAction::TYPE_FIXED;
            $audienceType = $this->faker->randomElement([
                CatalogAction::AUDIENCE_ALL,
                CatalogAction::AUDIENCE_ALL,
                CatalogAction::AUDIENCE_USER_GROUP,
                CatalogAction::AUDIENCE_USER,
            ]);

            $action = CatalogAction::query()->updateOrCreate(
                ['code' => sprintf('demo-product-action-%03d', $seq)],
                [
                    'scope' => CatalogAction::SCOPE_PRODUCT,
                    'type' => $type,
                    'discount_value' => $type === CatalogAction::TYPE_PERCENTAGE
                        ? random_int(5, 30)
                        : round((float) $this->faker->randomFloat(2, 0.8, 14), 2),
                    'target_type' => CatalogAction::TARGET_PRODUCT,
                    'audience_type' => $audienceType,
                    'customer_group_id' => $audienceType === CatalogAction::AUDIENCE_USER_GROUP
                        ? $this->randomId($this->customerGroupIds)
                        : null,
                    'user_id' => $audienceType === CatalogAction::AUDIENCE_USER
                        ? $this->randomId($customerUserIds)
                        : null,
                    'coupon_code' => $this->chance(18) ? 'DEMO'.str_pad((string) $seq, 3, '0', STR_PAD_LEFT) : null,
                    'priority' => random_int(10, 90),
                    'is_exclusive' => $this->chance(12),
                    'is_active' => true,
                    'usage_limit' => null,
                    'usage_limit_per_user' => null,
                    'payload' => ['seed' => 'demo'],
                    'created_by' => $this->randomId($this->adminUserIds),
                    'updated_by' => $this->randomId($this->adminUserIds),
                ]
            );

            foreach ($this->locales as $locale) {
                $title = $locale === 'hr'
                    ? 'Demo popust proizvoda '.$seq
                    : 'Demo product discount '.$seq;

                $action->translations()->updateOrCreate(
                    ['locale' => $locale],
                    [
                        'title' => $title,
                        'description' => $this->faker->sentence(14),
                        'badge' => $this->chance(35) ? ($locale === 'hr' ? 'Akcija' : 'Deal') : null,
                        'payload' => null,
                    ]
                );
            }

            $action->targets()->delete();
            foreach (array_values($chunk) as $position => $productId) {
                $action->targets()->create([
                    'target_type' => CatalogAction::TARGET_PRODUCT,
                    'target_id' => (int) $productId,
                    'sort_order' => $position,
                ]);
            }
        }

        for ($i = 1; $i <= 10; $i++) {
            $targetType = $this->faker->randomElement([CatalogAction::TARGET_CATEGORY, CatalogAction::TARGET_MANUFACTURER]);
            $targetId = $targetType === CatalogAction::TARGET_CATEGORY
                ? $this->randomId($categoryIds)
                : $this->randomId($manufacturerIds);

            if (! $targetId) {
                continue;
            }

            $action = CatalogAction::query()->updateOrCreate(
                ['code' => sprintf('demo-scope-action-%02d', $i)],
                [
                    'scope' => $this->chance(40) ? CatalogAction::SCOPE_CART : CatalogAction::SCOPE_PRODUCT,
                    'type' => $this->faker->randomElement([
                        CatalogAction::TYPE_PERCENTAGE,
                        CatalogAction::TYPE_FIXED,
                        CatalogAction::TYPE_BUY_X_GET_Y,
                        CatalogAction::TYPE_GIFT_ON_AMOUNT,
                    ]),
                    'discount_value' => random_int(4, 25),
                    'target_type' => $targetType,
                    'audience_type' => CatalogAction::AUDIENCE_ALL,
                    'min_subtotal' => $this->chance(45) ? random_int(20, 120) : null,
                    'buy_qty' => $this->chance(28) ? random_int(2, 4) : null,
                    'get_qty' => $this->chance(28) ? 1 : null,
                    'gift_product_id' => $this->chance(25) ? $this->randomId($productIds) : null,
                    'priority' => random_int(5, 60),
                    'is_exclusive' => false,
                    'is_active' => true,
                    'payload' => ['seed' => 'demo'],
                    'created_by' => $this->randomId($this->adminUserIds),
                    'updated_by' => $this->randomId($this->adminUserIds),
                ]
            );

            foreach ($this->locales as $locale) {
                $action->translations()->updateOrCreate(
                    ['locale' => $locale],
                    [
                        'title' => ($locale === 'hr' ? 'Demo akcija' : 'Demo action').' '.$i,
                        'description' => $this->faker->sentence(16),
                        'badge' => null,
                        'payload' => null,
                    ]
                );
            }

            $action->targets()->delete();
            $action->targets()->create([
                'target_type' => $targetType,
                'target_id' => (int) $targetId,
                'sort_order' => 0,
            ]);
        }
    }

    /**
     * @param  array<int, int>  $blogCategoryIds
     * @return array<int, int>
     */
    private function seedBlogPosts(array $blogCategoryIds): array
    {
        $existingCount = (int) BlogPost::query()->count();
        $toCreate = max(0, self::TARGET_BLOG_POSTS - $existingCount);
        $nextIndex = $this->nextIndexByPattern(
            values: BlogPost::query()->where('code', 'like', 'demo-blog-%')->pluck('code')->all(),
            pattern: '/^demo\-blog\-(\d{4})$/'
        );

        for ($i = 0; $i < $toCreate; $i++) {
            $seq = $nextIndex + $i;
            $code = sprintf('demo-blog-%04d', $seq);
            $creator = $this->randomId($this->adminUserIds);

            $post = BlogPost::query()->updateOrCreate(
                ['code' => $code],
                [
                    'is_active' => true,
                    'is_featured' => $this->chance(14),
                    'published_at' => now()->subDays(random_int(0, 365)),
                    'sort_order' => $seq,
                    'payload' => ['seed' => 'demo'],
                    'created_by' => $creator,
                    'updated_by' => $creator,
                ]
            );

            foreach ($this->locales as $locale) {
                $title = $locale === 'hr'
                    ? 'Demo blog objava '.$seq
                    : 'Demo blog post '.$seq;
                $excerpt = $locale === 'hr'
                    ? 'Kratki opis blog objave '.$seq.'.'
                    : 'Short excerpt for blog post '.$seq.'.';

                $post->translations()->updateOrCreate(
                    ['locale' => $locale],
                    [
                        'title' => $title,
                        'slug' => $code.'-'.$locale,
                        'excerpt' => $excerpt,
                        'body_html' => '<p>'.$excerpt.'</p><p>'.$this->faker->paragraphs(3, true).'</p>',
                        'meta_title' => $title,
                        'meta_description' => $excerpt,
                        'payload' => null,
                    ]
                );
            }

            if ($blogCategoryIds !== []) {
                $count = random_int(1, min(2, count($blogCategoryIds)));
                $picked = $this->pickMany($blogCategoryIds, $count);
                $syncPayload = [];
                foreach (array_values($picked) as $pos => $categoryId) {
                    $syncPayload[(int) $categoryId] = ['sort_order' => $pos, 'is_primary' => $pos === 0];
                }
                $post->categories()->sync($syncPayload);
            }
        }

        return BlogPost::query()
            ->orderByDesc('id')
            ->limit(self::TARGET_BLOG_POSTS)
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    /**
     * @param  array<int, int>  $pageCategoryIds
     * @return array<int, int>
     */
    private function seedInfoPages(array $pageCategoryIds): array
    {
        $existingCount = (int) InfoPage::query()->count();
        $toCreate = max(0, self::TARGET_INFO_PAGES - $existingCount);
        $nextIndex = $this->nextIndexByPattern(
            values: InfoPage::query()->where('code', 'like', 'demo-page-%')->pluck('code')->all(),
            pattern: '/^demo\-page\-(\d{3})$/'
        );

        for ($i = 0; $i < $toCreate; $i++) {
            $seq = $nextIndex + $i;
            $code = sprintf('demo-page-%03d', $seq);
            $creator = $this->randomId($this->adminUserIds);

            $page = InfoPage::query()->updateOrCreate(
                ['code' => $code],
                [
                    'layout' => $this->chance(20) ? 'legal' : 'default',
                    'is_active' => true,
                    'show_in_footer' => $this->chance(55),
                    'published_at' => now()->subDays(random_int(0, 450)),
                    'sort_order' => $seq,
                    'payload' => ['seed' => 'demo'],
                    'created_by' => $creator,
                    'updated_by' => $creator,
                ]
            );

            foreach ($this->locales as $locale) {
                $title = $locale === 'hr'
                    ? 'Demo info stranica '.$seq
                    : 'Demo info page '.$seq;
                $excerpt = $locale === 'hr'
                    ? 'Informativna stranica '.$seq.'.'
                    : 'Informative page '.$seq.'.';

                $page->translations()->updateOrCreate(
                    ['locale' => $locale],
                    [
                        'title' => $title,
                        'slug' => $code.'-'.$locale,
                        'excerpt' => $excerpt,
                        'body_html' => '<p>'.$excerpt.'</p><p>'.$this->faker->paragraphs(2, true).'</p>',
                        'meta_title' => $title,
                        'meta_description' => $excerpt,
                        'payload' => null,
                    ]
                );
            }

            if ($pageCategoryIds !== []) {
                $count = random_int(0, min(2, count($pageCategoryIds)));
                $picked = $this->pickMany($pageCategoryIds, $count);
                $syncPayload = [];
                foreach (array_values($picked) as $pos => $categoryId) {
                    $syncPayload[(int) $categoryId] = ['sort_order' => $pos, 'is_primary' => $pos === 0];
                }
                $page->categories()->sync($syncPayload);
            }
        }

        return InfoPage::query()
            ->orderByDesc('id')
            ->limit(self::TARGET_INFO_PAGES)
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    /**
     * @return array<int, int>
     */
    private function seedFaqs(): array
    {
        $groupCodes = ['general', 'shipping', 'payments', 'returns', 'orders'];
        $existingCount = (int) Faq::query()->count();
        $toCreate = max(0, self::TARGET_FAQS - $existingCount);
        $nextIndex = $this->nextIndexByPattern(
            values: Faq::query()->where('code', 'like', 'demo-faq-%')->pluck('code')->all(),
            pattern: '/^demo\-faq\-(\d{3})$/'
        );

        for ($i = 0; $i < $toCreate; $i++) {
            $seq = $nextIndex + $i;
            $code = sprintf('demo-faq-%03d', $seq);
            $creator = $this->randomId($this->adminUserIds);

            $faq = Faq::query()->updateOrCreate(
                ['code' => $code],
                [
                    'group_code' => $groupCodes[$seq % count($groupCodes)],
                    'is_active' => true,
                    'is_featured' => $this->chance(24),
                    'sort_order' => $seq * 10,
                    'payload' => ['seed' => 'demo'],
                    'created_by' => $creator,
                    'updated_by' => $creator,
                ]
            );

            foreach ($this->locales as $locale) {
                $question = $locale === 'hr'
                    ? 'Demo pitanje '.$seq.'?'
                    : 'Demo question '.$seq.'?';
                $answer = $locale === 'hr'
                    ? '<p>Ovo je demo odgovor za pitanje '.$seq.'.</p>'
                    : '<p>This is a demo answer for question '.$seq.'.</p>';

                $faq->translations()->updateOrCreate(
                    ['locale' => $locale],
                    [
                        'question' => $question,
                        'slug' => $code.'-'.$locale,
                        'answer_html' => $answer,
                        'meta_title' => $question,
                        'meta_description' => strip_tags($answer),
                        'payload' => null,
                    ]
                );
            }
        }

        return Faq::query()
            ->orderByDesc('id')
            ->limit(self::TARGET_FAQS)
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    /**
     * @param  array<string, array<int, string>>  $categoryCodesByScope
     * @param  array<int, string>  $productCodes
     * @param  array<int, string>  $blogCodes
     * @param  array<int, string>  $pageCodes
     */
    private function seedContentBlocksAndSlots(
        array $categoryCodesByScope,
        array $productCodes,
        array $blogCodes,
        array $pageCodes
    ): void {
        /** @var array<string, string> $typeMap */
        $typeMap = config('content_blocks.types', []);
        $types = array_keys($typeMap);
        if ($types === []) {
            $types = ['custom'];
        }

        $existingCount = (int) ContentBlock::query()->count();
        $toCreate = max(0, self::TARGET_CONTENT_BLOCKS - $existingCount);
        $nextIndex = $this->nextIndexByPattern(
            values: ContentBlock::query()->where('code', 'like', 'demo-block-%')->pluck('code')->all(),
            pattern: '/^demo\-block\-(\d{3})$/'
        );

        for ($i = 0; $i < $toCreate; $i++) {
            $seq = $nextIndex + $i;
            $code = sprintf('demo-block-%03d', $seq);
            $type = $types[array_rand($types)];

            $block = ContentBlock::query()->updateOrCreate(
                ['code' => $code],
                [
                    'name' => 'Demo Block '.$seq,
                    'type' => $type,
                    'is_active' => true,
                    'payload' => $this->defaultBlockPayload($type),
                    'created_by' => $this->randomId($this->adminUserIds),
                    'updated_by' => $this->randomId($this->adminUserIds),
                ]
            );

            foreach ($this->locales as $locale) {
                $title = $locale === 'hr' ? 'Demo blok '.$seq : 'Demo block '.$seq;
                $subtitle = $locale === 'hr'
                    ? 'Podesivi demo sadrzaj za slotove.'
                    : 'Configurable demo content for slots.';

                $block->translations()->updateOrCreate(
                    ['locale' => $locale],
                    [
                        'title' => $title,
                        'subtitle' => $subtitle,
                        'body_html' => '<p>'.$subtitle.'</p>',
                        'cta_label' => $locale === 'hr' ? 'Saznaj vise' : 'Learn more',
                        'cta_url' => '/demo',
                        'payload' => null,
                    ]
                );
            }
        }

        /** @var array<string, string> $placementMap */
        $placementMap = config('content_blocks.placements', []);
        $placements = array_keys($placementMap);
        if ($placements === []) {
            $placements = ['home.hero', 'home.bottom'];
        }

        $allBlocks = ContentBlock::query()->orderBy('id')->pluck('id')->map(fn ($id): int => (int) $id)->all();
        if ($allBlocks === []) {
            return;
        }

        $slotMissing = max(0, self::TARGET_CONTENT_SLOTS - (int) ContentBlockSlot::query()->count());
        for ($i = 0; $i < $slotMissing; $i++) {
            $targetType = $this->faker->randomElement([null, null, 'category', 'product', 'blog_post', 'page']);
            $targetRef = null;
            if ($targetType === 'category') {
                $scope = $this->faker->randomElement([Category::SCOPE_CATALOG, Category::SCOPE_BLOG, Category::SCOPE_PAGE]);
                $targetRef = $this->randomValue($categoryCodesByScope[$scope] ?? []);
            } elseif ($targetType === 'product') {
                $targetRef = $this->randomValue($productCodes);
            } elseif ($targetType === 'blog_post') {
                $targetRef = $this->randomValue($blogCodes);
            } elseif ($targetType === 'page') {
                $targetRef = $this->randomValue($pageCodes);
            }

            ContentBlockSlot::query()->create([
                'content_block_id' => $this->randomId($allBlocks),
                'placement' => $this->randomValue($placements) ?? 'home.hero',
                'target_type' => $targetType,
                'target_ref' => $targetRef,
                'sort_order' => random_int(0, 120),
                'is_active' => true,
                'starts_at' => $this->chance(12) ? now()->subDays(random_int(1, 30)) : null,
                'ends_at' => $this->chance(12) ? now()->addDays(random_int(10, 90)) : null,
                'created_by' => $this->randomId($this->adminUserIds),
                'updated_by' => $this->randomId($this->adminUserIds),
            ]);
        }
    }

    /**
     * @param  array<int, int>  $productIds
     * @param  array<int, int>  $customerUserIds
     */
    private function seedOrders(array $productIds, array $customerUserIds): void
    {
        if ($productIds === []) {
            return;
        }

        $existingCount = (int) Order::query()->count();
        $toCreate = max(0, self::TARGET_ORDERS - $existingCount);
        if ($toCreate === 0) {
            return;
        }

        $statusRows = OrderStatus::query()->where('is_active', true)->orderBy('sort_order')->get();
        if ($statusRows->isEmpty()) {
            return;
        }

        $defaultStatus = $statusRows->firstWhere('is_default', true) ?? $statusRows->first();
        $paidStatus = $statusRows->firstWhere('is_paid', true) ?? $defaultStatus;
        $sentStatus = $statusRows->firstWhere('code', 'sent') ?? $paidStatus;
        $cancelledStatus = $statusRows->firstWhere('is_cancelled', true) ?? $defaultStatus;

        $paymentMethods = PaymentMethod::query()->where('is_active', true)->orderBy('sort_order')->get(['code', 'name']);
        $shippingMethods = ShippingMethod::query()->where('is_active', true)->orderBy('sort_order')->get(['code', 'name', 'price', 'free_over']);

        $userPool = User::query()
            ->with(['profile', 'addresses'])
            ->whereIn('id', $customerUserIds !== [] ? $customerUserIds : User::query()->pluck('id'))
            ->get()
            ->values();

        if ($userPool->isEmpty()) {
            return;
        }

        $products = Product::query()
            ->with(['translations' => fn ($query) => $query->where('locale', $this->primaryLocale())])
            ->whereIn('id', $productIds)
            ->get(['id', 'code', 'sku', 'base_price'])
            ->values();

        if ($products->isEmpty()) {
            return;
        }

        $nextIndex = $this->nextIndexByPattern(
            values: Order::query()->where('order_number', 'like', 'AGD-2026-%')->pluck('order_number')->all(),
            pattern: '/^AGD\-2026\-(\d{6})$/'
        );

        for ($i = 0; $i < $toCreate; $i++) {
            $seq = $nextIndex + $i;
            $orderNumber = sprintf('AGD-2026-%06d', $seq);
            $createdBy = $this->randomId($this->adminUserIds);

            /** @var User $user */
            $user = $userPool->random();
            $status = $this->pickOrderStatus($defaultStatus, $paidStatus, $sentStatus, $cancelledStatus);

            $itemCount = random_int(1, 4);
            $pickedProducts = $products->shuffle()->take($itemCount);
            $itemRows = [];
            $subtotal = 0.0;
            $itemQty = 0;

            foreach ($pickedProducts as $pos => $product) {
                $quantity = random_int(1, 3);
                $unitPrice = round(((float) $product->base_price) * $this->faker->randomFloat(4, 0.92, 1.18), 2);
                $lineTotal = round($unitPrice * $quantity, 2);
                $name = $product->translations->first()?->name ?? ('Product '.$product->id);

                $subtotal += $lineTotal;
                $itemQty += $quantity;

                $itemRows[] = [
                    'product_id' => $product->id,
                    'product_option_value_id' => null,
                    'sku' => $product->sku,
                    'code' => $product->code,
                    'name' => $name,
                    'unit_price' => $unitPrice,
                    'discount_amount' => 0,
                    'tax_rate' => 0,
                    'tax_amount' => 0,
                    'quantity' => $quantity,
                    'line_total' => $lineTotal,
                    'sort_order' => $pos,
                    'payload' => ['seed' => 'demo'],
                ];
            }

            $subtotal = round($subtotal, 2);
            $discountTotal = $this->chance(28) ? round($subtotal * $this->faker->randomFloat(4, 0.03, 0.17), 2) : 0.0;
            $paymentFeeTotal = 0.0;

            $shipping = $shippingMethods->isNotEmpty() ? $shippingMethods->random() : null;
            $shippingTotal = 0.0;
            if ($shipping) {
                $shippingTotal = (float) $shipping->price;
                $freeOver = $shipping->free_over !== null ? (float) $shipping->free_over : null;
                if ($freeOver !== null && ($subtotal - $discountTotal) >= $freeOver) {
                    $shippingTotal = 0.0;
                }
            }

            $taxBase = max(0.0, $subtotal - $discountTotal);
            $taxTotal = round($taxBase * 0.25, 2);
            $grandTotal = round($subtotal + $shippingTotal + $paymentFeeTotal + $taxTotal - $discountTotal, 2);

            $placedAt = now()->subDays(random_int(0, 240))->subMinutes(random_int(0, 1440));
            $paidAt = $status->is_paid ? (clone $placedAt)->addMinutes(random_int(15, 720)) : null;

            $billing = $user->addresses->firstWhere('type', UserAddress::TYPE_BILLING);
            $shippingAddress = $user->addresses->firstWhere('type', UserAddress::TYPE_SHIPPING);
            $profile = $user->profile;

            $payment = $paymentMethods->isNotEmpty() ? $paymentMethods->random() : null;

            $order = Order::query()->create([
                'order_number' => $orderNumber,
                'status_id' => $status->id,
                'user_id' => $user->id,
                'source' => 'web',
                'locale' => $this->primaryLocale(),
                'currency_code' => 'EUR',
                'currency_rate' => 1,
                'customer_name' => $user->name,
                'customer_email' => $user->email,
                'customer_phone' => $profile?->phone,
                'billing_first_name' => $billing?->first_name ?? $profile?->first_name,
                'billing_last_name' => $billing?->last_name ?? $profile?->last_name,
                'billing_company' => $billing?->company ?? $profile?->company,
                'billing_oib' => $billing?->oib ?? $profile?->oib,
                'billing_address_line_1' => $billing?->address_line_1,
                'billing_address_line_2' => $billing?->address_line_2,
                'billing_postal_code' => $billing?->postal_code,
                'billing_city' => $billing?->city,
                'billing_country_code' => $billing?->country_code ?? 'HR',
                'shipping_first_name' => $shippingAddress?->first_name ?? $billing?->first_name,
                'shipping_last_name' => $shippingAddress?->last_name ?? $billing?->last_name,
                'shipping_company' => $shippingAddress?->company,
                'shipping_address_line_1' => $shippingAddress?->address_line_1 ?? $billing?->address_line_1,
                'shipping_address_line_2' => $shippingAddress?->address_line_2 ?? $billing?->address_line_2,
                'shipping_postal_code' => $shippingAddress?->postal_code ?? $billing?->postal_code,
                'shipping_city' => $shippingAddress?->city ?? $billing?->city,
                'shipping_country_code' => $shippingAddress?->country_code ?? 'HR',
                'payment_method_code' => $payment?->code,
                'payment_method_name' => $payment?->name,
                'shipping_method_code' => $shipping?->code,
                'shipping_method_name' => $shipping?->name,
                'item_qty' => $itemQty,
                'subtotal' => $subtotal,
                'shipping_total' => $shippingTotal,
                'payment_fee_total' => $paymentFeeTotal,
                'discount_total' => $discountTotal,
                'tax_total' => $taxTotal,
                'grand_total' => $grandTotal,
                'customer_note' => null,
                'admin_note' => 'Demo seeded order',
                'payload' => ['seed' => 'demo'],
                'placed_at' => $placedAt,
                'paid_at' => $paidAt,
                'created_by' => $createdBy,
                'updated_by' => $createdBy,
            ]);

            $order->items()->createMany($itemRows);
            $order->totals()->createMany([
                ['code' => 'subtotal', 'title' => 'Subtotal', 'value' => $subtotal, 'sort_order' => 10, 'payload' => null],
                ['code' => 'discount', 'title' => 'Discount', 'value' => -1 * abs($discountTotal), 'sort_order' => 20, 'payload' => null],
                ['code' => 'shipping', 'title' => 'Shipping', 'value' => $shippingTotal, 'sort_order' => 30, 'payload' => null],
                ['code' => 'tax', 'title' => 'Tax', 'value' => $taxTotal, 'sort_order' => 40, 'payload' => null],
                ['code' => 'total', 'title' => 'Total', 'value' => $grandTotal, 'sort_order' => 50, 'payload' => null],
            ]);

            $order->history()->create([
                'from_status_id' => null,
                'to_status_id' => $status->id,
                'changed_by' => $createdBy,
                'comment' => 'Initial seeded status.',
                'payload' => ['seed' => 'demo'],
            ]);

            if ($status->is_paid && $this->chance(75)) {
                $order->transactions()->create([
                    'provider' => 'manual',
                    'transaction_ref' => 'DEMO-TX-'.$orderNumber,
                    'status' => 'confirmed',
                    'amount' => $grandTotal,
                    'currency_code' => 'EUR',
                    'processed_at' => $paidAt ?? now(),
                    'payload' => ['seed' => 'demo'],
                    'created_by' => $createdBy,
                ]);
            }
        }
    }

    /**
     * @param  array<int, int>  $productIds
     * @param  array<int, int>  $blogIds
     * @param  array<int, int>  $pageIds
     * @param  array<int, int>  $faqIds
     * @param  array<int, int>  $customerUserIds
     */
    private function seedComments(
        array $productIds,
        array $blogIds,
        array $pageIds,
        array $faqIds,
        array $customerUserIds
    ): void {
        $toCreate = max(0, self::TARGET_COMMENTS - (int) Comment::query()->count());
        if ($toCreate === 0) {
            return;
        }

        $adminId = $this->randomId($this->adminUserIds);
        $typePool = [];
        if ($productIds !== []) {
            $typePool[] = ['type' => Product::class, 'ids' => $productIds];
        }
        if ($blogIds !== []) {
            $typePool[] = ['type' => BlogPost::class, 'ids' => $blogIds];
        }
        if ($pageIds !== []) {
            $typePool[] = ['type' => InfoPage::class, 'ids' => $pageIds];
        }
        if ($faqIds !== []) {
            $typePool[] = ['type' => Faq::class, 'ids' => $faqIds];
        }

        if ($typePool === []) {
            return;
        }

        for ($i = 0; $i < $toCreate; $i++) {
            $target = $typePool[array_rand($typePool)];
            $commentableId = $this->randomId($target['ids']);
            if (! $commentableId) {
                continue;
            }

            $status = $this->faker->randomElement([
                Comment::STATUS_PENDING,
                Comment::STATUS_APPROVED,
                Comment::STATUS_APPROVED,
                Comment::STATUS_REJECTED,
                Comment::STATUS_SPAM,
            ]);

            $userId = $this->randomId($customerUserIds);
            $user = $userId ? User::query()->find($userId) : null;
            $isReviewType = in_array($target['type'], [Product::class, BlogPost::class], true);

            Comment::query()->create([
                'commentable_type' => $target['type'],
                'commentable_id' => (int) $commentableId,
                'user_id' => $user?->id,
                'parent_id' => null,
                'author_name' => $user?->name ?? $this->faker->name(),
                'author_email' => $user?->email ?? $this->faker->unique()->safeEmail(),
                'locale' => $this->randomValue($this->locales) ?? $this->primaryLocale(),
                'body' => $this->faker->sentence(18),
                'rating' => $isReviewType && $this->chance(72) ? random_int(3, 5) : null,
                'status' => $status,
                'is_featured' => $status === Comment::STATUS_APPROVED && $this->chance(12),
                'reviewed_by' => $status === Comment::STATUS_PENDING ? null : $adminId,
                'reviewed_at' => $status === Comment::STATUS_PENDING ? null : now()->subMinutes(random_int(1, 20000)),
                'payload' => ['seed' => 'demo'],
            ]);
        }
    }

    /**
     * @param  array<int, int>  $customerUserIds
     * @param  array<int, int>  $productIds
     * @param  array<int, int>  $blogIds
     * @param  array<int, int>  $pageIds
     */
    private function seedUserTracking(
        array $customerUserIds,
        array $productIds,
        array $blogIds,
        array $pageIds
    ): void {
        $toCreate = max(0, self::TARGET_USER_TRACKING_EVENTS - (int) UserTrackingEvent::query()->count());
        if ($toCreate === 0 || $customerUserIds === []) {
            return;
        }

        $batch = [];
        $now = now();
        for ($i = 0; $i < $toCreate; $i++) {
            $event = $this->faker->randomElement(['page_view', 'product_view', 'search', 'add_to_cart', 'wishlist']);
            $subjectType = null;
            $subjectId = null;
            $url = '/';

            if ($event === 'product_view' || $event === 'add_to_cart' || $event === 'wishlist') {
                $subjectType = Product::class;
                $subjectId = $this->randomId($productIds);
                $url = '/products/'.($subjectId ?? '');
            } elseif ($event === 'page_view') {
                if ($this->chance(60) && $blogIds !== []) {
                    $subjectType = BlogPost::class;
                    $subjectId = $this->randomId($blogIds);
                    $url = '/blog/'.($subjectId ?? '');
                } elseif ($pageIds !== []) {
                    $subjectType = InfoPage::class;
                    $subjectId = $this->randomId($pageIds);
                    $url = '/pages/'.($subjectId ?? '');
                }
            } elseif ($event === 'search') {
                $url = '/search?q='.urlencode($this->faker->word());
            }

            $occurredAt = (clone $now)->subMinutes(random_int(0, 60000));

            $batch[] = [
                'user_id' => $this->randomId($customerUserIds),
                'session_id' => 'sess_'.Str::lower(Str::random(22)),
                'event' => $event,
                'subject_type' => $subjectType,
                'subject_id' => $subjectId,
                'url' => $url,
                'referrer' => $this->chance(45) ? 'https://google.com' : null,
                'ip_address' => $this->faker->ipv4(),
                'user_agent' => $this->faker->userAgent(),
                'payload' => json_encode(['seed' => 'demo'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'occurred_at' => $occurredAt,
                'created_at' => $occurredAt,
                'updated_at' => $occurredAt,
            ];

            if (count($batch) >= 600) {
                UserTrackingEvent::query()->insert($batch);
                $batch = [];
            }
        }

        if ($batch !== []) {
            UserTrackingEvent::query()->insert($batch);
        }
    }

    private function seedLoyaltyTransactions(): void
    {
        $pointsPerCurrency = (float) app(SystemSettingsService::class)->get(
            'loyalty_points_per_currency',
            (float) config('user_features.loyalty.points_per_currency', 1.0)
        );

        $eligibleOrders = Order::query()
            ->whereNotNull('user_id')
            ->whereHas('status', function ($query): void {
                $query->where('is_paid', true)->where('is_cancelled', false);
            })
            ->orderByDesc('id')
            ->limit(1400)
            ->get(['id', 'user_id', 'grand_total']);

        foreach ($eligibleOrders as $order) {
            $eventKey = 'demo-loyalty-settlement-'.$order->id;
            $points = max(1, (int) round(((float) $order->grand_total) * $pointsPerCurrency));

            LoyaltyTransaction::query()->updateOrCreate(
                ['event_key' => $eventKey],
                [
                    'user_id' => (int) $order->user_id,
                    'order_id' => (int) $order->id,
                    'type' => 'order_settlement',
                    'points' => $points,
                    'note' => 'Demo seeded loyalty settlement.',
                    'payload' => ['seed' => 'demo'],
                    'created_by' => $this->randomId($this->adminUserIds),
                ]
            );
        }
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function categoryCodesByScope(): array
    {
        $map = [];
        foreach ([Category::SCOPE_CATALOG, Category::SCOPE_BLOG, Category::SCOPE_PAGE] as $scope) {
            $map[$scope] = Category::query()
                ->where('scope', $scope)
                ->orderBy('id')
                ->pluck('code')
                ->map(fn ($code): string => (string) $code)
                ->all();
        }

        return $map;
    }

    /**
     * @return array<int, string>
     */
    private function codesForModel(string $modelClass, string $column, int $limit): array
    {
        return $modelClass::query()
            ->orderByDesc('id')
            ->limit($limit)
            ->pluck($column)
            ->map(fn ($value): string => (string) $value)
            ->all();
    }

    /**
     * @return array<int, string>
     */
    private function resolveLocales(): array
    {
        $locales = Language::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->pluck('code')
            ->map(fn ($code): string => (string) $code)
            ->all();

        if ($locales === []) {
            return ['hr', 'en'];
        }

        return $locales;
    }

    private function enableAllFeatureFlags(): void
    {
        app(SystemSettingsService::class)->putMany([
            'catalog_use_api' => true,
            'catalog_use_blog' => true,
            'catalog_use_attributes' => true,
            'catalog_use_options' => true,
            'catalog_use_manufacturers' => true,
            'catalog_use_actions' => true,
            'user_tracking_enabled' => true,
            'user_loyalty_enabled' => false,
        ]);
    }

    private function categoryName(string $scope, int $seq, string $locale): string
    {
        if ($locale === 'hr') {
            return match ($scope) {
                Category::SCOPE_BLOG => 'Blog kategorija '.$seq,
                Category::SCOPE_PAGE => 'Info kategorija '.$seq,
                default => 'Kategorija proizvoda '.$seq,
            };
        }

        return match ($scope) {
            Category::SCOPE_BLOG => 'Blog category '.$seq,
            Category::SCOPE_PAGE => 'Info category '.$seq,
            default => 'Product category '.$seq,
        };
    }

    /**
     * @param  array<int, string>  $values
     */
    private function nextIndexByPattern(array $values, string $pattern): int
    {
        $max = 0;
        foreach ($values as $value) {
            if (preg_match($pattern, (string) $value, $matches) !== 1) {
                continue;
            }
            $max = max($max, (int) ($matches[1] ?? 0));
        }

        return $max + 1;
    }

    /**
     * @template T
     *
     * @param  array<int, T>  $items
     * @return array<int, T>
     */
    private function pickMany(array $items, int $count): array
    {
        $count = max(0, min($count, count($items)));
        if ($count === 0) {
            return [];
        }
        if ($count >= count($items)) {
            return array_values($items);
        }

        $keys = array_rand($items, $count);
        $keys = is_array($keys) ? $keys : [$keys];
        $picked = [];
        foreach ($keys as $key) {
            $picked[] = $items[(int) $key];
        }

        return $picked;
    }

    /**
     * @param  array<int, int>  $ids
     */
    private function randomId(array $ids): ?int
    {
        if ($ids === []) {
            return null;
        }

        return (int) $ids[array_rand($ids)];
    }

    /**
     * @template T
     *
     * @param  array<int, T>  $values
     * @return T|null
     */
    private function randomValue(array $values): mixed
    {
        if ($values === []) {
            return null;
        }

        return $values[array_rand($values)];
    }

    private function chance(int $percent): bool
    {
        return random_int(1, 100) <= max(0, min(100, $percent));
    }

    private function primaryLocale(): string
    {
        return $this->locales[0] ?? 'hr';
    }

    private function pickOrderStatus(OrderStatus $default, OrderStatus $paid, OrderStatus $sent, OrderStatus $cancelled): OrderStatus
    {
        $roll = random_int(1, 100);

        if ($roll <= 36) {
            return $default;
        }

        if ($roll <= 70) {
            return $paid;
        }

        if ($roll <= 94) {
            return $sent;
        }

        return $cancelled;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function defaultBlockPayload(string $type): ?array
    {
        return match ($type) {
            'products_carousel' => [
                'source' => 'query',
                'limit' => 10,
                'sort' => 'newest',
                'category_ids' => [],
                'manufacturer_ids' => [],
            ],
            'blog_grid_3' => [
                'source' => 'query',
                'limit' => 3,
                'sort' => 'newest',
                'category_ids' => [],
            ],
            'hero_slider' => [
                'slides' => [
                    ['title' => 'Demo Slide 1', 'subtitle' => 'Demo subtitle', 'url' => '/shop', 'label' => 'Shop now'],
                    ['title' => 'Demo Slide 2', 'subtitle' => 'Demo subtitle', 'url' => '/shop', 'label' => 'Explore'],
                    ['title' => 'Demo Slide 3', 'subtitle' => 'Demo subtitle', 'url' => '/shop', 'label' => 'Discover'],
                ],
            ],
            'cards_2', 'cards_3' => [
                'cards' => [
                    ['icon' => 'sparkles', 'title' => 'Demo Card 1'],
                    ['icon' => 'shield', 'title' => 'Demo Card 2'],
                    ['icon' => 'clock', 'title' => 'Demo Card 3'],
                ],
            ],
            default => ['seed' => 'demo'],
        };
    }

    private function note(string $message): void
    {
        if ($this->command) {
            $this->command->getOutput()->writeln('<info>'.$message.'</info>');
        }
    }
}
