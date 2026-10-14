<?php

namespace App\Services\Import;

use App\Models\User;
use App\Models\User\LegacyCredential;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Silber\Bouncer\BouncerFacade as Bouncer;
use Silber\Bouncer\Database\Role;

/** Opt-in reconciliation: no source writes, no revival of native permissions or removed assignments. */
class HerreraStaffFinalSync
{
    public function import(ConnectionInterface $source, string $prefix, bool $apply): array
    {
        // This helper can be resolved directly; enforce the same guard before the first source SELECT.
        app(HerreraStaffImportService::class)->assertFinalSyncContext($source, $prefix);
        $rows = $source->table($prefix.'user')->orderBy('user_id')->get();
        $groups = $source->table($prefix.'user_group')->get()->keyBy('user_group_id');
        $assignments = $source->table($prefix.'customer_to_user')->get(['user_id', 'customer_id']);
        $maps = DB::table('herrera_import_maps')->where('source', 'herrera-opencart')->where('entity', 'admin_user')->pluck('target_id', 'source_id');
        $customerMap = DB::table('herrera_import_maps')->where('source', 'herrera-opencart')->where('entity', 'customer')->pluck('target_id', 'source_id');
        $report = array_fill_keys([
            'staff', 'administrators', 'order_managers', 'inactive', 'assignments', 'created', 'existing',
            'staff_preserved', 'assignments_added', 'assignments_preserved', 'native_assignment_removals_preserved',
            'native_assignment_reassignments_preserved', 'legacy_credentials_preserved', 'legacy_credentials_refreshed',
            'identity_conflicts', 'source_validation_conflicts', 'assignment_conflicts', 'missing_customer_mappings',
            'missing_source_staff_conflicts', 'source_status_conflicts', 'role_conflicts', 'baseline_conflicts', 'orphan_source_assignments', 'conflicts',
        ], 0);
        $report = ['mode' => $apply ? 'apply' : 'dry-run', 'final_sync' => 1] + $report;
        $report['staff'] = $rows->count();
        $sourceIds = $rows->pluck('user_id')->map(fn ($id) => (string) $id)->all();
        $report['orphan_source_assignments'] = $assignments->filter(fn ($assignment) => ! in_array((string) $assignment->user_id, $sourceIds, true))
            ->unique(fn ($assignment) => $assignment->user_id.':'.$assignment->customer_id)->count();
        $report['missing_source_staff_conflicts'] = $maps->keys()->map(fn ($id) => (string) $id)->diff($sourceIds)->count();
        $duplicateTargets = $maps->countBy()->filter(fn ($count) => $count > 1)->keys()->map(fn ($id) => (int) $id)->all();
        $plan = [];
        $plannedAssignments = [];

        foreach ($rows as $row) {
            $username = trim((string) $row->username);
            $email = Str::lower(trim((string) $row->email));
            $role = $this->reviewedRole($groups->get($row->user_group_id));
            if (! $role || ! $this->validSourceIdentity($row, $rows) || ! in_array(strlen((string) $row->password), [32, 40], true) || ! ctype_xdigit((string) $row->password)) {
                $report['source_validation_conflicts']++;

                continue;
            }
            $report[$role === 'admin' ? 'administrators' : 'order_managers']++;
            $report['inactive'] += ! (bool) $row->status;
            $mappedId = $maps->get((string) $row->user_id);
            $user = $mappedId ? User::query()->find($mappedId) : null;
            $matches = User::query()->where('account_type', 'staff')->where(fn ($query) => $query
                ->whereRaw('LOWER(admin_username) = ?', [Str::lower($username)])
                ->orWhereRaw('LOWER(email) = ?', [$email])
                ->orWhereRaw('LOWER(email) = ?', [Str::lower($username)])
                ->orWhereRaw('LOWER(admin_username) = ?', [$email]))->get();
            $usernameOwnedByCustomer = User::query()->where('account_type', '!=', 'staff')->whereRaw('LOWER(admin_username) = ?', [Str::lower($username)])->exists();
            if (($mappedId && (! $user || $user->account_type !== 'staff' || in_array((int) $mappedId, $duplicateTargets, true)))
                || ($user && $matches->contains(fn (User $match) => $match->id !== $user->id))
                || (! $mappedId && $matches->isNotEmpty()) || $usernameOwnedByCustomer) {
                $report['identity_conflicts']++;

                continue;
            }
            $profileSourceId = data_get($user?->profile?->payload, 'legacy_admin.source_id');
            if ($user && $profileSourceId !== null && (int) $profileSourceId !== (int) $row->user_id) {
                $report['identity_conflicts']++;

                continue;
            }
            if (! $user && ! $this->existingRoleIsReviewed($role)) {
                $report['role_conflicts']++;

                continue;
            }
            $report[$user ? 'existing' : 'created']++;
            $report['staff_preserved'] += (int) ($user !== null);
            // A source disable cannot silently leave an enabled native identity unnoticed at go-live.
            if ($user && $user->admin_login_enabled && ! (bool) $row->status) {
                $report['source_status_conflicts']++;
            }
            $sourceCustomerIds = $assignments->where('user_id', $row->user_id)->pluck('customer_id')->map(fn ($id) => (int) $id)->unique()->sort()->values()->all();
            $report['assignments'] += count($sourceCustomerIds);
            $current = $user ? DB::table('admin_customer_assignments')->where('admin_user_id', $user->id)->pluck('customer_user_id')->map(fn ($id) => (int) $id)->all() : [];
            $report['assignments_preserved'] += count($current);
            $metadata = $user?->profile?->payload ?? [];
            if (! is_array($metadata) || (isset($metadata['legacy_admin']) && ! is_array($metadata['legacy_admin']))) {
                $report['baseline_conflicts']++;

                continue;
            }
            $baseline = data_get($metadata, 'legacy_admin.assignment_baseline');
            $knownBaseline = is_array($baseline) && ($baseline['version'] ?? null) === 1
                && (int) data_get($metadata, 'legacy_admin.source_id') === (int) $row->user_id
                && $this->validIds($baseline['source_customer_ids'] ?? null) && $this->validIds($baseline['removed_source_customer_ids'] ?? null);
            if ($baseline !== null && ! $knownBaseline) {
                $report['baseline_conflicts']++;
            }
            $oldSourceIds = $knownBaseline ? array_map('intval', $baseline['source_customer_ids']) : [];
            $removed = $knownBaseline ? array_map('intval', $baseline['removed_source_customer_ids']) : [];
            foreach ($oldSourceIds as $oldId) {
                $targetId = (int) $customerMap->get((string) $oldId);
                if ($targetId && ! in_array($targetId, $current, true)) {
                    $removed[] = $oldId;
                }
            }
            $toAdd = [];
            $resolvedSourceIds = [];
            $unresolved = $baseline !== null && ! $knownBaseline;
            foreach ($sourceCustomerIds as $sourceCustomerId) {
                $targetId = (int) $customerMap->get((string) $sourceCustomerId);
                $customer = $targetId ? User::query()->find($targetId) : null;
                if (! $customer || $customer->account_type !== 'customer' || $customer->isA('superadmin', 'admin', 'editor', 'order_manager') || $customer->can('admin.access')) {
                    $report['missing_customer_mappings']++;
                    $unresolved = true;

                    continue;
                }
                if (in_array($targetId, $current, true)) {
                    $resolvedSourceIds[] = $sourceCustomerId;
                    $removed = array_values(array_diff($removed, [$sourceCustomerId]));

                    continue;
                }
                $otherAssignment = DB::table('admin_customer_assignments')->where('customer_user_id', $targetId)->exists()
                    || isset($plannedAssignments[$targetId]);
                if ($knownBaseline && (in_array($sourceCustomerId, $oldSourceIds, true) || in_array($sourceCustomerId, $removed, true))) {
                    $report[$otherAssignment ? 'native_assignment_reassignments_preserved' : 'native_assignment_removals_preserved']++;
                    $resolvedSourceIds[] = $sourceCustomerId;
                    $removed[] = $sourceCustomerId;

                    continue;
                }
                if (($user && ! $knownBaseline) || $otherAssignment) {
                    $report['assignment_conflicts']++;
                    $unresolved = true;

                    continue;
                }
                $toAdd[] = $targetId;
                $resolvedSourceIds[] = $sourceCustomerId;
                $plannedAssignments[$targetId] = true;
            }
            $report['assignments_added'] += count($toAdd);
            $legacy = $user ? LegacyCredential::query()->where('user_id', $user->id)->first() : null;
            $refreshLegacy = $legacy && $user->admin_login_enabled && $legacy->enabled && ! $legacy->retired_at
                && hash_equals((string) $legacy->password_fingerprint, hash('sha256', (string) $user->password))
                && (! hash_equals((string) $legacy->legacy_hash, (string) $row->password) || ! hash_equals((string) $legacy->legacy_salt, (string) $row->salt));
            if ($legacy) {
                $report[$refreshLegacy ? 'legacy_credentials_refreshed' : 'legacy_credentials_preserved']++;
            }
            // Keep all observed source pairs so a later native removal stays removed even if OpenCart deletes then re-adds the pair.
            // Never manufacture history for an ambiguous removal; new staff get only resolved pairs.
            $nextBaseline = (! $user || ! $unresolved) ? [
                'version' => 1, 'source_customer_ids' => array_values(array_unique(array_merge($oldSourceIds, $resolvedSourceIds))),
                'removed_source_customer_ids' => array_values(array_unique($removed)),
            ] : ($knownBaseline ? array_replace($baseline, ['removed_source_customer_ids' => array_values(array_unique($removed))]) : null);
            $plan[] = compact('row', 'role', 'username', 'email', 'user', 'toAdd', 'legacy', 'refreshLegacy', 'nextBaseline');
        }
        foreach (['identity_conflicts', 'source_validation_conflicts', 'assignment_conflicts', 'missing_customer_mappings', 'missing_source_staff_conflicts', 'source_status_conflicts', 'role_conflicts', 'baseline_conflicts', 'orphan_source_assignments'] as $key) {
            $report['conflicts'] += $report[$key];
        }
        if (! $apply) {
            return $report;
        }
        DB::transaction(function () use ($plan, $source, &$report): void {
            $report['assignments_added'] = 0;
            $report['legacy_credentials_refreshed'] = 0;
            foreach ($plan as $item) {
                ['row' => $row, 'user' => $user] = $item;
                if (! $user) {
                    if (! $this->existingRoleIsReviewed($item['role'])) {
                        throw new \RuntimeException('Staff role permissions changed after preflight; rerun the isolated rehearsal.');
                    }
                    $user = new User;
                    $user->forceFill(['account_type' => 'staff', 'admin_username' => $item['username'], 'admin_login_enabled' => (bool) $row->status,
                        'name' => trim($row->firstname.' '.$row->lastname) ?: $item['username'], 'email' => $item['email'],
                        'email_verified_at' => now(), 'password' => Hash::make(Str::random(80))])->save();
                    Bouncer::assign($item['role'])->to($user);
                    // Existing role permissions are reused; final sync never activates direct permissions.
                    LegacyCredential::query()->create(['user_id' => $user->id, 'legacy_hash' => $row->password, 'legacy_salt' => $row->salt, 'enabled' => (bool) $row->status]);
                    DB::table('herrera_import_maps')->insert(['source' => 'herrera-opencart', 'entity' => 'admin_user', 'source_id' => (string) $row->user_id,
                        'target_id' => $user->id, 'checksum' => hash('sha256', json_encode([$item['username'], $item['email'], $row->status, $item['role']])), 'created_at' => now(), 'updated_at' => now()]);
                    $profile = $user->profile()->make(['first_name' => $row->firstname, 'last_name' => $row->lastname]);
                    $profile->payload = ['legacy_admin' => ['source_id' => (int) $row->user_id, 'source_group_id' => (int) $row->user_group_id,
                        'username' => $item['username'], 'source_database' => $source->getDatabaseName()]];
                } else {
                    // Existing native name/login/status/roles/password and direct permissions are never imported over.
                    $user = User::query()->lockForUpdate()->findOrFail($user->id);
                    $profile = $user->profile()->firstOrNew();
                    if ($item['refreshLegacy']) {
                        $legacy = LegacyCredential::query()->where('user_id', $user->id)->lockForUpdate()->first();
                        if ($legacy && $legacy->enabled && ! $legacy->retired_at && $user->admin_login_enabled
                            && hash_equals((string) $legacy->password_fingerprint, hash('sha256', (string) $user->password))) {
                            $legacy->update(['legacy_hash' => $row->password, 'legacy_salt' => $row->salt]);
                            $report['legacy_credentials_refreshed']++;
                        } else {
                            $report['legacy_credentials_preserved']++;
                        }
                    }
                }
                foreach ($item['toAdd'] as $customerId) {
                    // Recheck access ownership under the destination transaction; a native reassignment wins.
                    User::query()->whereKey($customerId)->lockForUpdate()->firstOrFail(['id']);
                    if (! DB::table('admin_customer_assignments')->where('customer_user_id', $customerId)->exists()) {
                        DB::table('admin_customer_assignments')->insert(['admin_user_id' => $user->id, 'customer_user_id' => $customerId, 'created_at' => now(), 'updated_at' => now()]);
                        $report['assignments_added']++;
                    } else {
                        $report['assignment_conflicts']++;
                        $report['conflicts']++;
                    }
                }
                if ($item['nextBaseline'] !== null) {
                    $metadata = $profile->payload ?? [];
                    $metadata['legacy_admin']['source_id'] ??= (int) $row->user_id;
                    $metadata['legacy_admin']['assignment_baseline'] = $item['nextBaseline'];
                    $profile->payload = $metadata;
                    $profile->save();
                }
            }
        });
        Bouncer::refresh();

        return $report;
    }

    private function validIds(mixed $ids): bool
    {
        return is_array($ids) && count(array_filter($ids, fn ($id) => is_int($id) && $id > 0)) === count($ids);
    }

    private function validSourceIdentity(object $row, Collection $rows): bool
    {
        $username = Str::lower(trim((string) $row->username));
        $email = Str::lower(trim((string) $row->email));
        if ($username === '' || strlen($username) > 255 || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        return ! $rows->contains(fn ($other) => $other->user_id !== $row->user_id && (
            in_array($username, [Str::lower(trim((string) $other->username)), Str::lower(trim((string) $other->email))], true)
            || in_array($email, [Str::lower(trim((string) $other->username)), Str::lower(trim((string) $other->email))], true)
        ));
    }

    private function reviewedRole(?object $group): ?string
    {
        $role = match (Str::lower(trim((string) ($group?->name ?? '')))) {
            'administrator', 'admin' => 'admin', 'order manager' => 'order_manager', default => null,
        };
        $permissions = json_decode((string) ($group?->permission ?? ''), true);
        if (! $role || ! is_array($permissions) || ! is_array($permissions['access'] ?? null) || ! is_array($permissions['modify'] ?? null)) {
            return null;
        }
        if ($role === 'order_manager') {
            $access = ['customer/custom_field', 'customer/customer', 'localisation/country', 'localisation/currency', 'localisation/geo_zone', 'localisation/language', 'sale/order', 'user/api'];
            foreach (['access' => $access, 'modify' => array_values(array_diff($access, ['localisation/geo_zone']))] as $kind => $expected) {
                $actual = array_values(array_unique($permissions[$kind]));
                sort($actual);
                sort($expected);
                if ($actual !== $expected) {
                    return null;
                }
            }
        }

        return $role;
    }

    private function existingRoleIsReviewed(string $name): bool
    {
        $role = Role::query()->where('name', $name)->first();
        $allowed = config('admin_acl.roles.'.$name, []);
        if ($name === 'admin') {
            // Reviewed grants installed by 2026_08_30_086000_grant_msan_integration_access_to_admin_role.
            $allowed = array_merge($allowed, ['integrations.msan.view', 'integrations.msan.settings.manage', 'integrations.msan.sync.run', 'integrations.msan.mapping.manage', 'integrations.msan.import.manage']);
        }

        return $role && $allowed && ! $role->abilities()->where('permissions.forbidden', false)->whereNotIn('name', $allowed)->exists();
    }
}
