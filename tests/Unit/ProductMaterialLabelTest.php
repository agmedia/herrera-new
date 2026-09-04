<?php

namespace Tests\Unit;

use App\Support\ProductMaterialLabel;
use PHPUnit\Framework\TestCase;

class ProductMaterialLabelTest extends TestCase
{
    public function test_it_returns_only_the_material_with_the_highest_percentage(): void
    {
        $this->assertSame(
            'Mikromodal',
            ProductMaterialLabel::dominantMaterialName('95% Mikromodal, 5% Elastan')
        );
    }

    public function test_it_supports_slash_separated_compositions(): void
    {
        $this->assertSame(
            'Modal',
            ProductMaterialLabel::dominantMaterialName('47% Modal / 47% Pamuk / 6% Elastan')
        );
    }

    public function test_it_supports_decimal_comma_percentages(): void
    {
        $this->assertSame(
            'Pamuk',
            ProductMaterialLabel::dominantMaterialName('92,5% Pamuk / 7,5% Elastan')
        );
    }

    public function test_it_leaves_non_percentage_labels_to_the_existing_display_path(): void
    {
        $this->assertNull(ProductMaterialLabel::dominantMaterialName('Bambus'));
    }
}
