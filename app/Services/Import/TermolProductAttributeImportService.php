<?php

namespace App\Services\Import;

use App\Models\Catalog\Attribute\Attribute;
use App\Models\Catalog\Product\Product;
use App\Models\User;
use App\Services\Settings\SystemSettingsService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class TermolProductAttributeImportService
{
    private const MANAGED_CODE_PREFIX = 'termol-spec-';

    /**
     * @var array<string, array{hr:string,en:string,sort:int}>
     */
    private const GROUPS = [
        'boja' => ['hr' => 'Boja', 'en' => 'Color', 'sort' => 10],
        'materijal' => ['hr' => 'Materijal kućišta', 'en' => 'Housing material', 'sort' => 20],
        'snaga' => ['hr' => 'Snaga', 'en' => 'Power', 'sort' => 30],
        'kapacitet' => ['hr' => 'Kapacitet', 'en' => 'Capacity', 'sort' => 40],
        'tezina' => ['hr' => 'Neto težina', 'en' => 'Net weight', 'sort' => 50],
        'dimenzije' => ['hr' => 'Dimenzije', 'en' => 'Dimensions', 'sort' => 60],
        'napon' => ['hr' => 'Napon', 'en' => 'Voltage', 'sort' => 70],
        'tlak' => ['hr' => 'Tlak pumpe', 'en' => 'Pump pressure', 'sort' => 80],
        'broj_brzina' => ['hr' => 'Broj brzina', 'en' => 'Speed levels', 'sort' => 90],
    ];

    /**
     * Groups with reusable values that are useful in storefront filters.
     *
     * @var array<int, string>
     */
    private const FILTER_GROUPS = [
        'boja',
        'materijal',
        'snaga',
        'kapacitet',
    ];

    public function __construct(
        private readonly SystemSettingsService $settings,
    ) {}

    /**
     * @return array{products_scanned:int,products_updated:int,attributes_created:int,attribute_links:int,filter_groups:array<int,string>}
     */
    public function sync(): array
    {
        $products = Product::query()
            ->where('code', 'like', 'termol-%')
            ->with([
                'translations' => fn ($query) => $query->where('locale', 'hr'),
                'attributes' => fn ($query) => $query->where('catalog_attributes.code', 'like', self::MANAGED_CODE_PREFIX.'%'),
            ])
            ->orderBy('id')
            ->get();

        $userId = User::query()->value('id');
        $managedAttributeIds = Attribute::query()
            ->where('code', 'like', self::MANAGED_CODE_PREFIX.'%')
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();
        $stats = [
            'products_scanned' => $products->count(),
            'products_updated' => 0,
            'attributes_created' => 0,
            'attribute_links' => 0,
            'filter_groups' => self::FILTER_GROUPS,
        ];

        DB::transaction(function () use ($products, $userId, $managedAttributeIds, &$stats): void {
            foreach ($products as $product) {
                $translation = $product->translations->first();
                $values = $this->extractAttributes(
                    (string) ($translation?->name ?? $product->code),
                    (string) ($translation?->description ?? '')
                );

                if ($managedAttributeIds !== []) {
                    $product->attributes()->detach($managedAttributeIds);
                }

                $links = [];
                foreach ($values as $groupCode => $value) {
                    $attribute = $this->upsertAttribute($groupCode, $value, $userId, $stats);
                    $links[$attribute->id] = ['sort_order' => self::GROUPS[$groupCode]['sort']];
                }

                if ($links !== []) {
                    $product->attributes()->syncWithoutDetaching($links);
                    $product->touch();
                    $stats['products_updated']++;
                    $stats['attribute_links'] += count($links);
                }

                Cache::forget('front:product:last-modified:'.$product->id);
            }

            Attribute::query()
                ->where('code', 'like', self::MANAGED_CODE_PREFIX.'%')
                ->whereDoesntHave('products')
                ->get()
                ->each
                ->delete();
        });

        $currentFilterGroups = $this->settings->get('store_product_filter_attribute_group_codes', []);
        $filterGroups = collect(is_array($currentFilterGroups) ? $currentFilterGroups : [])
            ->merge(self::FILTER_GROUPS)
            ->map(fn ($group): string => trim((string) $group))
            ->filter()
            ->unique()
            ->values()
            ->all();
        $this->settings->put('store_product_filter_attribute_group_codes', $filterGroups);
        $stats['filter_groups'] = $filterGroups;

        return $stats;
    }

    /**
     * @return array<string, string>
     */
    private function extractAttributes(string $name, string $description): array
    {
        $text = $this->plainText($description);
        $values = [];

        $color = $this->firstMatch($text, [
            '/\bboja\s+(?:kućišta|kucista|proizvoda)\s*:\s*([^\n]+)/iu',
        ]) ?: $this->colorFromName($name);
        if ($color !== null) {
            $values['boja'] = $this->normalizeLabel($color);
        }

        $material = $this->firstMatch($text, [
            '/\bmaterijal\s+kućišta\s*:\s*([^\n]+)/iu',
            '/\bku[ćc]išt[ea]\s+od\s+([^\n]+)/iu',
        ]);
        if ($material !== null) {
            $values['materijal'] = $this->normalizeMaterial($material);
        }

        $power = $this->firstMatch($text, [
            '/\bsnaga\s+motora\s*\(W\)\s*:\s*([0-9][0-9.,]*)/iu',
            '/\bsnaga\s*:?\s*([0-9][0-9.,]*)\s*W\b/iu',
        ]);
        if ($power !== null) {
            $values['snaga'] = $this->normalizeWholeNumber($power).' W';
        }

        $capacityLitres = $this->firstMatch($text, [
            '/\bkapacitet\s+(?:vrča|posude|spremnika)[^:\n]*\(l\)\s*:\s*([0-9][0-9.,]*)/iu',
        ]);
        if ($capacityLitres !== null) {
            $values['kapacitet'] = $this->normalizeDecimal($capacityLitres).' l';
        } else {
            $capacity = $this->firstMatch($text, [
                '/\bmaksimalni\s+kapacitet\s*:\s*([^\n]+)/iu',
                '/\bkapacitet\s+(?:vrča|posude|spremnika)[^:\n]*\s*:\s*([^\n]+)/iu',
                '/(?:^|\n)\s*[-–]?\s*(?:zapremnina|kapacitet)\s*:?\s*([0-9][^\n]+)/iu',
            ]);
            if ($capacity !== null) {
                $values['kapacitet'] = $this->normalizeSpecValue($capacity);
            }
        }

        $weight = $this->firstMatch($text, [
            '/\bneto\s+težina\s*:\s*([0-9][0-9.,]*)\s*kg\b/iu',
        ]);
        if ($weight !== null) {
            $values['tezina'] = $this->normalizeWeight($weight);
        }

        $dimensions = $this->firstMatch($text, [
            '/\bdimenzije\s*\([^)\n]*\)\s*[:.]?\s*([^\n]+)/iu',
        ]);
        if ($dimensions !== null) {
            $values['dimenzije'] = $this->normalizeDimensions($dimensions);
        }

        $voltage = $this->firstMatch($text, [
            '/\bpriključni\s+napon\s*\(V\)\s*:\s*([0-9]+\s*[\/-]\s*[0-9]+)/iu',
        ]);
        if ($voltage !== null) {
            $values['napon'] = preg_replace('/\s*[\/-]\s*/u', '–', trim($voltage)).' V';
        }

        $pressure = $this->firstMatch($text, [
            '/\btlak\s+pumpe[^0-9\n]*([0-9]+(?:[.,][0-9]+)?)\s*bara?\b/iu',
        ]);
        if ($pressure !== null) {
            $values['tlak'] = $this->normalizeDecimal($pressure).' bar';
        }

        $speeds = $this->firstMatch($text, [
            '/\bbroj\s+brzina\s*:\s*([0-9]+)/iu',
            '/(?:^|\n)\s*[-–]\s*([0-9]+)\s+brzine\b/iu',
        ]);
        if ($speeds !== null) {
            $values['broj_brzina'] = $this->normalizeWholeNumber($speeds);
        }

        return array_filter(
            $values,
            fn (string $value, string $group): bool => isset(self::GROUPS[$group]) && trim($value) !== '',
            ARRAY_FILTER_USE_BOTH
        );
    }

    /**
     * @param  array<int, string>  $patterns
     */
    private function firstMatch(string $text, array $patterns): ?string
    {
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $text, $matches) === 1) {
                $value = $this->normalizeSpecValue((string) ($matches[1] ?? ''));
                if ($value !== '') {
                    return $value;
                }
            }
        }

        return null;
    }

    private function plainText(string $html): string
    {
        $withLineBreaks = preg_replace('/<br\s*\/?>/i', "\n", $html) ?? $html;
        $text = html_entity_decode(strip_tags($withLineBreaks), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $lines = preg_split('/\R+/u', $text) ?: [];

        return collect($lines)
            ->map(fn (string $line): string => trim(preg_replace('/\s+/u', ' ', $line) ?? $line))
            ->filter()
            ->implode("\n");
    }

    private function normalizeSpecValue(string $value): string
    {
        $value = trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
        $value = preg_replace('/^[\-–:.\s]+/u', '', $value) ?? $value;

        return Str::limit(trim($value, " \t\n\r\0\x0B.;"), 190, '');
    }

    private function normalizeLabel(string $value): string
    {
        $value = $this->normalizeSpecValue($value);

        return $value === '' ? '' : Str::ucfirst(Str::lower($value));
    }

    private function normalizeMaterial(string $value): string
    {
        $value = preg_replace('/\s+u\s+boji\b/iu', '', $this->normalizeSpecValue($value)) ?? $value;
        $value = preg_replace('/\bnehrđaju[ćc]eg\s+čelika\b/iu', 'nehrđajući čelik', $value) ?? $value;
        $value = preg_replace('/\blijevanog\s+aluminija\b/iu', 'lijevani aluminij', $value) ?? $value;
        $value = preg_replace('/\bABS-a\b/iu', 'ABS plastika', $value) ?? $value;

        return Str::ucfirst($value);
    }

    private function normalizeWholeNumber(string $value): string
    {
        $digits = preg_replace('/[^0-9]/', '', $value) ?? '';

        return $digits !== '' ? (string) (int) $digits : trim($value);
    }

    private function normalizeDecimal(string $value): string
    {
        $numeric = (float) str_replace(',', '.', preg_replace('/[^0-9,.]/', '', $value) ?? '0');

        return rtrim(rtrim(number_format($numeric, 3, ',', ''), '0'), ',');
    }

    private function normalizeWeight(string $value): string
    {
        $numeric = (float) str_replace(',', '.', preg_replace('/[^0-9,.]/', '', $value) ?? '0');
        if ($numeric > 100) {
            $numeric /= 1000;
        }

        return rtrim(rtrim(number_format($numeric, 3, ',', ''), '0'), ',').' kg';
    }

    private function normalizeDimensions(string $value): string
    {
        $value = preg_replace('/\s*[x×]\s*/iu', ' × ', $this->normalizeSpecValue($value)) ?? $value;

        return preg_replace('/\s+/u', ' ', $value) ?? $value;
    }

    private function colorFromName(string $name): ?string
    {
        $normalized = Str::upper(Str::ascii($name));
        $colors = [
            'SMARAGDNO ZELENA MAT' => 'Smaragdno zelena mat',
            'PASTELNO PLAVA' => 'Pastelno plava',
            'PASTELNO ZELENA' => 'Pastelno zelena',
            'CHAMPAGNE MAT' => 'Champagne mat',
            'CRVENA' => 'Crvena',
            'KREM' => 'Krem',
            'CRNA' => 'Crna',
            'CRNI' => 'Crna',
        ];

        foreach ($colors as $needle => $label) {
            if (str_contains($normalized, $needle)) {
                return $label;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $stats
     */
    private function upsertAttribute(string $groupCode, string $value, ?int $userId, array &$stats): Attribute
    {
        $valueHash = substr(sha1(Str::lower($value)), 0, 12);
        $code = self::MANAGED_CODE_PREFIX.$groupCode.'-'.$valueHash;
        $attribute = Attribute::query()->firstOrNew(['code' => $code]);
        if (! $attribute->exists) {
            $attribute->created_by = $userId;
            $stats['attributes_created']++;
        }

        $attribute->fill([
            'group_code' => $groupCode,
            'type' => Attribute::TYPE_SELECT,
            'is_active' => true,
            'sort_order' => self::GROUPS[$groupCode]['sort'],
            'payload' => [
                'source' => 'termol.hr description',
                'managed_by' => class_basename(self::class),
                'raw_value' => $value,
            ],
            'updated_by' => $userId,
        ]);
        $attribute->save();

        foreach (['hr', 'en'] as $locale) {
            $attribute->translations()->updateOrCreate(
                ['locale' => $locale],
                [
                    'group_name' => self::GROUPS[$groupCode][$locale],
                    'name' => $value,
                    'slug' => $code,
                    'description' => null,
                    'payload' => [
                        'source' => 'termol.hr description',
                    ],
                ]
            );
        }

        return $attribute;
    }
}
