<?php

namespace App\Models\Concerns;

trait HasB2BMonetaryPrecision
{
    public function getCasts(): array
    {
        $casts = parent::getCasts();
        if ((bool) config('commerce.b2b_only', true)) {
            foreach ($this->b2bMonetaryAttributes as $attribute) {
                $casts[$attribute] = 'decimal:4';
            }
        }

        return $casts;
    }
}
