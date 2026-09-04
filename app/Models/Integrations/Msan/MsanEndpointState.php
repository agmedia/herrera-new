<?php

namespace App\Models\Integrations\Msan;

use Illuminate\Database\Eloquent\Model;

class MsanEndpointState extends Model
{
    public const ENDPOINT_CATALOG = 'catalog';

    public const ENDPOINT_PRICES = 'prices';

    public const ENDPOINT_AVAILABILITY = 'availability';

    public const ENDPOINT_SPECIFICATIONS = 'specifications';

    public const ENDPOINT_CATEGORIES = 'categories';

    public const ENDPOINT_PRODUCT_CATEGORIES = 'product_categories';

    public const ENDPOINT_BARCODES = 'barcodes';

    protected $fillable = [
        'endpoint',
        'last_attempt_at',
        'last_success_at',
        'next_allowed_at',
        'last_error',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'last_attempt_at' => 'datetime',
            'last_success_at' => 'datetime',
            'next_allowed_at' => 'datetime',
            'metadata' => 'array',
        ];
    }
}
