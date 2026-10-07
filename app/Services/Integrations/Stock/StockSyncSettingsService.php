<?php

namespace App\Services\Integrations\Stock;

use App\Services\Settings\SystemSettingsService;
use App\Support\Integrations\Stock\StockSyncRegistry;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use RuntimeException;

class StockSyncSettingsService
{
    public function __construct(private readonly SystemSettingsService $settings) {}

    public function enabled(string $supplier): bool
    {
        $definition = StockSyncRegistry::get($supplier);

        return (bool) $this->settings->get('stock_sync_'.$supplier.'_enabled', $definition['enabled']);
    }

    public function saveEnabled(string $supplier, bool $enabled): void
    {
        StockSyncRegistry::get($supplier);
        $this->settings->put('stock_sync_'.$supplier.'_enabled', $enabled);
    }

    public function configured(string $supplier): bool
    {
        try {
            $connection = $this->connection($supplier);
            if (! $this->validUrl((string) ($connection['url'] ?? ''))) {
                return false;
            }
            foreach ($supplier === 'eracuni' ? ['username', 'password', 'token'] : [] as $key) {
                if (trim((string) ($connection[$key] ?? '')) === '') {
                    return false;
                }
            }

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    public function connection(string $supplier): array
    {
        StockSyncRegistry::get($supplier);
        $encrypted = $this->settings->get('stock_sync_'.$supplier.'_connection', '');
        try {
            $value = $encrypted ? json_decode(Crypt::decryptString($encrypted), true, flags: JSON_THROW_ON_ERROR) : [];
        } catch (\Throwable) {
            throw new RuntimeException('Pristupne postavke nije moguće pročitati. Spremite ih ponovno.');
        }

        return is_array($value) ? $value : [];
    }

    public function saveConnection(string $supplier, #[\SensitiveParameter] array $connection): void
    {
        StockSyncRegistry::get($supplier);
        if (! $this->validUrl((string) ($connection['url'] ?? ''))) {
            throw new RuntimeException('Izvor mora biti potpuna HTTP ili HTTPS adresa.');
        }
        $this->settings->put('stock_sync_'.$supplier.'_connection', Crypt::encryptString(json_encode($connection, JSON_THROW_ON_ERROR)));
        $this->ensureToken($supplier);
    }

    public function ensureToken(string $supplier): void
    {
        StockSyncRegistry::get($supplier);
        if (! $this->settings->get('stock_sync_'.$supplier.'_token')) {
            $this->settings->put('stock_sync_'.$supplier.'_token', Crypt::encryptString(Str::random(64)));
        }
    }

    public function validToken(string $supplier, #[\SensitiveParameter] string $token): bool
    {
        $expected = $this->token($supplier);

        return $expected !== '' && $token !== '' && hash_equals($expected, $token);
    }

    public function cronUrl(string $supplier): string
    {
        $token = $this->token($supplier);

        return $token === '' ? '' : route('integrations.stock.run', ['supplier' => $supplier, 'token' => $token]);
    }

    public function attributesEnabled(): bool
    {
        return (bool) $this->settings->get('eracuni_attributes_cron_enabled', false);
    }

    public function saveAttributesEnabled(bool $enabled): void
    {
        $this->settings->put('eracuni_attributes_cron_enabled', $enabled);
    }

    public function attributesCronUrl(?string $codeFrom = null, ?string $codeTo = null): string
    {
        $token = $this->token('eracuni');
        if ($token === '') {
            return '';
        }

        return route('integrations.eracuni.attributes', array_filter([
            'token' => $token, 'codeFrom' => $codeFrom, 'codeTo' => $codeTo,
        ], static fn ($value): bool => $value !== null && $value !== ''));
    }

    private function token(string $supplier): string
    {
        StockSyncRegistry::get($supplier);
        try {
            $value = $this->settings->get('stock_sync_'.$supplier.'_token', '');

            return $value ? Crypt::decryptString($value) : '';
        } catch (\Throwable) {
            return '';
        }
    }

    private function validUrl(string $url): bool
    {
        return filter_var($url, FILTER_VALIDATE_URL) && in_array(parse_url($url, PHP_URL_SCHEME), ['http', 'https'], true)
            && ! parse_url($url, PHP_URL_USER) && ! parse_url($url, PHP_URL_PASS);
    }
}
