<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Silber\Bouncer\Database\HasRolesAndAbilities;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

class User extends Authenticatable implements HasMedia
{
    use HasApiTokens;

    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory;

    use HasRolesAndAbilities;
    use InteractsWithMedia;
    use Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'api_access_enabled',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $attributes = [
        'account_type' => 'customer',
        'admin_login_enabled' => true,
    ];

    protected static function booted(): void
    {
        static::updated(function (self $user): void {
            if ($user->wasChanged('password')) {
                \App\Models\User\LegacyCredential::query()
                    ->where('user_id', $user->id)
                    ->whereNull('retired_at')
                    ->get()
                    ->each->retire();
            }
        });
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'api_access_enabled' => 'boolean',
            'admin_login_enabled' => 'boolean',
        ];
    }

    /**
     * Register user media collections.
     */
    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('avatar')->singleFile();
    }

    public function profile(): HasOne
    {
        return $this->hasOne(\App\Models\User\UserProfile::class);
    }

    public function addresses(): HasMany
    {
        return $this->hasMany(\App\Models\User\UserAddress::class)->orderByDesc('is_default')->orderBy('id');
    }

    public function customerGroups(): BelongsToMany
    {
        return $this->belongsToMany(\App\Models\User\CustomerGroup::class, 'customer_group_user')
            ->withTimestamps();
    }

    public function b2bAccount(): HasOne
    {
        return $this->hasOne(\App\Models\User\B2BAccount::class);
    }

    public function trackingEvents(): HasMany
    {
        return $this->hasMany(\App\Models\User\UserTrackingEvent::class);
    }

    public function loyaltyTransactions(): HasMany
    {
        return $this->hasMany(\App\Models\User\LoyaltyTransaction::class);
    }

    public function wishlistItems(): HasMany
    {
        return $this->hasMany(\App\Models\User\WishlistItem::class);
    }

    public function newsletterSignups(): HasMany
    {
        return $this->hasMany(\App\Models\User\NewsletterSignup::class);
    }
}
