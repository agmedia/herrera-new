<?php

namespace App\Support;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;

class ImportedDescriptionHtmlCleaner
{
    /** @var list<string> */
    private const BLOCKED_TAGS = [
        'base',
        'button',
        'embed',
        'form',
        'iframe',
        'input',
        'link',
        'math',
        'meta',
        'object',
        'script',
        'select',
        'style',
        'svg',
        'textarea',
    ];

    public function clean(string $value): string
    {
        $decoded = $this->decodeHtml($value);
        if ($decoded === '') {
            return '';
        }

        if (! class_exists(DOMDocument::class)) {
            return $this->cleanupWithRegex($decoded);
        }

        $previousUseInternalErrors = libxml_use_internal_errors(true);
        $document = new DOMDocument('1.0', 'UTF-8');
        $wrappedHtml = '<div data-import-root="1">'.$decoded.'</div>';
        $encodedHtml = mb_encode_numericentity(
            $wrappedHtml,
            [0x80, 0x10FFFF, 0, 0xFFFFFF],
            'UTF-8'
        );

        try {
            $loaded = $document->loadHTML(
                $encodedHtml,
                LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
            );

            if (! $loaded) {
                return $this->cleanupWithRegex($decoded);
            }

            $xpath = new DOMXPath($document);
            $root = $xpath->query('//*[@data-import-root="1"]')->item(0);

            if (! $root instanceof DOMElement) {
                return $this->cleanupWithRegex($decoded);
            }

            foreach (self::BLOCKED_TAGS as $tagName) {
                $this->removeElementsByTagName($root, $tagName);
            }
            $this->sanitizeAttributes($root);
            $this->unwrapElementsByTagName($root, 'span');

            return $this->normalizeHtml($this->innerHtml($root));
        } catch (\Throwable) {
            return $this->cleanupWithRegex($decoded);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previousUseInternalErrors);
        }
    }

    private function decodeHtml(string $value): string
    {
        $decoded = trim($value);
        if ($decoded === '') {
            return '';
        }

        for ($i = 0; $i < 2; $i++) {
            $next = html_entity_decode($decoded, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if ($next === $decoded) {
                break;
            }

            $decoded = $next;
        }

        return trim($decoded);
    }

    private function cleanupWithRegex(string $html): string
    {
        $blockedTags = implode('|', self::BLOCKED_TAGS);
        $cleaned = preg_replace("#<({$blockedTags})\\b[^>]*>.*?</\\1>#is", '', $html) ?? $html;
        $cleaned = preg_replace("#<({$blockedTags})\\b[^>]*/?>#is", '', $cleaned) ?? $cleaned;
        $cleaned = preg_replace('#</?span\b[^>]*>#i', '', $cleaned) ?? $cleaned;
        $cleaned = preg_replace('/\sstyle\s*=\s*(".*?"|\'.*?\'|[^\s>]+)/iu', '', $cleaned) ?? $cleaned;
        $cleaned = preg_replace('/\son[a-z0-9_-]+\s*=\s*(".*?"|\'.*?\'|[^\s>]+)/iu', '', $cleaned) ?? $cleaned;
        $cleaned = preg_replace('/\s(?:srcdoc|srcset|action|formaction)\s*=\s*(".*?"|\'.*?\'|[^\s>]+)/iu', '', $cleaned) ?? $cleaned;
        $cleaned = preg_replace_callback(
            '/\s(href|src|poster|background|dynsrc|lowsrc|xlink:href)\s*=\s*("([^"]*)"|\'([^\']*)\'|([^\s>]+))/iu',
            fn (array $matches): string => $this->isSafeUrl(
                (string) ($matches[3] ?? $matches[4] ?? $matches[5] ?? '')
            ) ? $matches[0] : '',
            $cleaned,
        ) ?? $cleaned;

        return $this->normalizeHtml($cleaned);
    }

    private function removeElementsByTagName(DOMElement $root, string $tagName): void
    {
        $nodes = [];
        foreach ($root->getElementsByTagName($tagName) as $node) {
            $nodes[] = $node;
        }

        foreach (array_reverse($nodes) as $node) {
            $node->parentNode?->removeChild($node);
        }
    }

    private function sanitizeAttributes(DOMNode $node): void
    {
        if ($node instanceof DOMElement) {
            $attributeNames = [];
            foreach ($node->attributes as $attribute) {
                $attributeNames[] = $attribute->nodeName;
            }

            foreach ($attributeNames as $attributeName) {
                $normalizedName = strtolower($attributeName);
                $remove = $normalizedName === 'style'
                    || str_starts_with($normalizedName, 'on')
                    || in_array($normalizedName, ['srcdoc', 'srcset', 'action', 'formaction'], true);

                if (
                    ! $remove
                    && in_array($normalizedName, ['href', 'src', 'poster', 'background', 'dynsrc', 'lowsrc', 'xlink:href'], true)
                    && ! $this->isSafeUrl($node->getAttribute($attributeName))
                ) {
                    $remove = true;
                }

                if ($remove) {
                    $node->removeAttribute($attributeName);
                }
            }
        }

        $children = [];
        foreach ($node->childNodes as $childNode) {
            $children[] = $childNode;
        }

        foreach ($children as $childNode) {
            $this->sanitizeAttributes($childNode);
        }
    }

    private function isSafeUrl(string $value): bool
    {
        $value = html_entity_decode(trim($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if ($value === '') {
            return true;
        }

        $withoutControls = preg_replace('/[\x00-\x20\x7F]+/u', '', $value) ?? $value;
        if (! preg_match('/^([a-z][a-z0-9+.-]*):/i', $withoutControls, $matches)) {
            return true;
        }

        return in_array(strtolower($matches[1]), ['http', 'https', 'mailto', 'tel'], true);
    }

    private function unwrapElementsByTagName(DOMElement $root, string $tagName): void
    {
        $nodes = [];
        foreach ($root->getElementsByTagName($tagName) as $node) {
            $nodes[] = $node;
        }

        foreach (array_reverse($nodes) as $node) {
            $parent = $node->parentNode;
            if (! $parent) {
                continue;
            }

            while ($node->firstChild) {
                $parent->insertBefore($node->firstChild, $node);
            }

            $parent->removeChild($node);
        }
    }

    private function innerHtml(DOMElement $element): string
    {
        $html = '';
        foreach ($element->childNodes as $childNode) {
            $html .= $element->ownerDocument?->saveHTML($childNode) ?? '';
        }

        return $html;
    }

    private function normalizeHtml(string $html): string
    {
        $normalized = str_replace("\u{00A0}", ' ', $html);
        $normalized = preg_replace('/>\s+</u', '><', $normalized) ?? $normalized;

        return trim($normalized);
    }
}
