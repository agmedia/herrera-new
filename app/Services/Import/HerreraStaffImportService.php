<?php

namespace App\Services\Import;

use App\Models\User;
use App\Models\User\LegacyCredential;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;
use Silber\Bouncer\BouncerFacade as Bouncer;

/** Imports staff separately from storefront customers, using SELECTs on the source. */
class HerreraStaffImportService
{
    public function import(ConnectionInterface $source, string $prefix = 'oc_', bool $apply = false): array
    {
        $this->guard($source, $prefix);
        $groups = $source->table($prefix.'user_group')->get()->keyBy('user_group_id');
        $rows = $source->table($prefix.'user')->orderBy('user_id')->get();
        $assignments = $source->table($prefix.'customer_to_user')->get(['user_id', 'customer_id']);
        $customerMap = DB::table('herrera_import_maps')->where('source', 'herrera-opencart')
            ->where('entity', 'customer')->pluck('target_id', 'source_id');
        $staffMap = DB::table('herrera_import_maps')->where('source', 'herrera-opencart')
            ->where('entity', 'admin_user')->pluck('target_id', 'source_id');
        $plan = [];
        $identities = [];
        $destinationIds = [];

        foreach ($rows as $row) {
            $username = Str::lower(trim((string) $row->username));
            $email = Str::lower(trim((string) $row->email));
            foreach ($rows as $other) {
                if ($row->user_id !== $other->user_id && ($username === Str::lower(trim((string) $other->email))
                    || $email === Str::lower(trim((string) $other->username)))) {
                    throw new RuntimeException('A source username conflicts with another staff email.');
                }
            }
        }

        foreach ($rows as $row) {
            $group = $groups->get($row->user_group_id);
            $role = match (strtolower(trim((string) ($group?->name ?? '')))) {
                'administrator', 'admin' => 'admin',
                'order manager' => 'order_manager',
                default => throw new RuntimeException('An unmapped source staff group requires review.'),
            };
            $username = trim((string) $row->username);
            $email = Str::lower(trim((string) $row->email));
            $key = Str::lower($username);
            if ($username === '' || strlen($username) > 255 || ! filter_var($email, FILTER_VALIDATE_EMAIL)
                || isset($identities[$key]) || isset($identities['email:'.$email])) {
                throw new RuntimeException('Source staff identity is missing, invalid or ambiguous.');
            }
            $identities[$key] = $identities['email:'.$email] = true;
            if (! in_array(strlen((string) $row->password), [32, 40], true) || ! ctype_xdigit((string) $row->password)) {
                throw new RuntimeException('An unsupported source staff password requires review.');
            }
            $user = isset($staffMap[$row->user_id]) ? User::find($staffMap[$row->user_id]) : null;
            if (isset($staffMap[$row->user_id]) && (! $user || $user->account_type !== 'staff')) {
                throw new RuntimeException('A staff import mapping points to a missing or customer identity.');
            }
            $byUsername = User::where('admin_username', $username)->first();
            $byEmail = User::where('account_type', 'staff')->whereRaw('LOWER(email) = ?', [$email])->first();
            $candidate = $user ?? $byUsername ?? $byEmail;
            foreach ([$byUsername, $byEmail] as $other) {
                if ($other && $candidate && $other->id !== $candidate->id) {
                    throw new RuntimeException('Conflicting destination staff identities require review.');
                }
            }
            if ($candidate && $candidate->account_type !== 'staff') {
                throw new RuntimeException('Customer accounts cannot be promoted by this importer.');
            }
            if ($candidate && isset($destinationIds[$candidate->id])) {
                throw new RuntimeException('Two source staff records resolve to the same destination account.');
            }
            if ($candidate && ! isset($staffMap[$row->user_id]) && Str::lower($candidate->email) !== $email) {
                throw new RuntimeException('An existing staff username belongs to a different email identity.');
            }
            if ($candidate) {
                $destinationIds[$candidate->id] = true;
            }
            $ambiguousLogin = User::where('account_type', 'staff')->where(function ($query) use ($username, $email): void {
                $query->whereRaw('LOWER(email) = ?', [Str::lower($username)])
                    ->orWhereRaw('LOWER(admin_username) = ?', [$email]);
            })->when($candidate, fn ($query) => $query->whereKeyNot($candidate->id))->exists();
            if ($ambiguousLogin) {
                throw new RuntimeException('A source staff login conflicts with a destination staff identity.');
            }
            if ($candidate && $role === 'order_manager') {
                $allowed = config('admin_acl.roles.order_manager', []);
                if ($candidate->roles()->whereNotIn('name', ['order_manager'])->exists()
                    || $candidate->getAbilities()->contains(fn ($ability) => ! in_array($ability->name, $allowed, true))) {
                    throw new RuntimeException('An existing manager has unreviewed destination permissions.');
                }
            }
            $permissions = json_decode((string) $group->permission, true);
            if (! is_array($permissions) || ! is_array($permissions['access'] ?? null) || ! is_array($permissions['modify'] ?? null)) {
                throw new RuntimeException('Source staff permissions are invalid.');
            }
            // The manager mapping is intentionally explicit: broader custom groups must be reviewed.
            if ($role === 'order_manager') {
                $access = ['customer/custom_field', 'customer/customer', 'localisation/country', 'localisation/currency', 'localisation/geo_zone', 'localisation/language', 'sale/order', 'user/api'];
                $modify = array_values(array_diff($access, ['localisation/geo_zone']));
                foreach (['access' => $access, 'modify' => $modify] as $kind => $expected) {
                    $actual = array_values(array_unique($permissions[$kind]));
                    sort($actual);
                    sort($expected);
                    if ($actual !== $expected) {
                        throw new RuntimeException('Order Manager source permissions differ from the reviewed mapping.');
                    }
                }
            }
            $customerIds = [];
            foreach ($assignments->where('user_id', $row->user_id) as $assignment) {
                $target = $customerMap->get((string) $assignment->customer_id);
                if (! $target || ! User::whereKey($target)->where('account_type', 'customer')->exists()) {
                    throw new RuntimeException('An assigned source customer has no verified destination mapping.');
                }
                $customerIds[] = (int) $target;
            }
            $plan[] = compact('row', 'role', 'username', 'email', 'candidate', 'permissions', 'customerIds');
        }

        $report = ['mode' => $apply ? 'apply' : 'dry-run', 'staff' => count($plan), 'administrators' => 0,
            'order_managers' => 0, 'inactive' => 0, 'assignments' => 0, 'created' => 0, 'existing' => 0];
        foreach ($plan as $item) {
            $report[$item['role'] === 'admin' ? 'administrators' : 'order_managers']++;
            $report['inactive'] += ! (bool) $item['row']->status;
            $report['assignments'] += count(array_unique($item['customerIds']));
            $report[$item['candidate'] ? 'existing' : 'created']++;
        }
        if (! $apply) {
            return $report;
        }

        DB::transaction(function () use ($plan, $source): void {
            $this->ensureRole('admin', 'Administrator', config('admin_acl.roles.admin', []));
            $this->ensureRole('order_manager', 'Order Manager', config('admin_acl.roles.order_manager', []));
            foreach ($plan as $item) {
                ['row' => $row, 'candidate' => $user, 'role' => $role] = $item;
                $user ??= new User;
                $new = ! $user->exists;
                $user->forceFill(['account_type' => 'staff', 'admin_username' => $item['username'],
                    'admin_login_enabled' => (bool) $row->status, 'name' => trim($row->firstname.' '.$row->lastname) ?: $item['username'],
                    'email' => $item['email'], 'email_verified_at' => $user->email_verified_at ?? now()]);
                if ($new) {
                    $user->password = Hash::make(Str::random(80));
                }
                $user->save();
                // Keep existing privileged maintenance accounts and their current passwords intact.
                if ($role === 'order_manager' && ($user->isA('admin') || $user->isA('superadmin') || $user->isA('editor'))) {
                    throw new RuntimeException('An existing privileged account conflicts with the source manager role.');
                }
                Bouncer::assign($role)->to($user);
                if ($role === 'admin' && in_array('user/user_permission', $item['permissions']['modify'], true)) {
                    Bouncer::allow($user)->to('users.access.manage');
                }
                // Existing native maintenance accounts retain their current login password.
                // Never attach an alternative legacy password on an email-only match.
                $legacy = LegacyCredential::where('user_id', $user->id)->first();
                if ($new) {
                    LegacyCredential::create(['user_id' => $user->id, 'legacy_hash' => $row->password,
                        'legacy_salt' => $row->salt, 'enabled' => (bool) $row->status]);
                } elseif ($legacy && ! $legacy->retired_at
                    && hash_equals($legacy->password_fingerprint, hash('sha256', (string) $user->password))) {
                    $legacy->fill(['legacy_hash' => $row->password, 'legacy_salt' => $row->salt,
                        'enabled' => (bool) $row->status])->save();
                }
                $profile = $user->profile()->firstOrNew();
                $payload = (array) ($profile->payload ?? []);
                $payload['legacy_admin'] = ['source_id' => (int) $row->user_id, 'source_group_id' => (int) $row->user_group_id,
                    'username' => $item['username'], 'permissions' => $item['permissions'], 'source_database' => $source->getDatabaseName()];
                $profile->forceFill(['first_name' => $row->firstname, 'last_name' => $row->lastname, 'payload' => $payload])->save();
                DB::table('herrera_import_maps')->updateOrInsert(['source' => 'herrera-opencart', 'entity' => 'admin_user', 'source_id' => (string) $row->user_id],
                    ['target_id' => $user->id, 'checksum' => hash('sha256', json_encode([$item['username'], $item['email'], $row->status, $role, $item['permissions'], $item['customerIds']])), 'created_at' => now(), 'updated_at' => now()]);
                DB::table('admin_customer_assignments')->where('admin_user_id', $user->id)->delete();
                foreach (array_unique($item['customerIds']) as $customerId) {
                    DB::table('admin_customer_assignments')->insert(['admin_user_id' => $user->id, 'customer_user_id' => $customerId, 'created_at' => now(), 'updated_at' => now()]);
                }
            }
        });
        Bouncer::refresh();

        return $report;
    }

    private function ensureRole(string $name, string $title, array $abilities): void
    {
        if ($abilities === []) {
            throw new RuntimeException('The reviewed staff role permissions are not configured.');
        }
        $role = Bouncer::role()->firstOrCreate(['name' => $name], ['title' => $title]);
        if ($name === 'order_manager') {
            $extra = $role->abilities()->whereNotIn('name', $abilities)->exists();
            if ($extra) {
                throw new RuntimeException('The destination Order Manager role has additional permissions requiring review.');
            }
        }
        foreach ($abilities as $ability) {
            Bouncer::allow($role)->to($ability);
        }
    }

    private function guard(ConnectionInterface $source, string $prefix): void
    {
        if (! preg_match('/^[a-zA-Z0-9_]+$/', $prefix) || $source === DB::connection()
            || $source->getDatabaseName() === DB::connection()->getDatabaseName()) {
            throw new RuntimeException('Staff source and destination must be separate verified databases.');
        }
        if (! app()->environment('testing') && ! (
            (app()->environment('local') && DB::connection()->getDatabaseName() === 'herrera_new_migration')
            || (app()->environment('staging') && DB::connection()->getDatabaseName() === 'herrera_redesign'
                && rtrim(config('app.url'), '/') === 'https://herrera.herrera.hr')
        )) {
            throw new RuntimeException('Staff imports are restricted to the isolated Herrera migration and test shop.');
        }
        foreach (['account_type', 'admin_username', 'admin_login_enabled'] as $column) {
            if (! DB::getSchemaBuilder()->hasColumn('users', $column)) {
                throw new RuntimeException('Install the staff identity migration before importing.');
            }
        }
        if (! DB::getSchemaBuilder()->hasTable('admin_customer_assignments')) {
            throw new RuntimeException('Install the staff customer-assignment migration before importing.');
        }
    }
}
