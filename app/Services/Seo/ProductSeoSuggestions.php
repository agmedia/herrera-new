<?php

namespace App\Services\Seo;

use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use RuntimeException;
use Throwable;

class ProductSeoSuggestions
{
    public const PROVIDERS = ['anthropic' => 'Claude', 'xai' => 'Grok'];

    public function availability(): array
    {
        return array_map(fn ($provider) => trim((string) config('ai.providers.'.$provider.'.key')) !== '', array_combine(array_keys(self::PROVIDERS), array_keys(self::PROVIDERS)));
    }

    public function facts(array $form): array
    {
        $text = static fn ($value, int $limit): string => mb_substr(trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags((string) $value), ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?? ''), 0, $limit);

        return [
            'locale' => $text($form['locale'] ?? '', 12),
            'name' => $text($form['name'] ?? '', 250),
            'model' => $text($form['code'] ?? '', 160),
            'excerpt' => $text($form['excerpt'] ?? '', 2000),
            'description' => $text($form['description'] ?? '', 6000),
        ];
    }

    public function fingerprint(array $form): string
    {
        return hash('sha256', json_encode($this->facts($form), JSON_THROW_ON_ERROR));
    }

    public function generate(array $form, string $provider, int $actorId): array
    {
        Validator::make(['provider' => $provider], ['provider' => ['required', Rule::in(array_keys(self::PROVIDERS))]])->validate();
        $facts = $this->facts($form);
        if ($facts['name'] === '' || ! in_array($facts['locale'], array_keys(config('app.locales', [])), true) && ! preg_match('/^[a-z]{2}(?:[-_][A-Z]{2})?$/D', $facts['locale'])) {
            throw new RuntimeException('Najprije unesite naziv artikla i odaberite jezik.');
        }
        if (! $this->availability()[$provider]) {
            throw new RuntimeException('Za odabrani AI servis još nije postavljen API pristup. Administrator treba povezati Claude ili Grok.');
        }
        $limiter = 'seo-ai:'.$actorId;
        if (RateLimiter::tooManyAttempts($limiter, 10)) {
            throw new RuntimeException('Dosegnuto je 10 prijedloga u minuti. Pričekajte kratko pa pokušajte ponovno.');
        }
        RateLimiter::hit($limiter, 60);
        try {
            $response = ProductSeoAgent::make()->prompt(json_encode(['product_facts' => $facts], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                provider: $provider, model: config('seo-ai.models.'.$provider), timeout: 45);
            $fields = Validator::make($response->structured, [
                'meta_title' => 'required|string|max:70', 'meta_description' => 'required|string|max:170',
            ])->validate();
            foreach ($fields as $key => $value) {
                $value = trim($value);
                if ($value === '' || $value !== strip_tags($value) || preg_match('/[\r\n\x00-\x1F]/u', $value)) {
                    throw new RuntimeException;
                }
                $fields[$key] = $value;
            }

            return $fields + ['provider' => $provider, 'fingerprint' => $this->fingerprint($form)];
        } catch (Throwable) {
            // Provider errors may contain request data, private keys or URLs.
            throw new RuntimeException('AI prijedlog nije moguće dohvatiti. Postojeći SEO podaci nisu promijenjeni. Provjerite pristup odabranom servisu i pokušajte ponovno.');
        }
    }
}
