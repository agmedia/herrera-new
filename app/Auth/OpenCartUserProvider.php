<?php

namespace App\Auth;

use App\Models\User\LegacyCredential;
use Illuminate\Auth\EloquentUserProvider;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Support\Facades\DB;

class OpenCartUserProvider extends EloquentUserProvider
{
    public function __construct(Hasher $hasher, $model, private readonly ?string $accountType = null)
    {
        parent::__construct($hasher, $model);
    }

    protected function newModelQuery($model = null)
    {
        $query = parent::newModelQuery($model);

        return $this->accountType === null ? $query : $query->where('account_type', $this->accountType);
    }

    public function retrieveByCredentials(#[\SensitiveParameter] array $credentials)
    {
        if (array_key_exists('login', $credentials)) {
            if (($credentials['account_type'] ?? null) !== 'staff' || ! is_string($credentials['login'])) {
                return null;
            }

            $identity = mb_strtolower(trim($credentials['login']));
            if ($identity === '') {
                return null;
            }

            unset($credentials['login']);

            // Preserve OpenCart's username identity even when it looks like an email.
            return parent::retrieveByCredentials($credentials + ['admin_username' => $identity])
                ?? parent::retrieveByCredentials($credentials + ['email' => $identity]);
        }

        if (isset($credentials['email']) && ! isset($credentials['account_type'])) {
            $credentials['email'] = mb_strtolower(trim((string) $credentials['email']));

            // A customer's password must never fall through to a same-email staff account.
            return parent::retrieveByCredentials($credentials + ['account_type' => 'customer'])
                ?? parent::retrieveByCredentials($credentials + ['account_type' => 'staff', 'admin_login_enabled' => true]);
        }

        return parent::retrieveByCredentials($credentials);
    }

    public function retrieveById($identifier)
    {
        $user = parent::retrieveById($identifier);

        return $user && $this->staffLoginIsDisabled($user) ? null : $user;
    }

    public function retrieveByToken($identifier, #[\SensitiveParameter] $token)
    {
        $user = parent::retrieveByToken($identifier, $token);

        return $user && $this->staffLoginIsDisabled($user) ? null : $user;
    }

    public function validateCredentials(Authenticatable $user, #[\SensitiveParameter] array $credentials)
    {
        if ($this->staffLoginIsDisabled($user)) {
            return false;
        }

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
            if ($this->staffLoginIsDisabled($user) || ! $current || ! $current->enabled || $current->retired_at
                || ! hash_equals($current->password_fingerprint, hash('sha256', (string) $user->getAuthPassword()))) {
                return false;
            }

            $user->forceFill([$user->getAuthPasswordName() => $this->hasher->make($plain)])->save();
            $current->retire();

            return true;
        });
    }

    private function staffLoginIsDisabled(Authenticatable $user): bool
    {
        return $user->account_type === 'staff' && ! $user->admin_login_enabled;
    }
}
