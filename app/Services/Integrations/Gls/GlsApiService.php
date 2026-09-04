<?php

namespace App\Services\Integrations\Gls;

use App\Services\Settings\SystemSettingsService;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Crypt;
use InvalidArgumentException;
use RuntimeException;

class GlsApiService
{
    private const TEST_BASE_URI = 'https://api.test.mygls.hr/ParcelService.svc/json/';

    private const LIVE_BASE_URI = 'https://api.mygls.hr/ParcelService.svc/json/';

    public function __construct(
        private readonly SystemSettingsService $settings,
        private readonly HttpFactory $http
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function defaults(): array
    {
        return [
            'gls_api_enabled' => false,
            'gls_api_mode' => 'test',
            'gls_api_username' => '',
            'gls_api_client_number' => '',
            'gls_api_pickup_name' => config('app.name', 'Termol'),
            'gls_api_pickup_contact_name' => '',
            'gls_api_pickup_contact_phone' => '',
            'gls_api_pickup_contact_email' => '',
            'gls_api_pickup_street' => '',
            'gls_api_pickup_address_line_2' => '',
            'gls_api_pickup_city' => '',
            'gls_api_pickup_postal_code' => '',
            'gls_api_pickup_country_code' => 'HR',
            'gls_api_printer_type' => 'A4_2x2',
            'gls_api_print_position' => 1,
            'gls_api_show_print_dialog' => false,
            'gls_api_verify_tls' => true,
        ];
    }

    /**
     * Safe values intended for the admin form. The saved password is never returned.
     *
     * @return array<string, mixed>
     */
    public function getSettings(): array
    {
        $values = [];
        foreach ($this->defaults() as $key => $defaultValue) {
            $values[$key] = $this->settings->get($key, $defaultValue);
        }

        $values['gls_api_password'] = '';
        $values['gls_api_password_configured'] = $this->hasStoredPassword();

        return $values;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function saveSettings(array $payload): void
    {
        $password = trim((string) ($payload['gls_api_password'] ?? ''));
        unset($payload['gls_api_password'], $payload['gls_api_password_configured']);

        if ($password !== '') {
            $payload['gls_api_password_encrypted'] = Crypt::encryptString($password);
        }

        $this->settings->putMany($payload);
    }

    public function enabledInSettings(): bool
    {
        return (bool) $this->settings->get('gls_api_enabled', false);
    }

    public function assertEnabled(): void
    {
        if (! $this->enabledInSettings()) {
            throw new RuntimeException('GLS integracija je isključena u modulu dostave.');
        }
    }

    public function endpointForMode(?string $mode = null): string
    {
        return $this->normalizeMode($mode) === 'live'
            ? self::LIVE_BASE_URI
            : self::TEST_BASE_URI;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>|null  $override
     * @return array<string, mixed>
     */
    public function printLabels(array $payload, ?array $override = null): array
    {
        return $this->request('PrintLabels', $payload, $override);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>|null  $override
     * @return array<string, mixed>
     */
    private function request(string $method, array $payload, ?array $override = null): array
    {
        $settings = array_merge($this->connectionSettings(), $override ?? []);
        $username = trim((string) ($settings['gls_api_username'] ?? ''));
        $clientNumber = trim((string) ($settings['gls_api_client_number'] ?? ''));
        $password = trim((string) ($settings['gls_api_password'] ?? ''));

        if ($username === '') {
            throw new InvalidArgumentException('GLS korisničko ime je obavezno.');
        }
        if ($clientNumber === '') {
            throw new InvalidArgumentException('GLS broj klijenta je obavezan.');
        }
        if ($password === '') {
            throw new InvalidArgumentException('GLS lozinka je obavezna.');
        }

        $payload['Username'] = $username;
        $payload['Password'] = $this->hashPasswordToBytes($password);

        $client = $this->http
            ->acceptJson()
            ->asJson()
            ->connectTimeout(15)
            ->timeout(45)
            ->retry(2, 500)
            ->withHeaders(['User-Agent' => 'Termol-GLS-Connector/1.0']);

        if (! (bool) ($settings['gls_api_verify_tls'] ?? true)) {
            $client = $client->withoutVerifying();
        }

        $response = $client->post(
            rtrim($this->endpointForMode((string) ($settings['gls_api_mode'] ?? 'test')), '/').'/'.$method,
            $payload
        );

        if (! $response->successful()) {
            throw new RuntimeException(sprintf(
                'GLS zahtjev nije uspio (%d): %s',
                $response->status(),
                trim($response->body()) !== '' ? trim($response->body()) : 'prazan odgovor'
            ));
        }

        $decoded = $response->json();
        if (! is_array($decoded)) {
            throw new RuntimeException('GLS odgovor nije valjan JSON objekt.');
        }

        return $decoded;
    }

    /**
     * @return array<string, mixed>
     */
    private function connectionSettings(): array
    {
        $settings = $this->getSettings();
        $encrypted = (string) $this->settings->get('gls_api_password_encrypted', '');
        $settings['gls_api_password'] = '';

        if ($encrypted !== '') {
            try {
                $settings['gls_api_password'] = Crypt::decryptString($encrypted);
            } catch (DecryptException) {
                throw new RuntimeException('Spremljena GLS lozinka ne može se dešifrirati. Spremite je ponovno.');
            }
        }

        return $settings;
    }

    private function hasStoredPassword(): bool
    {
        return trim((string) $this->settings->get('gls_api_password_encrypted', '')) !== '';
    }

    /**
     * @return list<int>
     */
    private function hashPasswordToBytes(string $password): array
    {
        $hash = hash('sha512', $password, true);

        return array_values(unpack('C*', $hash) ?: []);
    }

    private function normalizeMode(?string $mode): string
    {
        return strtolower(trim((string) $mode)) === 'live' ? 'live' : 'test';
    }
}
