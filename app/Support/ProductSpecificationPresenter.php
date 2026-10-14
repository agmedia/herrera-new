<?php

namespace App\Support;

use App\Models\Catalog\Product\Product;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class ProductSpecificationPresenter
{
    /** A compact, read-only view of canonical, imported and explicitly labelled description facts. */
    public function compact(Product $product, string $locale, string $fallbackLocale, string $description = ''): array
    {
        $rows = collect();
        if ($product->relationLoaded('attributes')) {
            foreach ($product->attributes->reject(fn ($attribute): bool => $attribute->isMsanManaged())->groupBy('group_code') as $code => $attributes) {
                $values = $attributes->map(function ($attribute) use ($locale, $fallbackLocale): array {
                    $translation = $attribute->translations->firstWhere('locale', $locale)
                        ?? $attribute->translations->firstWhere('locale', $fallbackLocale)
                        ?? $attribute->translations->first();

                    return ['label' => trim((string) ($translation?->group_name ?: Str::headline($attribute->group_code))),
                        'value' => trim((string) ($translation?->name ?: $attribute->code))];
                })->filter(fn ($row): bool => $row['value'] !== '')->unique('value');
                foreach ($values as $value) {
                    $rows->push($value);
                }
            }
        }
        foreach ($this->remainingRows($product, $locale, $fallbackLocale) as $row) {
            $rows->push(['label' => trim((string) $row->item_name), 'value' => $this->valueText($row)]);
        }
        $facts = $rows->merge($this->descriptionRows($description))
            ->map(fn ($row): array => ['label' => $this->plainText($row['label']), 'value' => $this->plainText($row['value'])])
            ->filter(fn ($row): bool => $row['label'] !== '' && $row['value'] !== '')
            ->unique(fn ($row): string => $this->pairKey($row['label'], $row['value']))->values();
        $rows = $facts->groupBy(fn ($row): string => Str::lower(trim($row['label'])))
            ->map(fn ($group): array => ['label' => $group->first()['label'], 'value' => $group->pluck('value')->unique()->implode(', ')])->values();

        return [
            'primary' => $rows->filter(fn ($row): bool => $this->primaryRank($row['label']) !== null)
                ->sortBy(fn ($row): int => $this->primaryRank($row['label']))->values(),
            'secondary' => $rows->reject(fn ($row): bool => $this->primaryRank($row['label']) !== null)->values(),
            'description' => $this->withoutDisplayedFacts($description, $facts),
        ];
    }

    private function primaryRank(string $label): ?int
    {
        $label = Str::lower(Str::ascii($label));
        foreach (['/(?:boja svjetl|temperatura boj|light colou?r|colou?r temperature|cct)/' => 0,
            '/^(?:snaga|nazivna snaga|wattage|power|rated power)\b(?!\s*factor)/' => 1, '/(?:lumen|svjetlosni tok|luminous flux)/' => 2,
            '/(?:jamstvo|garancij|warranty|guarantee)/' => 3,
            '/(?:dimenzij|dimension)|^(?:duzina|sirina|visina|promjer|dubina|length|width|height|diameter|depth)\b/' => 4] as $pattern => $rank) {
            if (preg_match($pattern, $label)) {
                return $rank;
            }
        }

        return null;
    }

    private function labelledLine(string $text): ?array
    {
        $pair = $this->rawPair($text);
        if (! $pair) {
            return null;
        }
        $label = $pair['label'];
        // Only recognizable product facts are extracted from prose; arbitrary text and URLs stay in the description.
        $normalized = Str::lower(Str::ascii($label));
        if ($this->primaryRank($label) === null && ! preg_match('/^(?:boja|color|colour|materijal|material|napon|voltage|struja|current|ip\b|ip\s|zastita|stupanj zastite|tezina|masa|weight|frekvencija|frequency|grlo|podnozje|socket|dimabil|dimmable|prigusiv|kut|angle|cri\b|indeks reprodukcije|pakiranje|packaging|duzina|sirina|visina|promjer|length|width|height|diameter|radna temperatura|working temperature|energetska klasa|razina energetske|energy class|razred|kategorija boj|ekvivalent|faktor snage|vrijeme pocetka|zivotni vijek|broj ciklusa)/', $normalized)) {
            return null;
        }

        return $pair;
    }

    private function rawPair(string $text): ?array
    {
        $text = $this->plainText(strip_tags(preg_replace('~<(?![/!?A-Za-z])~u', '&lt;', $text) ?? $text));

        return preg_match('/^([^:\n]{1,80}):\s*(.{1,500})$/u', $text, $match)
            ? ['label' => trim($match[1]), 'value' => trim($match[2])]
            : null;
    }

    private function descriptionRows(string $description): Collection
    {
        if (trim($description) === '' || strlen($description) > 1000000) {
            return collect();
        }
        [$dom, $root] = $this->descriptionDom($description);
        $rows = collect();
        $xpath = new DOMXPath($dom);
        foreach ($xpath->query('.//tr', $root) as $row) {
            $cells = $xpath->query('./th | ./td', $row);
            if ($cells->length === 2) {
                $pair = $this->labelledLine(trim($cells->item(0)->textContent).': '.trim($cells->item(1)->textContent));
                if ($pair) {
                    $rows->push($pair);
                }
            }
        }
        $text = preg_replace('~<br\s*/?>|</(?:p|div|li)>~i', "\n", $description);
        $text = strip_tags(preg_replace('~<(?![/!?A-Za-z])~u', '&lt;', $text) ?? $text);
        foreach (preg_split('/\R/u', $text) ?: [] as $line) {
            if ($pair = $this->labelledLine($line)) {
                $rows->push($pair);
            }
        }

        return $rows->take(200)->values();
    }

    private function withoutDisplayedFacts(string $description, Collection $rows): string
    {
        if ($rows->isEmpty() || trim($description) === '' || strlen($description) > 1000000) {
            return $description;
        }
        $displayed = $rows->mapWithKeys(fn ($row): array => [$this->pairKey($row['label'], $row['value']) => true]);
        [$dom, $root] = $this->descriptionDom($description);
        $xpath = new DOMXPath($dom);
        foreach (iterator_to_array($xpath->query('.//tr', $root)) as $row) {
            $cells = $xpath->query('./th | ./td', $row);
            if ($cells->length !== 2) {
                continue;
            }
            if ($this->hasLinkedOrMediaContent($this->innerHtml($dom, $row))) {
                continue;
            }
            $pair = $this->rawPair(trim($cells->item(0)->textContent).': '.trim($cells->item(1)->textContent));
            if ($pair && $displayed->has($this->pairKey($pair['label'], $pair['value']))) {
                $row->parentNode->removeChild($row);
            }
        }
        foreach (iterator_to_array($xpath->query('.//table', $root)) as $table) {
            if ($xpath->query('.//tr | .//td | .//th', $table)->length === 0) {
                $table->parentNode?->removeChild($table);
            }
        }
        foreach (iterator_to_array($xpath->query('.//p | .//li | .//div[not(.//p or .//div or .//table)]', $root)) as $block) {
            $html = $this->innerHtml($dom, $block);
            $lines = preg_split('~<br\s*/?>|\R~i', $html) ?: [];
            $remaining = array_filter($lines, function (string $line) use ($displayed): bool {
                if ($this->hasLinkedOrMediaContent($line)) {
                    return true;
                }
                $pair = $this->rawPair($line);

                return ! $pair || ! $displayed->has($this->pairKey($pair['label'], $pair['value']));
            });
            if (count($remaining) === count($lines)) {
                continue;
            }
            // Imported <br> + newlines otherwise become empty lines after a fact is removed.
            $remaining = array_filter($remaining, fn (string $line): bool => trim($line) !== '');
            $remainingHtml = implode('<br>', $remaining);
            if (trim(strip_tags($remainingHtml)) === '' && ! preg_match('~<(?:img|picture|svg|video|audio|iframe|object|canvas|hr)\b~i', $remainingHtml)) {
                $block->parentNode?->removeChild($block);
                continue;
            }
            [, $fragmentRoot] = $this->descriptionDom($remainingHtml);
            while ($block->firstChild) {
                $block->removeChild($block->firstChild);
            }
            foreach (iterator_to_array($fragmentRoot->childNodes) as $child) {
                $block->appendChild($dom->importNode($child, true));
            }
        }
        // A labelled fact may span several inline nodes, e.g. <strong>Snaga:</strong> 20 W<br>.
        $inline = [];
        $removeDisplayedInline = function () use (&$inline, $dom, $root, $displayed): void {
            $html = implode('', array_map(fn ($node) => $dom->saveHTML($node), $inline));
            $pair = $this->rawPair($html);
            if ($pair && $displayed->has($this->pairKey($pair['label'], $pair['value'])) && ! $this->hasLinkedOrMediaContent($html)) {
                foreach ($inline as $node) {
                    if ($node->parentNode === $root) {
                        $root->removeChild($node);
                    }
                }
            }
            $inline = [];
        };
        foreach (iterator_to_array($root->childNodes) as $child) {
            if ($child instanceof DOMElement && preg_match('/^(?:p|div|table|ul|ol|li|section|figure|h[1-6])$/i', $child->tagName)) {
                $removeDisplayedInline();
            } else {
                $inline[] = $child;
                if ($child instanceof DOMElement && strtolower($child->tagName) === 'br') {
                    $removeDisplayedInline();
                }
            }
        }
        $removeDisplayedInline();
        // Handle plain-text newlines after inline facts so their adjacent <br> is removed with them.
        foreach (iterator_to_array($root->childNodes) as $child) {
            if ($child->nodeType === XML_TEXT_NODE && str_contains($child->nodeValue, "\n")) {
                $child->nodeValue = implode("\n", array_filter(preg_split('/\R/u', $child->nodeValue) ?: [], function ($line) use ($displayed): bool {
                    $pair = $this->rawPair($line);

                    return ! $pair || ! $displayed->has($this->pairKey($pair['label'], $pair['value']));
                }));
            }
        }

        return trim($this->innerHtml($dom, $root));
    }

    private function hasLinkedOrMediaContent(string $html): bool
    {
        return (bool) preg_match('~<(?:a|img|picture|svg|video|audio|iframe|object|canvas)\b~i', $html);
    }

    private function plainText(string $value): string
    {
        // Imported values can contain one or more layers of HTML entity escaping.
        // Decode text only; Blade still escapes the final value when rendering it.
        for ($pass = 0; $pass < 4; $pass++) {
            $decoded = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if ($decoded === $value) {
                break;
            }
            $value = $decoded;
        }
        $value = trim(preg_replace('/\s+/u', ' ', str_replace("\u{00A0}", ' ', $value)) ?? $value);

        return preg_replace('/([<>≤≥])\s*(?=\d)/u', '$1 ', $value) ?? $value;
    }

    private function descriptionDom(string $html): array
    {
        $dom = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        try {
            $dom->loadHTML('<?xml encoding="UTF-8"><div id="product-facts-source">'.$html.'</div>', LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        return [$dom, $dom->getElementById('product-facts-source')];
    }

    private function innerHtml(DOMDocument $dom, DOMElement $element): string
    {
        $html = '';
        foreach ($element->childNodes as $child) {
            $html .= $dom->saveHTML($child);
        }

        return $html;
    }

    /**
     * Keep imported specifications that are not already displayed by an attribute
     * panel. This is a presentation-only filter: both source records are retained.
     */
    public function remainingRows(Product $product, string $locale, string $fallbackLocale): Collection
    {
        if (! $product->relationLoaded('technicalSpecificationRows')) {
            return collect();
        }

        $displayedPairs = [];
        $attributes = $product->relationLoaded('attributes')
            ? $product->attributes->reject(fn ($attribute): bool => $attribute->isMsanManaged())
            : collect();

        foreach ($attributes->groupBy('group_code') as $groupCode => $groupAttributes) {
            $items = $groupAttributes->map(function ($attribute) use ($locale, $fallbackLocale): array {
                $translation = $attribute->translations->firstWhere('locale', $locale)
                    ?? $attribute->translations->firstWhere('locale', $fallbackLocale)
                    ?? $attribute->translations->first();

                return [
                    'label' => trim((string) ($translation?->group_name ?? '')),
                    'value' => trim((string) ($translation?->name ?? $attribute->code)),
                ];
            })->filter(fn (array $item): bool => $item['value'] !== '')
                ->unique('value')->values();

            if ($items->isEmpty()) {
                continue;
            }

            $fallbackLabels = ['sastav' => 'Sastav', 'kvaliteta' => 'Kvaliteta', 'garancija' => 'Garancija'];
            $label = $items->pluck('label')->first(fn (string $value): bool => $value !== '')
                ?: ($fallbackLabels[$groupCode] ?? Str::headline((string) $groupCode));

            foreach ($items as $item) {
                $displayedPairs[$this->pairKey($label, $item['value'])] = true;
            }
            $displayedPairs[$this->pairKey($label, $items->pluck('value')->implode(', '))] = true;
        }

        return $product->technicalSpecificationRows
            ->filter(function ($row) use (&$displayedPairs): bool {
                $label = trim((string) ($row->item_name ?? ''));
                $value = $this->valueText($row);
                if ($label === '' || $value === '') {
                    return false;
                }

                $pair = $this->pairKey($label, $value);
                if (isset($displayedPairs[$pair])) {
                    return false;
                }

                $displayedPairs[$pair] = true;

                return true;
            })->values();
    }

    public function valueText(object $row): string
    {
        $value = collect((array) ($row->values ?? []))->flatten()
            ->filter(fn ($value): bool => is_scalar($value) && trim((string) $value) !== '')
            ->map(fn ($value): string => trim((string) $value))
            ->unique()->implode(', ');
        $measure = trim((string) ($row->measure ?? ''));

        return $value !== '' && $measure !== '' && ! Str::endsWith(Str::lower($value), Str::lower($measure))
            ? $value.' '.$measure
            : $value;
    }

    private function pairKey(string $label, string $value): string
    {
        $normalize = fn (string $text): string => Str::lower($this->plainText($text));

        $value = preg_replace('/(?<=\d)\s+(?=(?:kw|w|v|ma|a|khz|hz|lm|k|mm|cm|m|kg|g|%)(?:\b|$))/iu', '', $normalize($value));

        return hash('sha256', serialize([$normalize($label), $value]));
    }
}
