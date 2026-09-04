<?php

namespace App\Models\User;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserProfile extends Model
{
    protected $fillable = [
        'user_id',
        'first_name',
        'last_name',
        'phone',
        'company',
        'oib',
        'birthday',
        'gender',
        'affiliate_name',
        'bio',
        'newsletter_opt_in',
        'payload',
    ];

    protected $casts = [
        'birthday' => 'date',
        'newsletter_opt_in' => 'bool',
        'payload' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class);
    }
}

