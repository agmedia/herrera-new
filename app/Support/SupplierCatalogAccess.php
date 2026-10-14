<?php

namespace App\Support;

use App\Models\User;

final class SupplierCatalogAccess
{
    public static function authorize(?User $user): void
    {
        abort_unless($user && ($user->isA('superadmin') || ($user->can('admin.access') && $user->can('content.supplier_catalogs.manage'))), 403);
    }
}
