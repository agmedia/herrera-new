<?php

namespace App\Models\User;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserAddress extends Model
{
    public const TYPE_BILLING = 'billing';
    public const TYPE_SHIPPING = 'shipping';

    protected $fillable = [
        'user_id',
        'type',
        'first_name',
        'last_name',
        'company',
        'oib',
        'vat_id',
        'phone',
        'address_line_1',
        'address_line_2',
        'postal_code',
        'city',
        'state',
        'country_code',
        'is_default',
        'payload',
    ];

    protected $casts = [
        'is_default' => 'bool',
        'payload' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class);
    }
}

