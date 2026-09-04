<?php

namespace Tests\Unit;

use App\Models\Catalog\Product\Product;
use App\Models\Catalog\Product\ProductEnergyDeclaration;
use App\Support\ProductEnergyLabelPresenter;
use Tests\TestCase;

class ProductEnergyLabelPresenterTest extends TestCase
{
    public function test_it_builds_official_eprel_assets_from_strict_persisted_identifiers(): void
    {
        $product = new Product;
        $product->setRelation('energyDeclarations', collect([
            new ProductEnergyDeclaration([
                'context_code' => 'sdr',
                'label' => 'SDR',
                'energy_class' => 'D',
                'scale_min' => 'A',
                'scale_max' => 'G',
                'eprel_registration_number' => '1713217',
                'eprel_product_group' => 'electronicdisplays',
                'energy_label_image' => 'D A-G.svg',
                'is_primary' => true,
                'source' => ProductEnergyDeclaration::SOURCE_EPREL,
            ]),
        ]));
        $product->setRelation('energyMedia', collect());

        $declaration = app(ProductEnergyLabelPresenter::class)->primary($product);

        $this->assertNotNull($declaration);
        $this->assertTrue($declaration['is_complete']);
        $this->assertSame(
            'https://ec.europa.eu/assets/move-ener/eprel/EPREL%20Public/Nested-labels%20thumbnails/D%20A-G.svg',
            $declaration['energy_class_image_url'],
        );
        $this->assertSame(
            'https://eprel.ec.europa.eu/api/products/electronicdisplays/1713217/labels?format=PDF',
            $declaration['energy_label_url'],
        );
        $this->assertSame(
            'https://eprel.ec.europa.eu/fiches/electronicdisplays/Fiche_1713217_HR.pdf',
            $declaration['product_information_sheet_url'],
        );
    }

    public function test_it_rejects_unsafe_eprel_thumbnail_names_but_uses_css_fallback_with_a_valid_label(): void
    {
        $product = new Product;
        $product->setRelation('energyDeclarations', collect([
            new ProductEnergyDeclaration([
                'context_code' => 'unsafe',
                'energy_class' => 'A',
                'scale_min' => 'A',
                'scale_max' => 'G',
                'eprel_registration_number' => '123456',
                'eprel_product_group' => 'lightsources',
                'energy_label_image' => '../A.svg?token=secret',
                'is_primary' => true,
                'source' => ProductEnergyDeclaration::SOURCE_EPREL,
            ]),
        ]));
        $product->setRelation('energyMedia', collect());

        $declarations = app(ProductEnergyLabelPresenter::class)->declarations($product);

        $this->assertCount(1, $declarations);
        $this->assertTrue($declarations->first()['is_complete']);
        $this->assertNull($declarations->first()['energy_class_image_url']);
        $this->assertNotNull(app(ProductEnergyLabelPresenter::class)->primary($product));
    }

    public function test_imported_declaration_without_any_valid_label_asset_is_not_complete(): void
    {
        $product = new Product;
        $product->setRelation('energyDeclarations', collect([
            new ProductEnergyDeclaration([
                'context_code' => 'unsafe',
                'energy_class' => 'A',
                'scale_min' => 'A',
                'scale_max' => 'G',
                'eprel_registration_number' => 'invalid',
                'eprel_product_group' => '../lightsources',
                'energy_label_image' => '../A.svg?token=secret',
                'energy_label_url' => 'storage/../private/label.pdf',
                'is_primary' => true,
                'source' => ProductEnergyDeclaration::SOURCE_MSAN,
            ]),
        ]));
        $product->setRelation('energyMedia', collect());

        $declaration = app(ProductEnergyLabelPresenter::class)->primaryDeclaration($product);

        $this->assertNotNull($declaration);
        $this->assertFalse($declaration['is_complete']);
        $this->assertNull($declaration['energy_class_image_url']);
        $this->assertNull($declaration['energy_label_url']);
        $this->assertNull(app(ProductEnergyLabelPresenter::class)->primary($product));
    }

    public function test_nested_thumbnail_can_render_an_arrow_but_is_not_a_full_label_link(): void
    {
        $product = new Product;
        $product->setRelation('energyDeclarations', collect([
            new ProductEnergyDeclaration([
                'context_code' => 'thumbnail-only',
                'energy_class' => 'C',
                'scale_min' => 'A',
                'scale_max' => 'G',
                'energy_label_image' => 'C A-G.svg',
                'is_primary' => true,
                'source' => ProductEnergyDeclaration::SOURCE_MSAN,
            ]),
        ]));
        $product->setRelation('energyMedia', collect());

        $declaration = app(ProductEnergyLabelPresenter::class)->primaryDeclaration($product);

        $this->assertNotNull($declaration);
        $this->assertTrue($declaration['has_arrow']);
        $this->assertFalse($declaration['is_complete']);
        $this->assertNotNull($declaration['energy_class_image_url']);
        $this->assertNull($declaration['energy_label_url']);
        $this->assertNull(app(ProductEnergyLabelPresenter::class)->primary($product));

        $html = (string) $this->blade(
            '<x-front.energy-label-arrow :declaration="$declaration" />',
            ['declaration' => $declaration],
        );

        $this->assertStringContainsString('data-energy-label-arrow', $html);
        $this->assertStringContainsString('<span', $html);
        $this->assertStringNotContainsString('<a', $html);
        $this->assertStringNotContainsString('href=', $html);
    }

    public function test_explicit_primary_wins_even_when_it_is_not_the_first_loaded_declaration(): void
    {
        $product = new Product;
        $product->setRelation('energyDeclarations', collect([
            new ProductEnergyDeclaration([
                'context_code' => 'first',
                'energy_class' => 'B',
                'scale_min' => 'A',
                'scale_max' => 'G',
                'energy_label_url' => 'https://cdn.example.test/labels/first.pdf',
                'is_primary' => false,
                'source' => ProductEnergyDeclaration::SOURCE_MANUAL,
            ]),
            new ProductEnergyDeclaration([
                'context_code' => 'selected',
                'energy_class' => 'C',
                'scale_min' => 'A',
                'scale_max' => 'G',
                'energy_label_url' => 'https://cdn.example.test/labels/selected.pdf',
                'is_primary' => true,
                'source' => ProductEnergyDeclaration::SOURCE_MANUAL,
            ]),
        ]));
        $product->setRelation('energyMedia', collect());

        $declaration = app(ProductEnergyLabelPresenter::class)->primary($product);

        $this->assertNotNull($declaration);
        $this->assertSame('selected', $declaration['context_code']);
        $this->assertSame('C', $declaration['energy_class']);
    }

    public function test_manual_https_label_uses_accessible_css_arrow_fallback(): void
    {
        $product = new Product;
        $product->setRelation('energyDeclarations', collect([
            new ProductEnergyDeclaration([
                'context_code' => 'manual-main',
                'energy_class' => 'B',
                'scale_min' => 'A',
                'scale_max' => 'G',
                'energy_label_url' => 'https://cdn.example.test/labels/product.pdf',
                'product_information_sheet_url' => 'https://cdn.example.test/fiches/product.pdf',
                'is_primary' => true,
                'source' => ProductEnergyDeclaration::SOURCE_MANUAL,
            ]),
        ]));
        $product->setRelation('energyMedia', collect());

        $declaration = app(ProductEnergyLabelPresenter::class)->primary($product);

        $this->assertNotNull($declaration);
        $this->assertTrue($declaration['is_complete']);
        $this->assertNull($declaration['energy_class_image_url']);
    }

    public function test_energy_class_outside_declared_scale_does_not_render_an_arrow(): void
    {
        $product = new Product;
        $product->setRelation('energyDeclarations', collect([
            new ProductEnergyDeclaration([
                'context_code' => 'invalid-range',
                'energy_class' => 'B',
                'scale_min' => 'C',
                'scale_max' => 'G',
                'energy_label_url' => 'https://cdn.example.test/labels/product.pdf',
                'is_primary' => true,
                'source' => ProductEnergyDeclaration::SOURCE_MANUAL,
            ]),
        ]));
        $product->setRelation('energyMedia', collect());

        $declaration = app(ProductEnergyLabelPresenter::class)->primaryDeclaration($product);

        $this->assertNotNull($declaration);
        $this->assertFalse($declaration['is_complete']);
        $this->assertNull(app(ProductEnergyLabelPresenter::class)->primary($product));
    }
}
