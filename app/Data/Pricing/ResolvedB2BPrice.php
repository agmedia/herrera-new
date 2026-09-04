<?php

namespace App\Data\Pricing;

class ResolvedB2BPrice
{
    public function __construct(
        public readonly int $id,
        public readonly float $price,
        public readonly string $source_type,
        public readonly ?int $customer_group_id = null,
        public readonly ?int $user_id = null,
        public readonly ?int $product_package_id = null,
        public readonly ?int $group_price_id = null,
        public readonly ?int $rule_id = null,
    ) {}

    public function getKey(): int
    {
        return $this->id;
    }
}
