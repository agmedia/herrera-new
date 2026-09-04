<?php

namespace App\Services\Integrations\Msan;

use App\Services\Settings\SystemSettingsService;
use Cron\CronExpression;
use Cron\FieldFactory;
use Cron\FieldInterface;
use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use RuntimeException;
use Throwable;

class MsanSettingsService
{
    /** @var array<string, bool> */
    private static array $priceStockCronValidityCache = [];

    public const KEY_ENABLED = 'msan_enabled';

    public const KEY_P12_PIN_ENCRYPTED = 'msan_p12_pin_encrypted';

    public const KEY_CONNECT_TIMEOUT = 'msan_connect_timeout';

    public const KEY_REQUEST_TIMEOUT = 'msan_request_timeout';

    public const KEY_PRICE_STOCK_SYNC_ENABLED = 'msan_price_stock_sync_enabled';

    public const KEY_PRICE_STOCK_SYNC_CRON = 'msan_price_stock_sync_cron';

    public const DEFAULT_PRICE_STOCK_SYNC_CRON = '*/15 * * * *';

    public const PRICE_STOCK_SYNC_TIMEZONE = 'Europe/Zagreb';

    public const KEY_IMPORT_IMAGES = 'msan_import_images';

    public const KEY_IMPORT_PRODUCTS_ACTIVE = 'msan_import_products_active';

    public const KEY_IMPORT_SPECIFICATIONS = 'msan_import_specifications';

    public const KEY_SPECIFICATIONS_SELECTED_ONLY = 'msan_specifications_selected_only';

    public const KEY_SPECIFICATIONS_SOURCE = 'msan_specifications_source';

    public const KEY_SPECIFICATIONS_TIMEOUT = 'msan_specifications_timeout';

    public const SPECIFICATIONS_SOURCE_STANDARD = 'standard';

    public const SPECIFICATIONS_SOURCE_ICECAT = 'icecat';

    public const KEY_STOCK_LEVEL_PREFIX = 'msan_stock_level_';

    /**
     * M SAN exposes availability levels rather than exact supplier stock.
     * These values are conservative local sellable-quantity limits.
     *
     * @var array<int, int>
     */
    public const STOCK_LEVEL_QUANTITY_DEFAULTS = [
        0 => 0,
        1 => 1,
        2 => 3,
        3 => 5,
        4 => 10,
    ];

    /** @var array<int, string> */
    public const AVAILABILITY_LEVEL_LABELS = [
        0 => 'Nije dostupno',
        1 => 'Niska dostupnost',
        2 => 'Srednja dostupnost',
        3 => 'Visoka dostupnost',
        4 => 'Vrlo visoka dostupnost',
    ];

    public const KEY_FTP_ENABLED = 'msan_ftp_enabled';

    public const KEY_FTP_USERNAME = 'msan_ftp_username';

    public const KEY_FTP_PASSWORD_ENCRYPTED = 'msan_ftp_password_encrypted';

    public const KEY_FTP_CONNECT_TIMEOUT = 'msan_ftp_connect_timeout';

    public const KEY_FTP_TIMEOUT = 'msan_ftp_timeout';

    public const KEY_EPREL_ENABLED = 'msan_eprel_enabled';

    public const KEY_EPREL_API_KEY_ENCRYPTED = 'msan_eprel_api_key_encrypted';

    public const KEY_EPREL_CONNECT_TIMEOUT = 'msan_eprel_connect_timeout';

    public const KEY_EPREL_TIMEOUT = 'msan_eprel_timeout';

    public function __construct(
        private readonly SystemSettingsService $settings,
    ) {}

    /**
     * Values safe to bind to an administrator form. Stored secrets are never returned.
     *
     * @return array<string, bool|int|string>
     */
    public function adminValues(): array
    {
        return [
            self::KEY_ENABLED => $this->enabled(),
            'msan_p12_pin' => '',
            'msan_p12_pin_configured' => $this->hasP12Pin(),
            self::KEY_CONNECT_TIMEOUT => $this->connectTimeout(),
            self::KEY_REQUEST_TIMEOUT => $this->requestTimeout(),
            self::KEY_PRICE_STOCK_SYNC_ENABLED => $this->priceStockSyncEnabled(),
            self::KEY_PRICE_STOCK_SYNC_CRON => $this->priceStockSyncCron(),
            self::KEY_IMPORT_IMAGES => $this->toBool($this->settings->get(self::KEY_IMPORT_IMAGES, true)),
            self::KEY_IMPORT_PRODUCTS_ACTIVE => $this->toBool($this->settings->get(self::KEY_IMPORT_PRODUCTS_ACTIVE, false)),
            self::KEY_IMPORT_SPECIFICATIONS => $this->importSpecifications(),
            self::KEY_SPECIFICATIONS_SELECTED_ONLY => $this->specificationsSelectedOnly(),
            self::KEY_SPECIFICATIONS_SOURCE => $this->specificationsSource(),
            self::KEY_SPECIFICATIONS_TIMEOUT => $this->specificationsTimeout(),
            self::KEY_STOCK_LEVEL_PREFIX.'0' => $this->stockLevelQuantity(0),
            self::KEY_STOCK_LEVEL_PREFIX.'1' => $this->stockLevelQuantity(1),
            self::KEY_STOCK_LEVEL_PREFIX.'2' => $this->stockLevelQuantity(2),
            self::KEY_STOCK_LEVEL_PREFIX.'3' => $this->stockLevelQuantity(3),
            self::KEY_STOCK_LEVEL_PREFIX.'4' => $this->stockLevelQuantity(4),
            self::KEY_FTP_ENABLED => $this->ftpEnabled(),
            self::KEY_FTP_USERNAME => trim((string) $this->settings->get(self::KEY_FTP_USERNAME, '')),
            'msan_ftp_password' => '',
            'msan_ftp_password_configured' => $this->hasFtpPassword(),
            self::KEY_FTP_CONNECT_TIMEOUT => $this->ftpConnectTimeout(),
            self::KEY_FTP_TIMEOUT => $this->ftpTimeout(),
            self::KEY_EPREL_ENABLED => $this->eprelEnabled(),
            'msan_eprel_api_key' => '',
            'msan_eprel_api_key_configured' => $this->hasEprelApiKey(),
            self::KEY_EPREL_CONNECT_TIMEOUT => $this->eprelConnectTimeout(),
            self::KEY_EPREL_TIMEOUT => $this->eprelTimeout(),
        ];
    }

    /**
     * Blank or omitted secret fields intentionally preserve the currently stored secret.
     * Unknown keys and encrypted-value keys supplied by a form are ignored.
     *
     * @param  array<string, mixed>  $values
     */
    public function saveAdminValues(#[\SensitiveParameter] array $values): void
    {
        $entries = [];

        if (array_key_exists(self::KEY_ENABLED, $values)) {
            $entries[self::KEY_ENABLED] = $this->toBool($values[self::KEY_ENABLED]);
        }
        if (array_key_exists(self::KEY_CONNECT_TIMEOUT, $values)) {
            $entries[self::KEY_CONNECT_TIMEOUT] = $this->boundedInt(
                $values[self::KEY_CONNECT_TIMEOUT],
                default: 15,
                min: 2,
                max: 60,
            );
        }
        if (array_key_exists(self::KEY_REQUEST_TIMEOUT, $values)) {
            $entries[self::KEY_REQUEST_TIMEOUT] = $this->boundedInt(
                $values[self::KEY_REQUEST_TIMEOUT],
                default: 120,
                min: 15,
                max: 300,
            );
        }
        if (array_key_exists(self::KEY_PRICE_STOCK_SYNC_ENABLED, $values)) {
            $entries[self::KEY_PRICE_STOCK_SYNC_ENABLED] = $this->toBool($values[self::KEY_PRICE_STOCK_SYNC_ENABLED]);
        }
        if (array_key_exists(self::KEY_PRICE_STOCK_SYNC_CRON, $values)) {
            $expression = trim((string) $values[self::KEY_PRICE_STOCK_SYNC_CRON]);
            $entries[self::KEY_PRICE_STOCK_SYNC_CRON] = self::isValidPriceStockSyncCron($expression)
                ? $expression
                : self::DEFAULT_PRICE_STOCK_SYNC_CRON;
        }
        if (array_key_exists(self::KEY_IMPORT_IMAGES, $values)) {
            $entries[self::KEY_IMPORT_IMAGES] = $this->toBool($values[self::KEY_IMPORT_IMAGES]);
        }
        if (array_key_exists(self::KEY_IMPORT_PRODUCTS_ACTIVE, $values)) {
            $entries[self::KEY_IMPORT_PRODUCTS_ACTIVE] = $this->toBool($values[self::KEY_IMPORT_PRODUCTS_ACTIVE]);
        }
        if (array_key_exists(self::KEY_IMPORT_SPECIFICATIONS, $values)) {
            $entries[self::KEY_IMPORT_SPECIFICATIONS] = $this->toBool($values[self::KEY_IMPORT_SPECIFICATIONS]);
        }
        if (array_key_exists(self::KEY_SPECIFICATIONS_SELECTED_ONLY, $values)) {
            $entries[self::KEY_SPECIFICATIONS_SELECTED_ONLY] = $this->toBool($values[self::KEY_SPECIFICATIONS_SELECTED_ONLY]);
        }
        if (array_key_exists(self::KEY_SPECIFICATIONS_SOURCE, $values)) {
            $source = strtolower(trim((string) $values[self::KEY_SPECIFICATIONS_SOURCE]));
            $entries[self::KEY_SPECIFICATIONS_SOURCE] = in_array($source, [
                self::SPECIFICATIONS_SOURCE_STANDARD,
                self::SPECIFICATIONS_SOURCE_ICECAT,
            ], true) ? $source : self::SPECIFICATIONS_SOURCE_STANDARD;
        }
        if (array_key_exists(self::KEY_SPECIFICATIONS_TIMEOUT, $values)) {
            $entries[self::KEY_SPECIFICATIONS_TIMEOUT] = $this->boundedInt(
                $values[self::KEY_SPECIFICATIONS_TIMEOUT],
                default: 1800,
                min: 300,
                max: 7200,
            );
        }
        foreach (range(0, 4) as $level) {
            $key = self::KEY_STOCK_LEVEL_PREFIX.$level;
            if (array_key_exists($key, $values)) {
                $entries[$key] = $this->boundedInt(
                    $values[$key],
                    default: self::STOCK_LEVEL_QUANTITY_DEFAULTS[$level],
                    min: 0,
                    max: 999999,
                );
            }
        }
        if (array_key_exists(self::KEY_FTP_ENABLED, $values)) {
            $entries[self::KEY_FTP_ENABLED] = $this->toBool($values[self::KEY_FTP_ENABLED]);
        }
        if (array_key_exists(self::KEY_FTP_USERNAME, $values)) {
            $entries[self::KEY_FTP_USERNAME] = mb_substr(trim((string) $values[self::KEY_FTP_USERNAME]), 0, 191);
        }
        if (array_key_exists(self::KEY_FTP_CONNECT_TIMEOUT, $values)) {
            $entries[self::KEY_FTP_CONNECT_TIMEOUT] = $this->boundedInt(
                $values[self::KEY_FTP_CONNECT_TIMEOUT],
                default: 15,
                min: 2,
                max: 60,
            );
        }
        if (array_key_exists(self::KEY_FTP_TIMEOUT, $values)) {
            $entries[self::KEY_FTP_TIMEOUT] = $this->boundedInt(
                $values[self::KEY_FTP_TIMEOUT],
                default: 120,
                min: 15,
                max: 120,
            );
        }
        if (array_key_exists(self::KEY_EPREL_ENABLED, $values)) {
            $entries[self::KEY_EPREL_ENABLED] = $this->toBool($values[self::KEY_EPREL_ENABLED]);
        }
        if (array_key_exists(self::KEY_EPREL_CONNECT_TIMEOUT, $values)) {
            $entries[self::KEY_EPREL_CONNECT_TIMEOUT] = $this->boundedInt(
                $values[self::KEY_EPREL_CONNECT_TIMEOUT],
                default: 10,
                min: 2,
                max: 30,
            );
        }
        if (array_key_exists(self::KEY_EPREL_TIMEOUT, $values)) {
            $entries[self::KEY_EPREL_TIMEOUT] = $this->boundedInt(
                $values[self::KEY_EPREL_TIMEOUT],
                default: 30,
                min: 5,
                max: 120,
            );
        }

        $p12Pin = (string) ($values['msan_p12_pin'] ?? '');
        if (trim($p12Pin) !== '') {
            $entries[self::KEY_P12_PIN_ENCRYPTED] = Crypt::encryptString($p12Pin);
        }

        $ftpPassword = (string) ($values['msan_ftp_password'] ?? '');
        if (trim($ftpPassword) !== '') {
            $entries[self::KEY_FTP_PASSWORD_ENCRYPTED] = Crypt::encryptString($ftpPassword);
        }

        $eprelApiKey = (string) ($values['msan_eprel_api_key'] ?? '');
        if (trim($eprelApiKey) !== '') {
            $entries[self::KEY_EPREL_API_KEY_ENCRYPTED] = Crypt::encryptString(trim($eprelApiKey));
        }

        if ($entries !== []) {
            $this->settings->putMany($entries);
        }

        if ($p12Pin !== '') {
            Cache::forget(MsanCertificateService::METADATA_CACHE_KEY);
        }
    }

    public function saveP12Pin(#[\SensitiveParameter] string $pin): void
    {
        if (trim($pin) === '') {
            throw new RuntimeException('PIN M SAN certifikata ne smije biti prazan.');
        }

        $this->settings->put(self::KEY_P12_PIN_ENCRYPTED, Crypt::encryptString($pin));
        Cache::forget(MsanCertificateService::METADATA_CACHE_KEY);
    }

    public function enabled(): bool
    {
        return $this->toBool($this->settings->get(self::KEY_ENABLED, false));
    }

    public function assertEnabled(): void
    {
        if (! $this->enabled()) {
            throw new RuntimeException('M SAN integracija nije uključena.');
        }
    }

    public function ftpEnabled(): bool
    {
        return $this->toBool($this->settings->get(self::KEY_FTP_ENABLED, false));
    }

    public function assertFtpEnabled(): void
    {
        $this->assertEnabled();

        if (! $this->ftpEnabled()) {
            throw new RuntimeException('M SAN FTP dohvat nije uključen.');
        }
    }

    public function connectTimeout(): int
    {
        return $this->boundedInt($this->settings->get(self::KEY_CONNECT_TIMEOUT, 15), 15, 2, 60);
    }

    public function requestTimeout(): int
    {
        return $this->boundedInt($this->settings->get(self::KEY_REQUEST_TIMEOUT, 120), 120, 15, 300);
    }

    public function priceStockSyncEnabled(): bool
    {
        // Availability was historically refreshed every 15 minutes without a
        // toggle, so enabled-by-default preserves the existing deployment.
        return $this->toBool($this->settings->get(self::KEY_PRICE_STOCK_SYNC_ENABLED, true));
    }

    public function priceStockSyncCron(): string
    {
        $expression = trim((string) $this->settings->get(
            self::KEY_PRICE_STOCK_SYNC_CRON,
            self::DEFAULT_PRICE_STOCK_SYNC_CRON,
        ));

        return self::isValidPriceStockSyncCron($expression)
            ? $expression
            : self::DEFAULT_PRICE_STOCK_SYNC_CRON;
    }

    public function priceStockSyncIsDue(?DateTimeInterface $at = null): bool
    {
        if (! $this->priceStockSyncEnabled()) {
            return false;
        }

        return (new CronExpression($this->priceStockSyncCron()))->isDue(
            $at ?? new DateTimeImmutable('now'),
            self::PRICE_STOCK_SYNC_TIMEZONE,
        );
    }

    public static function isValidPriceStockSyncCron(string $expression): bool
    {
        $expression = trim($expression);
        if (array_key_exists($expression, self::$priceStockCronValidityCache)) {
            return self::$priceStockCronValidityCache[$expression];
        }

        $valid = self::validatePriceStockSyncCron($expression);
        if (count(self::$priceStockCronValidityCache) >= 128) {
            array_shift(self::$priceStockCronValidityCache);
        }
        self::$priceStockCronValidityCache[$expression] = $valid;

        return $valid;
    }

    private static function validatePriceStockSyncCron(string $expression): bool
    {
        $parts = preg_split('/\s+/', $expression, -1, PREG_SPLIT_NO_EMPTY);
        if (mb_strlen($expression) > 100 || ! is_array($parts) || count($parts) !== 5
            || ! CronExpression::isValidExpression($expression)) {
            return false;
        }

        try {
            // CronExpression accepts some syntactically valid but impossible
            // calendar combinations (for example, 31 February). Require at
            // least one real run date before evaluating its daily cadence.
            (new CronExpression($expression))->getNextRunDate(
                new DateTimeImmutable('2000-01-01 00:00:00', new \DateTimeZone(self::PRICE_STOCK_SYNC_TIMEZONE)),
                0,
                true,
                self::PRICE_STOCK_SYNC_TIMEZONE,
            );
            $runMinutes = self::dailyCronRunMinutes($parts);
        } catch (Throwable) {
            return false;
        }

        if ($runMinutes === []) {
            return false;
        }

        foreach (array_slice($runMinutes, 1) as $index => $minute) {
            if ($minute - $runMinutes[$index] < 10) {
                return false;
            }
        }

        $overnightGap = 1440 - $runMinutes[array_key_last($runMinutes)] + $runMinutes[0];

        return $overnightGap >= 10 || ! self::cronCanRunOnConsecutiveDays($parts);
    }

    /**
     * @param  list<string>  $parts
     * @return list<int>
     */
    private static function dailyCronRunMinutes(array $parts): array
    {
        $factory = new FieldFactory;
        $minuteField = $factory->getField(CronExpression::MINUTE);
        $hourField = $factory->getField(CronExpression::HOUR);
        $date = new DateTimeImmutable('2000-01-01 00:00:00', new \DateTimeZone(self::PRICE_STOCK_SYNC_TIMEZONE));
        $matches = [];

        for ($hour = 0; $hour < 24; $hour++) {
            for ($minute = 0; $minute < 60; $minute++) {
                $candidate = $date->setTime($hour, $minute);
                if (self::cronFieldMatches($hourField, $candidate, $parts[CronExpression::HOUR])
                    && self::cronFieldMatches($minuteField, $candidate, $parts[CronExpression::MINUTE])) {
                    $matches[] = ($hour * 60) + $minute;
                }
            }
        }

        return $matches;
    }

    /**
     * The Gregorian weekday/date pattern repeats every 400 years. We only
     * need this scan when the daily time pattern crosses midnight in less
     * than ten minutes, and can stop at the first pair of eligible days.
     *
     * @param  list<string>  $parts
     */
    private static function cronCanRunOnConsecutiveDays(array $parts): bool
    {
        $factory = new FieldFactory;
        $dayField = $factory->getField(CronExpression::DAY);
        $monthField = $factory->getField(CronExpression::MONTH);
        $weekdayField = $factory->getField(CronExpression::WEEKDAY);
        $timezone = new \DateTimeZone(self::PRICE_STOCK_SYNC_TIMEZONE);
        $date = new DateTimeImmutable('2000-01-01 12:00:00', $timezone);
        $end = new DateTimeImmutable('2400-01-01 12:00:00', $timezone);
        $previousDayMatched = false;

        while ($date <= $end) {
            $monthMatches = self::cronFieldMatches($monthField, $date, $parts[CronExpression::MONTH]);
            $dayMatches = self::cronDateFieldsMatch($dayField, $weekdayField, $date, $parts);
            $currentDayMatches = $monthMatches && $dayMatches;
            if ($currentDayMatches && $previousDayMatched) {
                return true;
            }

            $previousDayMatched = $currentDayMatches;
            $date = $date->modify('+1 day');
        }

        return false;
    }

    /** @param list<string> $parts */
    private static function cronDateFieldsMatch(
        FieldInterface $dayField,
        FieldInterface $weekdayField,
        DateTimeInterface $date,
        array $parts,
    ): bool {
        $dayExpression = $parts[CronExpression::DAY];
        $weekdayExpression = $parts[CronExpression::WEEKDAY];
        $dayRestricted = ! in_array($dayExpression, ['*', '?'], true);
        $weekdayRestricted = ! in_array($weekdayExpression, ['*', '?'], true);
        $dayMatches = self::cronFieldMatches($dayField, $date, $dayExpression);
        $weekdayMatches = self::cronFieldMatches($weekdayField, $date, $weekdayExpression);

        if ($dayRestricted && $weekdayRestricted) {
            // This matches CronExpression's standard DOM-or-DOW behavior.
            return $dayMatches || $weekdayMatches;
        }

        if ($dayRestricted) {
            return $dayMatches;
        }

        return ! $weekdayRestricted || $weekdayMatches;
    }

    private static function cronFieldMatches(
        FieldInterface $field,
        DateTimeInterface $date,
        string $expression,
    ): bool {
        foreach (explode(',', $expression) as $part) {
            if ($field->isSatisfiedBy($date, trim($part), false)) {
                return true;
            }
        }

        return false;
    }

    public function stockLevelQuantity(int $level): int
    {
        if ($level < 0 || $level > 4) {
            throw new RuntimeException('M SAN razina zalihe mora biti između 0 i 4.');
        }

        $default = self::STOCK_LEVEL_QUANTITY_DEFAULTS[$level];

        return $this->boundedInt(
            $this->settings->get(self::KEY_STOCK_LEVEL_PREFIX.$level, $default),
            $default,
            0,
            999999,
        );
    }

    public function importSpecifications(): bool
    {
        return $this->toBool($this->settings->get(self::KEY_IMPORT_SPECIFICATIONS, true));
    }

    public function specificationsSelectedOnly(): bool
    {
        return $this->toBool($this->settings->get(self::KEY_SPECIFICATIONS_SELECTED_ONLY, true));
    }

    public function specificationsSource(): string
    {
        $source = strtolower(trim((string) $this->settings->get(
            self::KEY_SPECIFICATIONS_SOURCE,
            self::SPECIFICATIONS_SOURCE_STANDARD,
        )));

        return in_array($source, [self::SPECIFICATIONS_SOURCE_STANDARD, self::SPECIFICATIONS_SOURCE_ICECAT], true)
            ? $source
            : self::SPECIFICATIONS_SOURCE_STANDARD;
    }

    public function specificationsTimeout(): int
    {
        return $this->boundedInt($this->settings->get(self::KEY_SPECIFICATIONS_TIMEOUT, 1800), 1800, 300, 7200);
    }

    public static function availabilityLevelLabel(?int $level): string
    {
        if ($level === null) {
            return 'Nepoznata dostupnost';
        }

        return self::AVAILABILITY_LEVEL_LABELS[$level] ?? 'Nepoznata dostupnost';
    }

    /** @return array<int, int> */
    public function stockLevelQuantities(): array
    {
        $quantities = [];

        foreach (array_keys(self::STOCK_LEVEL_QUANTITY_DEFAULTS) as $level) {
            $quantities[$level] = $this->stockLevelQuantity($level);
        }

        return $quantities;
    }

    public function ftpConnectTimeout(): int
    {
        return $this->boundedInt($this->settings->get(self::KEY_FTP_CONNECT_TIMEOUT, 15), 15, 2, 60);
    }

    public function ftpTimeout(): int
    {
        return $this->boundedInt($this->settings->get(self::KEY_FTP_TIMEOUT, 120), 120, 15, 120);
    }

    public function eprelEnabled(): bool
    {
        return $this->toBool($this->settings->get(self::KEY_EPREL_ENABLED, false));
    }

    public function eprelConnectTimeout(): int
    {
        return $this->boundedInt($this->settings->get(self::KEY_EPREL_CONNECT_TIMEOUT, 10), 10, 2, 30);
    }

    public function eprelTimeout(): int
    {
        return $this->boundedInt($this->settings->get(self::KEY_EPREL_TIMEOUT, 30), 30, 5, 120);
    }

    public function eprelApiKey(): string
    {
        return $this->decryptSetting(self::KEY_EPREL_API_KEY_ENCRYPTED, 'EPREL API ključ');
    }

    public function p12Pin(): string
    {
        return $this->decryptSetting(self::KEY_P12_PIN_ENCRYPTED, 'PIN M SAN certifikata');
    }

    /**
     * @return array{username: string, password: string}
     */
    public function ftpCredentials(): array
    {
        $username = trim((string) $this->settings->get(self::KEY_FTP_USERNAME, ''));
        $password = $this->decryptSetting(self::KEY_FTP_PASSWORD_ENCRYPTED, 'M SAN FTP lozinka');

        if ($username === '') {
            throw new RuntimeException('M SAN FTP korisničko ime nije postavljeno.');
        }

        return ['username' => $username, 'password' => $password];
    }

    private function hasP12Pin(): bool
    {
        return trim((string) $this->settings->get(self::KEY_P12_PIN_ENCRYPTED, '')) !== '';
    }

    private function hasFtpPassword(): bool
    {
        return trim((string) $this->settings->get(self::KEY_FTP_PASSWORD_ENCRYPTED, '')) !== '';
    }

    public function hasEprelApiKey(): bool
    {
        return trim((string) $this->settings->get(self::KEY_EPREL_API_KEY_ENCRYPTED, '')) !== '';
    }

    private function decryptSetting(string $key, string $label): string
    {
        $encrypted = trim((string) $this->settings->get($key, ''));
        if ($encrypted === '') {
            throw new RuntimeException($label.' nije postavljen.');
        }

        try {
            $value = Crypt::decryptString($encrypted);
        } catch (DecryptException) {
            throw new RuntimeException($label.' nije moguće dešifrirati. Spremite ga ponovno.');
        }

        if ($value === '') {
            throw new RuntimeException($label.' je prazan. Spremite ga ponovno.');
        }

        return $value;
    }

    private function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value)) {
            return $value === 1;
        }

        return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'on'], true);
    }

    private function boundedInt(mixed $value, int $default, int $min, int $max): int
    {
        $number = is_numeric($value) ? (int) $value : $default;

        return max($min, min($max, $number));
    }
}
