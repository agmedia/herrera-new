<?php

namespace App\Services\Integrations\Newsletter;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class MailchimpNewsletterService
{
    /**
     * @param  array<string, mixed>  $settings
     * @return array{provider_reference: string}
     */
    public function subscribe(string $email, array $settings): array
    {
        $apiKey = trim((string) ($settings['mailchimp_api_key'] ?? ''));
        $listId = trim((string) ($settings['mailchimp_list_id'] ?? ''));

        if ($apiKey === '' || $listId === '') {
            throw new RuntimeException('Mailchimp postavke nisu potpune.');
        }

        $dataCenter = trim((string) Str::afterLast($apiKey, '-'));
        if ($dataCenter === '' || $dataCenter === $apiKey) {
            throw new RuntimeException('Mailchimp API key mora sadržavati server prefix.');
        }

        $normalizedEmail = Str::lower(trim($email));
        $subscriberHash = md5($normalizedEmail);
        $endpoint = sprintf(
            'https://%s.api.mailchimp.com/3.0/lists/%s/members/%s',
            $dataCenter,
            rawurlencode($listId),
            $subscriberHash
        );

        $response = Http::timeout(15)
            ->retry(1, 250, throw: false)
            ->acceptJson()
            ->withBasicAuth('newsletter', $apiKey)
            ->put($endpoint, [
                'email_address' => $normalizedEmail,
                'status_if_new' => 'subscribed',
                'status' => 'subscribed',
            ]);

        if (! $response->successful()) {
            $detail = trim((string) ($response->json('detail') ?? $response->json('title') ?? ''));
            throw new RuntimeException($detail !== '' ? $detail : 'Mailchimp sinkronizacija nije uspjela.');
        }

        return [
            'provider_reference' => (string) ($response->json('id') ?? $subscriberHash),
        ];
    }
}
