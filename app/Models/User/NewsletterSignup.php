<?php

namespace App\Models\User;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NewsletterSignup extends Model
{
    public const SOURCE_FOOTER = 'footer';

    public const PROVIDER_NONE = 'none';
    public const PROVIDER_DATABASE = 'database';
    public const PROVIDER_MAILCHIMP = 'mailchimp';
    public const PROVIDER_KLAVIYO = 'klaviyo';

    public const SYNC_PENDING = 'pending';
    public const SYNC_SYNCED = 'synced';
    public const SYNC_SKIPPED = 'skipped';
    public const SYNC_FAILED = 'failed';

    protected $fillable = [
        'user_id',
        'email',
        'source',
        'locale',
        'provider',
        'sync_status',
        'consent_accepted',
        'provider_reference',
        'provider_error',
        'ip_address',
        'user_agent',
        'subscribed_at',
        'synced_at',
        'payload',
    ];

    protected $casts = [
        'user_id' => 'int',
        'consent_accepted' => 'bool',
        'subscribed_at' => 'datetime',
        'synced_at' => 'datetime',
        'payload' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'user_id');
    }
}
