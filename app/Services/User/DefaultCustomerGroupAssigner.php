<?php

namespace App\Services\User;

use App\Models\User;
use App\Models\User\CustomerGroup;

class DefaultCustomerGroupAssigner
{
    public function assign(User $user): void
    {
        $groupId = CustomerGroup::query()
            ->where('is_active', true)
            ->where('is_default', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->value('id');

        if (! $groupId) {
            return;
        }

        $user->customerGroups()->syncWithoutDetaching([(int) $groupId]);
    }
}
