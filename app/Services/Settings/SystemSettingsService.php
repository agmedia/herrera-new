<?php

namespace App\Services\Settings;

use App\Models\Settings\System\SystemSetting;
use Illuminate\Support\Facades\Cache;

class SystemSettingsService
{
    private const CACHE_KEY = 'settings.system.map';

    /**
     * @var array<string, mixed>|null
     */
    private ?array $settingsCache = null;

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        if ($this->settingsCache !== null) {
            return $this->settingsCache;
        }

        return $this->settingsCache = Cache::remember(self::CACHE_KEY, now()->addHours(4), function (): array {
            $rows = SystemSetting::query()->get(['key', 'value']);
            $map = [];

            foreach ($rows as $row) {
                $map[$row->key] = $this->decodeValue($row->value);
            }

            return $map;
        });
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $all = $this->all();

        return array_key_exists($key, $all) ? $all[$key] : $default;
    }

    public function getInt(string $key, int $default, int $min = 1, int $max = 200): int
    {
        $value = $this->get($key, $default);

        if (is_numeric($value)) {
            $value = (int) $value;
        } else {
            $value = $default;
        }

        return max($min, min($max, $value));
    }

    /**
     * @param  array<string, mixed>  $entries
     */
    public function putMany(array $entries): void
    {
        foreach ($entries as $key => $value) {
            SystemSetting::query()->updateOrCreate(
                ['key' => $key],
                ['value' => $this->encodeValue($value)]
            );
        }

        $this->flush();
    }

    public function put(string $key, mixed $value): void
    {
        $this->putMany([$key => $value]);
    }

    public function flush(): void
    {
        $this->clearRuntimeCache();

        Cache::forget(self::CACHE_KEY);
    }

    /**
     * Clear only the cache held by the current PHP process.
     *
     * Queue workers keep singleton services alive between jobs, so this must be
     * reset before each job to pick up settings changed from an admin request.
     */
    public function clearRuntimeCache(): void
    {
        $this->settingsCache = null;
    }

    private function encodeValue(mixed $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function decodeValue(?string $raw): mixed
    {
        if ($raw === null || $raw === '') {
            return null;
        }

        $decoded = json_decode($raw, true);

        return json_last_error() === JSON_ERROR_NONE ? $decoded : $raw;
    }
}
