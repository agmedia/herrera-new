<?php

namespace App\Services\Integrations\Newsletter;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class KlaviyoNewsletterService
{
    private const REVISION = '2024-10-15';

    /**
     * @param  array<string, mixed>  $settings
     * @return array{provider_reference: string}
     */
    public function subscribe(string $email, array $settings): array
    {
        $apiKey = trim((string) ($settings['klaviyo_api_key'] ?? ''));
        $listId = trim((string) ($settings['klaviyo_list_id'] ?? ''));

        if ($apiKey === '' || $listId === '') {
            throw new RuntimeException('Klaviyo postavke nisu potpune.');
        }

        $response = Http::timeout(15)
            ->retry(1, 250, throw: false)
            ->acceptJson()
            ->withHeaders([
                'Authorization' => 'Klaviyo-API-Key '.$apiKey,
                'revision' => self::REVISION,
            ])
            ->post('https://a.klaviyo.com/api/profile-subscription-bulk-create-jobs/', [
                'data' => [
                    'type' => 'profile-subscription-bulk-create-job',
                    'attributes' => [
                        'custom_source' => 'Footer newsletter form',
                        'profiles' => [
                            'data' => [
                                [
                                    'type' => 'profile',
                                    'attributes' => [
                                        'email' => trim($email),
                                    ],
                                ],
                            ],
                        ],
                    ],
                    'relationships' => [
                        'list' => [
                            'data' => [
                                'type' => 'list',
                                'id' => $listId,
                            ],
                        ],
                    ],
                ],
            ]);

        if (! $response->successful()) {
            $error = '';
            $errors = $response->json('errors');
            if (is_array($errors) && isset($errors[0]['detail'])) {
                $error = trim((string) $errors[0]['detail']);
            }
            if ($error === '') {
                $error = trim((string) ($response->json('message') ?? ''));
            }

            throw new RuntimeException($error !== '' ? $error : 'Klaviyo sinkronizacija nije uspjela.');
        }

        return [
            'provider_reference' => (string) ($response->json('data.id') ?? ''),
        ];
    }
}
