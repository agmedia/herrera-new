<?php

$text = static fn (string $label, string $translation): array => ['label' => $label, 'translation' => 'herrera.'.$translation];
$url = static fn (string $label): array => ['label' => $label, 'type' => 'url'];

return [
    'placements' => [
        'home.hero' => 'Uvod',
        'home.hero_benefits' => 'Ispod uvoda',
        'home.categories' => 'Kategorije',
        'home.before_products' => 'Izdvojena ponuda',
        'home.after_products' => 'Dodatna ponuda',
        'home.bottom' => 'Donji dio početne',
    ],
    'fields' => [
        'herrera_hero' => [
            'eyebrow' => $text('Nadnaslov', 'eyebrow'),
            'panel_title' => $text('Naslov panela za poslovne kupce', 'panel_title'),
            'panel_description' => $text('Opis panela', 'panel_description'),
            'prices_label' => $text('Oznaka za pristup cijenama', 'benefit_prices'),
            'quick_order_label' => $text('Oznaka za brzu narudžbu', 'benefit_order'),
            'orders_label' => $text('Oznaka za prethodne narudžbe', 'benefit_history'),
            'account_label' => $text('Oznaka poslovnog računa', 'account'),
            'account_url' => $url('Poveznica poslovnog računa'),
            'register_label' => $text('Oznaka registracije', 'register'),
            'register_url' => $url('Poveznica registracije'),
        ],
        'featured_categories' => [
            'support_visible' => ['label' => 'Prikaži karticu B2B podrške', 'type' => 'checkbox', 'default' => true],
            'support_eyebrow' => $text('Nadnaslov kartice podrške', 'category_support.eyebrow'),
            'support_title' => $text('Naslov kartice podrške', 'category_support.title'),
            'support_description' => $text('Opis kartice podrške', 'category_support.description'),
            'support_contact_label' => $text('Oznaka poveznice za kontakt', 'category_support.contact'),
            'support_contact_url' => $url('Poveznica za kontakt'),
            'support_register_label' => $text('Oznaka poveznice za registraciju', 'category_support.register'),
            'support_register_url' => $url('Poveznica za registraciju'),
        ],
        'herrera_curated_products' => [
            'eyebrow' => $text('Nadnaslov', 'products_eyebrow'),
        ],
        'popular_brands' => [
            'eyebrow' => $text('Nadnaslov', 'brands_eyebrow'),
        ],
        'herrera_popular_products' => [
            'eyebrow' => $text('Nadnaslov', 'popular_products_eyebrow'),
            'days' => ['label' => 'Razdoblje prodaje u danima', 'type' => 'number', 'default' => 30, 'max' => 365],
        ],
        'herrera_registration' => [
            'eyebrow' => $text('Nadnaslov', 'registration.eyebrow'),
            'login_label' => $text('Oznaka poveznice za prijavu', 'registration.login'),
            'login_url' => $url('Poveznica za prijavu'),
            'note' => $text('Napomena uz registraciju', 'registration.note'),
            'prices_label' => $text('Naslov prednosti ugovorenih cijena', 'benefit_prices'),
            'prices_description' => $text('Opis prednosti ugovorenih cijena', 'registration.benefits.prices'),
            'quick_order_label' => $text('Naslov prednosti brze narudžbe', 'benefit_order'),
            'quick_order_description' => $text('Opis prednosti brze narudžbe', 'registration.benefits.order'),
            'orders_label' => $text('Naslov prednosti prethodnih narudžbi', 'benefit_history'),
            'orders_description' => $text('Opis prednosti prethodnih narudžbi', 'registration.benefits.history'),
        ],
        'blogs_carousel' => [
            'eyebrow' => $text('Nadnaslov', 'blog.eyebrow'),
        ],
    ],
];
