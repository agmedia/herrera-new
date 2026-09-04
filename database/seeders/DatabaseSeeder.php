<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            RoleSeeder::class,
            RoleAbilitySeeder::class,
            CustomerGroupSeeder::class,
            UserSedder::class,
            SettingsLocalSeeder::class,
            SystemSettingsSeeder::class,
            CategorySeeder::class,
            BlogPostSeeder::class,
            InfoPageSeeder::class,
            FaqSeeder::class,
            ContentBlockSeeder::class,
            ContentBlockSlotSeeder::class,
            ProductSeeder::class,
            ManufacturerSeeder::class,
            CatalogActionSeeder::class,
            AttributeSeeder::class,
            OptionSeeder::class,
            ProductOptionValueSeeder::class,
            OrderSeeder::class,
            CommentSeeder::class,
            MediaDemoSeeder::class,
        ]);

        if ($this->shouldRunDummySeeder()) {
            $this->call([
                DummyWebshopSeeder::class,
            ]);
        }
    }

    private function shouldRunDummySeeder(): bool
    {
        if (app()->runningUnitTests()) {
            return false;
        }

        $envFlag = filter_var((string) env('SEED_DUMMY_DATA', 'false'), FILTER_VALIDATE_BOOL);
        if ($envFlag === true) {
            return true;
        }

        if (! $this->command) {
            return false;
        }

        $noInteraction = method_exists($this->command, 'option')
            ? (bool) $this->command->option('no-interaction')
            : false;

        if ($noInteraction) {
            return false;
        }

        return (bool) $this->command->confirm(
            'Seed large dummy webshop dataset (100 categories, 1000 products, 3000 orders, 500 users, etc.)?',
            false
        );
    }
}
