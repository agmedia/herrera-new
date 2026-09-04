<?php

namespace App\Services\Front;

use App\Models\User;
use App\Models\User\NewsletterSignup;
use App\Services\Integrations\Newsletter\KlaviyoNewsletterService;
use App\Services\Integrations\Newsletter\MailchimpNewsletterService;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;

class NewsletterSignupService
{
    public function __construct(
        private readonly StoreSettingsService $storeSettings,
        private readonly MailchimpNewsletterService $mailchimp,
        private readonly KlaviyoNewsletterService $klaviyo,
    ) {
    }

    /**
     * @return array{signup: NewsletterSignup|null, synced: bool}
     */
    public function subscribe(
        string $email,
        bool $consentAccepted,
        ?User $user = null,
        string $source = NewsletterSignup::SOURCE_FOOTER,
        ?string $locale = null,
        ?string $ipAddress = null,
        ?string $userAgent = null,
        ?string $referrer = null,
    ): array {
        $newsletterSettings = $this->storeSettings->newsletter();
        $provider = $this->normalizeProvider((string) ($newsletterSettings['provider'] ?? NewsletterSignup::PROVIDER_NONE));
        $normalizedEmail = Str::lower(trim($email));
        $now = now();

        if ($provider === NewsletterSignup::PROVIDER_NONE) {
            throw new RuntimeException((string) __('ui.front.desktop.newsletter.status.failed'));
        }

        if ($provider === NewsletterSignup::PROVIDER_DATABASE) {
            if (! Schema::hasTable('newsletter_signups')) {
                throw new RuntimeException((string) __('ui.front.desktop.newsletter.status.failed'));
            }

            $signup = NewsletterSignup::query()->firstOrNew(['email' => $normalizedEmail]);
            $payload = is_array($signup->payload) ? $signup->payload : [];

            $signup->fill([
                'user_id' => $signup->user_id ?: $user?->id,
                'email' => $normalizedEmail,
                'source' => $source,
                'locale' => trim((string) ($locale ?: app()->getLocale() ?: config('app.locale', 'hr'))) ?: 'hr',
                'provider' => $provider,
                'sync_status' => NewsletterSignup::SYNC_SYNCED,
                'consent_accepted' => $consentAccepted,
                'ip_address' => trim((string) $ipAddress) ?: null,
                'user_agent' => trim((string) $userAgent) ?: null,
                'subscribed_at' => $signup->subscribed_at ?: $now,
                'synced_at' => $now,
                'payload' => array_merge($payload, array_filter([
                    'last_referrer' => trim((string) $referrer) ?: null,
                    'last_seen_at' => $now->toIso8601String(),
                ], static fn ($value) => $value !== null)),
            ]);
            $signup->provider_error = null;
            $signup->save();

            return ['signup' => $signup->fresh(), 'synced' => true];
        }

        match ($provider) {
            NewsletterSignup::PROVIDER_MAILCHIMP => $this->mailchimp->subscribe($normalizedEmail, $newsletterSettings),
            NewsletterSignup::PROVIDER_KLAVIYO => $this->klaviyo->subscribe($normalizedEmail, $newsletterSettings),
            default => throw new RuntimeException('Newsletter provider nije podržan.'),
        };

        return ['signup' => null, 'synced' => true];
    }

    private function normalizeProvider(string $provider): string
    {
        $provider = Str::lower(trim($provider));

        return in_array($provider, [
            NewsletterSignup::PROVIDER_NONE,
            NewsletterSignup::PROVIDER_DATABASE,
            NewsletterSignup::PROVIDER_MAILCHIMP,
            NewsletterSignup::PROVIDER_KLAVIYO,
        ], true) ? $provider : NewsletterSignup::PROVIDER_NONE;
    }
}
