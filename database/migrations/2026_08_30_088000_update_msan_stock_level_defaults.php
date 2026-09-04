<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @var array<int, int> */
    private const OLD_DEFAULTS = [0, 1, 1, 1, 1];

    /** @var array<int, int> */
    private const NEW_DEFAULTS = [0, 1, 3, 5, 10];

    public function up(): void
    {
        $this->replaceUnchangedDefaults(self::OLD_DEFAULTS, self::NEW_DEFAULTS);
    }

    public function down(): void
    {
        $this->replaceUnchangedDefaults(self::NEW_DEFAULTS, self::OLD_DEFAULTS);
    }

    /**
     * Preserve administrator choices and only upgrade values that still match
     * the previous application defaults.
     *
     * @param  array<int, int>  $expected
     * @param  array<int, int>  $replacement
     */
    private function replaceUnchangedDefaults(array $expected, array $replacement): void
    {
        if (! Schema::hasTable('system_settings')) {
            return;
        }

        $rows = DB::table('system_settings')
            ->whereIn('key', array_map(static fn (int $level): string => 'msan_stock_level_'.$level, range(0, 4)))
            ->get(['id', 'key', 'value'])
            ->keyBy('key');

        $current = [];
        foreach (range(0, 4) as $level) {
            $row = $rows->get('msan_stock_level_'.$level);
            $current[$level] = $row ? $this->decodeInteger($row->value) : null;
        }

        if ($current !== $expected) {
            return;
        }

        foreach ($replacement as $level => $quantity) {
            $row = $rows->get('msan_stock_level_'.$level);
            DB::table('system_settings')->where('id', $row->id)->update([
                'value' => json_encode($quantity, JSON_THROW_ON_ERROR),
                'updated_at' => now(),
            ]);
        }

        Cache::forget('settings.system.map');
    }

    private function decodeInteger(mixed $raw): ?int
    {
        if (! is_string($raw) || $raw === '') {
            return null;
        }

        $decoded = json_decode($raw, true);

        return json_last_error() === JSON_ERROR_NONE && is_numeric($decoded)
            ? (int) $decoded
            : null;
    }
};
