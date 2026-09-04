<?php

namespace App\Models\User;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class UserTrackingEvent extends Model
{
    protected $fillable = [
        'user_id',
        'session_id',
        'event',
        'subject_type',
        'subject_id',
        'url',
        'referrer',
        'ip_address',
        'user_agent',
        'payload',
        'occurred_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'occurred_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class);
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }
}

