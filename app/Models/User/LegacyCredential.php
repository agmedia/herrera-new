<?php

namespace App\Models\User;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class LegacyCredential extends Model
{
    protected $table = 'user_legacy_credentials';

    protected $fillable = ['user_id', 'legacy_hash', 'legacy_salt', 'enabled'];

    protected $hidden = ['legacy_hash', 'legacy_salt', 'password_fingerprint'];

    protected function casts(): array
    {
        return [
            'legacy_hash' => 'encrypted',
            'legacy_salt' => 'encrypted',
            'enabled' => 'boolean',
            'retired_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $credential): void {
            $credential->password_fingerprint = hash('sha256', (string) User::query()->whereKey($credential->user_id)->value('password'));
        });
    }

    public function retire(): void
    {
        $this->forceFill([
            'legacy_hash' => null, 'legacy_salt' => null,
            'enabled' => false, 'retired_at' => now(),
        ])->save();
    }
}
