<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('account_type', 20)->default('customer');
            $table->string('admin_username')->nullable()->unique();
            $table->boolean('admin_login_enabled')->default(true);
            $table->dropUnique('users_email_unique');
            $table->unique(['email', 'account_type']);
        });

        DB::table('users')->whereIn('id', function ($query): void {
            $query->select('assigned_roles.entity_id')
                ->from('assigned_roles')
                ->join('roles', 'roles.id', '=', 'assigned_roles.role_id')
                ->where('assigned_roles.entity_type', App\Models\User::class)
                ->whereIn('roles.name', ['superadmin', 'super-admin', 'admin', 'editor']);
        })->update(['account_type' => 'staff']);
    }

    public function down(): void
    {
        if (DB::table('users')->select('email')->groupBy('email')->havingRaw('COUNT(*) > 1')->exists()) {
            throw new RuntimeException('Cannot remove staff identities while customer and staff accounts share an email.');
        }

        Schema::table('users', function (Blueprint $table): void {
            $table->dropUnique('users_email_account_type_unique');
            $table->dropUnique('users_admin_username_unique');
            $table->dropColumn(['account_type', 'admin_username', 'admin_login_enabled']);
            $table->unique('email');
        });
    }
};
