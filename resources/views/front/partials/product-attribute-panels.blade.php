@php
    $preferredAttributeOrder = ['sastav', 'kvaliteta', 'garancija'];
    $fallbackLabels = [
        'sastav' => 'Sastav',
        'kvaliteta' => 'Kvaliteta',
        'garancija' => 'Garancija',
    ];
    $attributes = $product->relationLoaded('attributes')
        ? $product->attributes->reject(
            fn ($attribute): bool => $attribute->isMsanManaged(),
        )
        : collect();
    $attributeOrder = collect($preferredAttributeOrder)
        ->merge($attributes->pluck('group_code')->map(fn ($groupCode): string => (string) $groupCode))
        ->filter()
        ->unique()
        ->values();

    $attributePanels = $attributeOrder
        ->map(function (string $groupCode) use ($attributes, $fallbackLabels, $locale, $fallbackLocale): ?array {
            $items = $attributes
                ->filter(fn ($attribute): bool => (string) $attribute->group_code === $groupCode)
                ->map(function ($attribute) use ($locale, $fallbackLocale): array {
                    $translation = $attribute->translations->firstWhere('locale', $locale)
                        ?? $attribute->translations->firstWhere('locale', $fallbackLocale)
                        ?? $attribute->translations->first();

                    return [
                        'id' => (int) $attribute->id,
                        'group_name' => trim((string) ($translation?->group_name ?? '')),
                        'name' => trim((string) ($translation?->name ?? $attribute->code)),
                    ];
                })
                ->filter(fn (array $item): bool => $item['name'] !== '')
                ->unique('name')
                ->values();

            if ($items->isEmpty()) {
                return null;
            }

            $groupName = $items
                ->pluck('group_name')
                ->first(fn (string $value): bool => $value !== '');

            return [
                'group_code' => $groupCode,
                'group_name' => $groupName ?: ($fallbackLabels[$groupCode] ?? \Illuminate\Support\Str::headline($groupCode)),
                'items' => $items->all(),
            ];
        })
        ->filter()
        ->values();

    $materialFeatureIconSets = [
        'giza_pamuk' => [
            ['label' => 'Prozračan', 'icon' => 'PROZRACAN.svg'],
            ['label' => 'Upijajući', 'icon' => 'UPOJNOST.svg'],
            ['label' => 'Izdržljiv', 'icon' => 'DUGOTRAJAN.svg'],
        ],
        'modal_pamuk' => [
            ['label' => 'Svilenkast', 'icon' => 'SVILENKASTI_DODIR.svg'],
            ['label' => 'Elastičan', 'icon' => 'ELASTICNOST.svg'],
            ['label' => 'Prozračan', 'icon' => 'PROZRACAN.svg'],
        ],
        'mikromodal' => [
            ['label' => 'Svilenkast', 'icon' => 'SVILENKASTI_DODIR.svg'],
            ['label' => 'Elastičan', 'icon' => 'ELASTICNOST.svg'],
            ['label' => 'Hipoalergen', 'icon' => 'HIPOALERGEN.svg'],
        ],
    ];

    $materialFeatureIcons = [];
    $materialPanel = $attributePanels->firstWhere('group_code', 'sastav')
        ?? $attributePanels->firstWhere('group_code', 'materijal');

    if ($materialPanel) {
        $materialText = collect($materialPanel['items'])->pluck('name')->implode(' ');
        $materialText = strtolower(\Illuminate\Support\Str::ascii($materialText));
        $materialText = preg_replace('/\s+/', ' ', $materialText) ?? $materialText;
        $isMikromodal = str_contains($materialText, 'mikromodal')
            || str_contains($materialText, 'mikro modal')
            || str_contains($materialText, 'micromodal')
            || str_contains($materialText, 'micro modal');

        if ($isMikromodal) {
            $materialFeatureIcons = $materialFeatureIconSets['mikromodal'];
        } elseif (str_contains($materialText, 'giza') && str_contains($materialText, 'pamuk')) {
            $materialFeatureIcons = $materialFeatureIconSets['giza_pamuk'];
        } elseif (str_contains($materialText, 'modal') && str_contains($materialText, 'pamuk')) {
            $materialFeatureIcons = $materialFeatureIconSets['modal_pamuk'];
        }
    }
@endphp

@if ($attributePanels->isNotEmpty())
    <div class="{{ trim($containerClass ?? '') }}" data-product-attribute-panels>
        <dl class="product-detail-attribute-grid">
            @foreach ($attributePanels as $panel)
                <div class="product-detail-attribute-card" data-product-attribute-group="{{ $panel['group_code'] }}">
                    <dt>{{ $panel['group_name'] }}</dt>
                    <dd>
                        @if (count($panel['items']) === 1)
                            {!! nl2br(e($panel['items'][0]['name'])) !!}
                        @else
                            <ul>
                                @foreach ($panel['items'] as $item)
                                    <li>{!! nl2br(e($item['name'])) !!}</li>
                                @endforeach
                            </ul>
                        @endif
                    </dd>

                    @if (in_array($panel['group_code'], ['sastav', 'materijal'], true) && count($materialFeatureIcons) > 0)
                        <div data-material-feature-grid aria-label="Karakteristike materijala">
                            @foreach ($materialFeatureIcons as $featureIcon)
                                <figure data-material-feature>
                                    <img
                                        src="{{ asset('assets/payments/'.$featureIcon['icon']) }}"
                                        alt=""
                                        data-material-feature-icon
                                        loading="lazy"
                                        decoding="async"
                                        aria-hidden="true"
                                    >
                                    <figcaption data-material-feature-label>{{ $featureIcon['label'] }}</figcaption>
                                </figure>
                            @endforeach
                        </div>
                    @endif
                </div>
            @endforeach
        </dl>
    </div>
@endif
