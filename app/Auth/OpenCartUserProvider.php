<?php

namespace App\Auth;

use App\Models\User\LegacyCredential;
use Illuminate\Auth\EloquentUserProvider;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;

class OpenCartUserProvider extends EloquentUserProvider
{
    public function validateCredentials(Authenticatable $user, #[\SensitiveParameter] array $credentials)
    {
        if (parent::validateCredentials($user, $credentials)) {
            return true;
        }

        $plain = $credentials['password'] ?? null;
        if (! is_string($plain) || $plain === '') {
            return false;
        }

        $legacy = LegacyCredential::query()->where('user_id', $user->getAuthIdentifier())->first();
        if (! $legacy || ! $legacy->enabled || $legacy->retired_at) {
            return false;
        }

        // A password reset or profile password change permanently invalidates
        // the original credential, even before the first migrated login.
        if (! hash_equals($legacy->password_fingerprint, hash('sha256', (string) $user->getAuthPassword()))) {
            $legacy->retire();

            return false;
        }

        $storedHash = strtolower((string) $legacy->legacy_hash);
        $salt = (string) $legacy->legacy_salt;
        $valid = match (strlen($storedHash)) {
            40 => ctype_xdigit($storedHash) && hash_equals($storedHash, sha1($salt.sha1($salt.sha1($plain)))),
            32 => ctype_xdigit($storedHash) && hash_equals($storedHash, md5($plain)),
            default => false,
        };
        if (! $valid) {
            return false;
        }

        return DB::transaction(function () use ($user, $legacy, $plain): bool {
            $current = LegacyCredential::query()->lockForUpdate()->find($legacy->id);
            $user->refresh();
            if (! $current || ! $current->enabled || $current->retired_at
                || ! hash_equals($current->password_fingerprint, hash('sha256', (string) $user->getAuthPassword()))) {
                return false;
            }

            $user->forceFill([$user->getAuthPasswordName() => $this->hasher->make($plain)])->save();
            $current->retire();

            return true;
        });
    }
}
