<?php

namespace App\Services\Import;

use App\Models\Content\Blog\BlogPost;
use App\Services\Settings\SystemSettingsService;
use App\Support\Media\HerreraBlogImage;
use Illuminate\Support\Facades\DB;
use LogicException;
use RuntimeException;

class HerreraBlogRefreshService
{
    public const PAYLOAD_VERSION_KEY = 'herrera_blog_refresh_v1';

    public function __construct(private readonly SystemSettingsService $settings) {}

    /** Refresh each existing Croatian article once; missing imports remain pending. */
    public function apply(): int
    {
        if (! config('commerce.b2b_only')
            || ! str_contains(strtolower((string) $this->settings->get('store_brand_name', '')), 'herrera')) {
            throw new LogicException('Blog refresh requires the Herrera B2B store.');
        }

        return DB::transaction(function (): int {
            $updated = 0;
            foreach ((array) config('herrera-blog-content', []) as $code => $content) {
                $post = BlogPost::query()->where('code', $code)->lockForUpdate()->first();
                if (! $post || data_get($post->payload, self::PAYLOAD_VERSION_KEY.'.applied_at')) {
                    continue;
                }

                $translation = $post->translations()->where('locale', 'hr')->lockForUpdate()->first();
                if (! $translation) {
                    continue;
                }

                $bodyPath = (string) ($content['body_path'] ?? '');
                $body = is_file($bodyPath) && is_readable($bodyPath) ? file_get_contents($bodyPath) : false;
                $imagePath = HerreraBlogImage::assetPath($content['image_path'] ?? null);
                if ($body === false || trim($body) === '' || $imagePath === null
                    || trim((string) ($content['title'] ?? '')) === '') {
                    throw new RuntimeException('Blog refresh source text or image is missing for '.$code.'.');
                }

                $payload = is_array($post->payload) ? $post->payload : [];
                $backup = [
                    'applied_at' => now()->toIso8601String(),
                    'translation' => $translation->attributesToArray(),
                    'post_payload' => $payload,
                ];
                $translation->fill([
                    'title' => (string) $content['title'],
                    'excerpt' => (string) ($content['excerpt'] ?? ''),
                    'body_html' => $body,
                    'meta_title' => (string) ($content['meta_title'] ?? $content['title']),
                    'meta_description' => (string) ($content['meta_description'] ?? $content['excerpt'] ?? ''),
                ])->save();
                $post->update(['payload' => array_replace($payload, [
                    'herrera_cover_path' => $imagePath,
                    self::PAYLOAD_VERSION_KEY => $backup,
                ])]);
                $updated++;
            }

            return $updated;
        });
    }
}
