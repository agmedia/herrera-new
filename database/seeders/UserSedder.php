<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\User\CustomerGroup;
use App\Models\User\UserAddress;
use App\Models\User\UserProfile;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Silber\Bouncer\BouncerFacade as Bouncer;
use Silber\Bouncer\Database\Role;

class UserSedder extends Seeder
{
    /**
     * Seed template admin users from legacy shops.
     */
    public function run(): void
    {
        $filip = User::updateOrCreate(
            ['email' => 'filip@agmedia.hr'],
            [
                'name' => 'Filip Jankoski',
                'password' => 'majamaja001',
                'email_verified_at' => now(),
            ]
        );

        $tomislav = User::updateOrCreate(
            ['email' => 'tomislav@agmedia.hr'],
            [
                'name' => 'Tomislav Juresa',
                'password' => 'bakanal',
                'email_verified_at' => now(),
            ]
        );

        $superadminRoleId = (int) Role::query()->where('name', 'superadmin')->value('id');
        $adminRoleId = (int) Role::query()->where('name', 'admin')->value('id');
        $editorRoleId = (int) Role::query()->where('name', 'editor')->value('id');
        $customerRoleId = (int) Role::query()->where('name', 'customer')->value('id');

        $adminUser = User::updateOrCreate(
            ['email' => 'admin@agshop.local'],
            [
                'name' => 'admin',
                'password' => 'admin',
                'email_verified_at' => now(),
            ]
        );
        if ($adminRoleId > 0) {
            $adminUser->roles()->sync([$adminRoleId]);
        } else {
            Bouncer::assign('admin')->to($adminUser);
        }

        $editorUser = User::updateOrCreate(
            ['email' => 'editor@agshop.local'],
            [
                'name' => 'editor',
                'password' => 'editor',
                'email_verified_at' => now(),
            ]
        );
        if ($editorRoleId > 0) {
            $editorUser->roles()->sync([$editorRoleId]);
        } else {
            Bouncer::assign('editor')->to($editorUser);
        }

        $customerUser = User::updateOrCreate(
            ['email' => 'customer@agshop.local'],
            [
                'name' => 'customer',
                'password' => 'customer',
                'email_verified_at' => now(),
            ]
        );
        if ($customerRoleId > 0) {
            $customerUser->roles()->sync([$customerRoleId]);
        } else {
            Bouncer::assign('customer')->to($customerUser);
        }

        if ($superadminRoleId > 0) {
            $filip->roles()->sync([$superadminRoleId]);
            $tomislav->roles()->sync([$superadminRoleId]);
        } else {
            Bouncer::assign('superadmin')->to($filip);
            Bouncer::assign('superadmin')->to($tomislav);
        }

        if (Schema::hasTable('user_profiles')) {
            UserProfile::query()->updateOrCreate(
                ['user_id' => $filip->id],
                [
                    'first_name' => 'Filip',
                    'last_name' => 'Jankoski',
                    'phone' => '+38591222333',
                    'company' => 'Agmedia d.o.o.',
                    'oib' => '12345678901',
                    'gender' => 'male',
                    'newsletter_opt_in' => true,
                    'bio' => 'Founder account.',
                ]
            );

            UserProfile::query()->updateOrCreate(
                ['user_id' => $tomislav->id],
                [
                    'first_name' => 'Tomislav',
                    'last_name' => 'Juresa',
                    'phone' => '+38598111222',
                    'company' => 'Agmedia d.o.o.',
                    'oib' => '10987654321',
                    'gender' => 'male',
                    'newsletter_opt_in' => true,
                    'bio' => 'Administrator account.',
                ]
            );
        }

        if (Schema::hasTable('user_addresses')) {
            UserAddress::query()->updateOrCreate(
                ['user_id' => $filip->id, 'type' => UserAddress::TYPE_BILLING],
                [
                    'first_name' => 'Filip',
                    'last_name' => 'Jankoski',
                    'company' => 'Agmedia d.o.o.',
                    'oib' => '12345678901',
                    'address_line_1' => 'Kovacica 23',
                    'postal_code' => '44320',
                    'city' => 'Kutina',
                    'country_code' => 'HR',
                    'is_default' => true,
                ]
            );

            UserAddress::query()->updateOrCreate(
                ['user_id' => $filip->id, 'type' => UserAddress::TYPE_SHIPPING],
                [
                    'first_name' => 'Filip',
                    'last_name' => 'Jankoski',
                    'address_line_1' => 'Kovacica 23',
                    'postal_code' => '44320',
                    'city' => 'Kutina',
                    'country_code' => 'HR',
                    'is_default' => true,
                ]
            );

            UserAddress::query()->updateOrCreate(
                ['user_id' => $tomislav->id, 'type' => UserAddress::TYPE_BILLING],
                [
                    'first_name' => 'Tomislav',
                    'last_name' => 'Juresa',
                    'company' => 'Agmedia d.o.o.',
                    'oib' => '10987654321',
                    'address_line_1' => 'Malesnica bb',
                    'postal_code' => '10000',
                    'city' => 'Zagreb',
                    'country_code' => 'HR',
                    'is_default' => true,
                ]
            );

            UserAddress::query()->updateOrCreate(
                ['user_id' => $tomislav->id, 'type' => UserAddress::TYPE_SHIPPING],
                [
                    'first_name' => 'Tomislav',
                    'last_name' => 'Juresa',
                    'address_line_1' => 'Malesnica bb',
                    'postal_code' => '10000',
                    'city' => 'Zagreb',
                    'country_code' => 'HR',
                    'is_default' => true,
                ]
            );
        }

        if (Schema::hasTable('customer_groups') && Schema::hasTable('customer_group_user')) {
            $defaultGroupId = CustomerGroup::query()
                ->where('is_default', true)
                ->value('id') ?? CustomerGroup::query()->value('id');

            $b2bGroupId = CustomerGroup::query()
                ->where('code', 'b2b')
                ->value('id');

            if ($defaultGroupId) {
                $filip->customerGroups()->syncWithoutDetaching([$defaultGroupId]);
                $tomislav->customerGroups()->syncWithoutDetaching([$defaultGroupId]);
            }

            if ($b2bGroupId) {
                $tomislav->customerGroups()->syncWithoutDetaching([$b2bGroupId]);
            }
        }

        if (Schema::hasTable('user_details')) {
            DB::table('user_details')->updateOrInsert(
                ['user_id' => $filip->id],
                [
                    'fname' => 'Filip',
                    'lname' => 'Jankoski',
                    'address' => 'Kovacica 23',
                    'zip' => '44320',
                    'city' => 'Kutina',
                    'avatar' => 'media/avatars/avatar0.jpg',
                    'bio' => 'Lorem ipsum...',
                    'social' => '790117367',
                    'role' => 'admin',
                    'status' => 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );

            DB::table('user_details')->updateOrInsert(
                ['user_id' => $tomislav->id],
                [
                    'fname' => 'Tomislav',
                    'lname' => 'Juresa',
                    'address' => 'Malesnica bb',
                    'zip' => '10000',
                    'city' => 'Zagreb',
                    'avatar' => 'media/avatars/avatar0.jpg',
                    'bio' => 'Lorem ipsum...',
                    'social' => '',
                    'role' => 'admin',
                    'status' => 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        }
    }
}
