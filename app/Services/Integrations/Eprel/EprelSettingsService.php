<?php

namespace App\Services\Integrations\Eprel;

use App\Services\Settings\SystemSettingsService;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

class EprelSettingsService
{
    public const KEY_ENABLED = 'eprel_enabled';

    public const KEY_API_KEY = 'eprel_api_key';

    public const KEY_API_KEY_ENCRYPTED = 'eprel_api_key_encrypted';

    public const KEY_CONNECT_TIMEOUT = 'eprel_connect_timeout';

    public const KEY_TIMEOUT = 'eprel_timeout';

    public function __construct(private readonly SystemSettingsService $settings) {}

    public function enabled(): bool
    {
        $value = $this->value(self::KEY_ENABLED, 'msan_eprel_enabled', false);

        return is_bool($value) ? $value : in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'on'], true);
    }

    public function assertEnabled(): void
    {
        if (! $this->enabled()) {
            throw new RuntimeException('EPREL dohvat nije uključen.');
        }
    }

    public function hasApiKey(): bool
    {
        $encrypted = $this->value(self::KEY_API_KEY_ENCRYPTED, 'msan_eprel_api_key_encrypted', '');

        return is_string($encrypted) && trim($encrypted) !== '';
    }

    public function apiKey(): string
    {
        $encrypted = $this->value(self::KEY_API_KEY_ENCRYPTED, 'msan_eprel_api_key_encrypted', '');
        if (! is_string($encrypted) || trim($encrypted) === '') {
            throw new RuntimeException('EPREL API ključ nije postavljen.');
        }
        try {
            $value = Crypt::decryptString($encrypted);
        } catch (DecryptException) {
            throw new RuntimeException('EPREL API ključ nije moguće dešifrirati. Spremite ga ponovno.');
        }
        if (trim($value) === '') {
            throw new RuntimeException('EPREL API ključ je prazan. Spremite ga ponovno.');
        }

        return $value;
    }

    public function connectTimeout(): int
    {
        return $this->boundedInt($this->value(self::KEY_CONNECT_TIMEOUT, 'msan_eprel_connect_timeout', 10), 10, 2, 30);
    }

    public function timeout(): int
    {
        return $this->boundedInt($this->value(self::KEY_TIMEOUT, 'msan_eprel_timeout', 30), 30, 5, 120);
    }

    /** @return array<string, bool|int|string> */
    public function formValues(): array
    {
        return [
            self::KEY_ENABLED => $this->enabled(),
            self::KEY_API_KEY => '',
            self::KEY_CONNECT_TIMEOUT => $this->connectTimeout(),
            self::KEY_TIMEOUT => $this->timeout(),
        ];
    }

    /** @param array<string, mixed> $values */
    public function saveAdminValues(#[\SensitiveParameter] array $values): void
    {
        $entries = [];
        if (array_key_exists(self::KEY_ENABLED, $values)) {
            $value = $values[self::KEY_ENABLED];
            $entries[self::KEY_ENABLED] = is_bool($value) ? $value : in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'on'], true);
        }
        if (array_key_exists(self::KEY_CONNECT_TIMEOUT, $values)) {
            $entries[self::KEY_CONNECT_TIMEOUT] = $this->boundedInt($values[self::KEY_CONNECT_TIMEOUT], 10, 2, 30);
        }
        if (array_key_exists(self::KEY_TIMEOUT, $values)) {
            $entries[self::KEY_TIMEOUT] = $this->boundedInt($values[self::KEY_TIMEOUT], 30, 5, 120);
        }
        $key = $values[self::KEY_API_KEY] ?? '';
        if (! is_string($key)) {
            throw new InvalidArgumentException('EPREL API ključ nije ispravan.');
        }
        $key = trim($key);
        if (strlen($key) > 2048) {
            throw new InvalidArgumentException('EPREL API ključ je predugačak.');
        }
        if ($key !== '') {
            $entries[self::KEY_API_KEY_ENCRYPTED] = Crypt::encryptString($key);
        }
        if ($entries !== []) {
            DB::transaction(fn () => $this->settings->putMany($entries), 3);
        }
    }

    private function value(string $key, string $legacyKey, mixed $default): mixed
    {
        $values = $this->settings->all();

        // Prazna ili isključena nova postavka namjerno blokira stari fallback.
        return array_key_exists($key, $values) ? $values[$key] : ($values[$legacyKey] ?? $default);
    }

    private function boundedInt(mixed $value, int $default, int $min, int $max): int
    {
        return max($min, min($max, is_numeric($value) ? (int) $value : $default));
    }
}
