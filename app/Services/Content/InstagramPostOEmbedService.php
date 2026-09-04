<?php

namespace App\Services\Content;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class InstagramPostOEmbedService
{
    private const INSTAGRAM_APP_ID = '936619743392459';

    /**
     * @return array{
     *     shortcode: string,
     *     canonical_url: string,
     *     author_name: string,
     *     media_kind: string,
     *     block_title: string,
     *     caption: string,
     *     alt: string,
     *     thumbnail_url: string,
     *     thumbnail_bytes: string,
     *     mime_type: string
     * }
     */
    public function fetch(string $url): array
    {
        [$pathType, $shortcode] = $this->extractPathTypeAndShortcode($url);
        $normalizedUrl = sprintf('https://www.instagram.com/%s/%s/', $pathType, $shortcode);
        $oEmbedUrl = 'https://www.instagram.com/api/v1/oembed/?url='.rawurlencode($normalizedUrl);

        $response = Http::timeout(15)
            ->retry(2, 250, throw: false)
            ->withHeaders([
                'x-ig-app-id' => self::INSTAGRAM_APP_ID,
                'Accept' => 'application/json',
            ])
            ->withUserAgent('Mozilla/5.0')
            ->get($oEmbedUrl);

        if (! $response->successful()) {
            throw new RuntimeException('Instagram objavu nije moguće dohvatiti s tog URL-a.');
        }

        $payload = $response->json();
        if (! is_array($payload)) {
            throw new RuntimeException('Instagram odgovor nije u očekivanom formatu.');
        }

        $thumbnailUrl = trim((string) ($payload['thumbnail_url'] ?? ''));
        if ($thumbnailUrl === '') {
            throw new RuntimeException('Instagram objava nema dostupnu preview sliku.');
        }

        $imageResponse = Http::timeout(20)
            ->retry(2, 250, throw: false)
            ->withUserAgent('Mozilla/5.0')
            ->get($thumbnailUrl);

        if (! $imageResponse->successful()) {
            throw new RuntimeException('Preview sliku s Instagrama nije moguće preuzeti.');
        }

        $thumbnailBytes = (string) $imageResponse->body();
        if ($thumbnailBytes === '') {
            throw new RuntimeException('Instagram preview slika je prazna.');
        }

        $caption = $this->normalizeText((string) ($payload['title'] ?? ''));
        $authorName = trim((string) ($payload['author_name'] ?? 'Instagram'));
        $canonicalUrl = $this->extractPermalink((string) ($payload['html'] ?? ''))
            ?: $normalizedUrl;
        $mediaKind = str_contains($canonicalUrl, '/reel/') ? 'video' : 'image';
        $blockTitle = $this->resolveBlockTitle($caption, $authorName);
        $alt = trim(sprintf('Instagram objava %s - %s', $authorName, $blockTitle));
        $mimeType = $this->normalizeMimeType((string) $imageResponse->header('Content-Type', 'image/jpeg'));

        return [
            'shortcode' => $shortcode,
            'canonical_url' => $canonicalUrl,
            'author_name' => $authorName,
            'media_kind' => $mediaKind,
            'block_title' => $blockTitle,
            'caption' => $caption,
            'alt' => $alt,
            'thumbnail_url' => $thumbnailUrl,
            'thumbnail_bytes' => $thumbnailBytes,
            'mime_type' => $mimeType,
        ];
    }

    /**
     * @return array{string,string}
     */
    private function extractPathTypeAndShortcode(string $url): array
    {
        $url = trim($url);
        if ($url === '') {
            throw new RuntimeException('Instagram URL je prazan.');
        }

        if (! preg_match('~instagram\.com/(p|reel|tv)/([A-Za-z0-9_-]+)~i', $url, $matches)) {
            throw new RuntimeException('Uneseni URL nije valjani Instagram post URL.');
        }

        return [strtolower((string) $matches[1]), (string) $matches[2]];
    }

    private function extractPermalink(string $html): ?string
    {
        if ($html === '') {
            return null;
        }

        if (! preg_match('~data-instgrm-permalink="([^"]+)"~i', $html, $matches)) {
            return null;
        }

        $permalink = html_entity_decode((string) $matches[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $permalink = preg_replace('~\?.*$~', '', $permalink) ?? $permalink;

        return trim($permalink) !== '' ? trim($permalink) : null;
    }

    private function normalizeText(string $value): string
    {
        $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = preg_replace("/[\r\n\t]+/u", ' ', $value) ?? $value;
        $value = preg_replace('/\s{2,}/u', ' ', $value) ?? $value;

        return trim($value);
    }

    private function resolveBlockTitle(string $caption, string $authorName): string
    {
        $source = $caption !== '' ? $caption : $authorName;
        $source = trim((string) Str::of($source)->before('#')->trim());

        return (string) Str::limit($source !== '' ? $source : 'Instagram objava', 44, '...');
    }

    private function normalizeMimeType(string $value): string
    {
        $value = strtolower(trim(strtok($value, ';') ?: ''));

        return str_starts_with($value, 'image/') ? $value : 'image/jpeg';
    }
}
