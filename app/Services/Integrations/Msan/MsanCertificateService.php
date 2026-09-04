<?php

namespace App\Services\Integrations\Msan;

use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class MsanCertificateService
{
    public const METADATA_CACHE_KEY = 'integrations:msan:certificate-metadata';

    public const STORAGE_PATH = 'integrations/msan/certificates/client.p12';

    public const CA_STORAGE_PATH = 'integrations/msan/certificates/ca.pem';

    private const MAX_CERTIFICATE_BYTES = 1_048_576;

    public function __construct(
        private readonly MsanSettingsService $settings,
    ) {}

    /**
     * Validates and atomically installs a PKCS#12 client certificate.
     *
     * @return array{fingerprint: string, subject: string, issuer: string, valid_until: string}
     */
    public function replaceFromPath(string $sourcePath, #[\SensitiveParameter] string $pin): array
    {
        $lock = Cache::lock('integrations:msan:certificate-replace', 30);
        if (! $lock->get()) {
            throw new RuntimeException('Druga zamjena M SAN certifikata je već u tijeku.');
        }

        try {
            return $this->replaceFromPathLocked($sourcePath, $pin);
        } finally {
            $lock->release();
        }
    }

    /**
     * @return array{fingerprint: string, subject: string, issuer: string, valid_until: string}
     */
    private function replaceFromPathLocked(string $sourcePath, #[\SensitiveParameter] string $pin): array
    {
        if (! is_file($sourcePath) || ! is_readable($sourcePath)) {
            throw new RuntimeException('Odabrana M SAN certifikat datoteka nije čitljiva.');
        }

        $size = filesize($sourcePath);
        if (! is_int($size) || $size <= 0 || $size > self::MAX_CERTIFICATE_BYTES) {
            throw new RuntimeException('M SAN certifikat mora biti neprazna P12 datoteka manja od 1 MB.');
        }

        $contents = file_get_contents($sourcePath);
        if (! is_string($contents) || $contents === '') {
            throw new RuntimeException('M SAN certifikat nije moguće pročitati.');
        }

        $inspection = $this->inspectContents($contents, $pin);
        $metadata = $inspection['metadata'];
        $caBundle = $inspection['ca_bundle'];
        $disk = $this->disk();
        $directory = dirname(self::STORAGE_PATH);
        $operationId = (string) Str::uuid();
        $temporaryPath = $directory.'/.client-'.$operationId.'.p12';
        $temporaryCaPath = $directory.'/.ca-'.$operationId.'.pem';
        $backupPath = $directory.'/.client-'.$operationId.'.backup';
        $backupCaPath = $directory.'/.ca-'.$operationId.'.backup';
        $installedCertificate = false;
        $installedCa = false;

        $disk->makeDirectory($directory);

        try {
            if (! $disk->put($temporaryPath, $contents)) {
                throw new RuntimeException('M SAN certifikat nije moguće spremiti u privatnu pohranu.');
            }

            if ($caBundle !== '' && ! $disk->put($temporaryCaPath, $caBundle)) {
                throw new RuntimeException('M SAN CA lanac nije moguće spremiti u privatnu pohranu.');
            }

            $temporaryAbsolutePath = $disk->path($temporaryPath);
            $targetAbsolutePath = $disk->path(self::STORAGE_PATH);
            $temporaryCaAbsolutePath = $disk->path($temporaryCaPath);
            $targetCaAbsolutePath = $disk->path(self::CA_STORAGE_PATH);
            $backupAbsolutePath = $disk->path($backupPath);
            $backupCaAbsolutePath = $disk->path($backupCaPath);
            @chmod($temporaryAbsolutePath, 0600);
            if ($caBundle !== '') {
                @chmod($temporaryCaAbsolutePath, 0600);
            }

            if (is_link($targetAbsolutePath) || is_link($targetCaAbsolutePath)) {
                throw new RuntimeException('Putanja M SAN certifikata ili CA lanca ne smije biti simbolička poveznica.');
            }

            if (is_file($targetAbsolutePath) && ! @rename($targetAbsolutePath, $backupAbsolutePath)) {
                throw new RuntimeException('Postojeći M SAN certifikat nije moguće sigurno pripremiti za zamjenu.');
            }
            if (is_file($targetCaAbsolutePath) && ! @rename($targetCaAbsolutePath, $backupCaAbsolutePath)) {
                if (is_file($backupAbsolutePath)) {
                    @rename($backupAbsolutePath, $targetAbsolutePath);
                }
                throw new RuntimeException('Postojeći M SAN CA lanac nije moguće sigurno pripremiti za zamjenu.');
            }
            if (! @rename($temporaryAbsolutePath, $targetAbsolutePath)) {
                throw new RuntimeException('M SAN certifikat nije moguće atomarno zamijeniti.');
            }
            $installedCertificate = true;

            if ($caBundle !== '' && ! @rename($temporaryCaAbsolutePath, $targetCaAbsolutePath)) {
                throw new RuntimeException('M SAN CA lanac nije moguće atomarno zamijeniti.');
            }
            $installedCa = $caBundle !== '';

            @chmod($targetAbsolutePath, 0600);
            if ($installedCa) {
                @chmod($targetCaAbsolutePath, 0600);
            }
            $this->settings->saveP12Pin($pin);
            Cache::forget(self::METADATA_CACHE_KEY);
            $disk->delete([$backupPath, $backupCaPath]);
        } catch (\Throwable $exception) {
            if ($installedCertificate) {
                $disk->delete(self::STORAGE_PATH);
            }
            if ($installedCa) {
                $disk->delete(self::CA_STORAGE_PATH);
            }
            if ($disk->exists($backupPath)) {
                @rename($disk->path($backupPath), $disk->path(self::STORAGE_PATH));
            }
            if ($disk->exists($backupCaPath)) {
                @rename($disk->path($backupCaPath), $disk->path(self::CA_STORAGE_PATH));
            }

            throw $exception;
        } finally {
            $disk->delete([$temporaryPath, $temporaryCaPath, $backupPath, $backupCaPath]);
            $this->erase($contents);
            $this->erase($caBundle);
        }

        return $metadata;
    }

    /**
     * @return array{fingerprint: string, subject: string, issuer: string, valid_until: string}|null
     */
    public function currentMetadata(): ?array
    {
        if (! $this->hasCertificate()) {
            return null;
        }

        /** @var array{ok: bool, metadata?: array{fingerprint: string, subject: string, issuer: string, valid_until: string}, error?: string} $state */
        $state = Cache::remember(self::METADATA_CACHE_KEY, now()->addMinutes(5), function (): array {
            $contents = file_get_contents($this->absolutePath());
            if (! is_string($contents) || $contents === '') {
                return ['ok' => false, 'error' => 'Spremljeni M SAN certifikat nije moguće pročitati.'];
            }

            try {
                return [
                    'ok' => true,
                    'metadata' => $this->inspectContents($contents, $this->settings->p12Pin())['metadata'],
                ];
            } catch (\Throwable $exception) {
                return ['ok' => false, 'error' => $exception->getMessage()];
            } finally {
                $this->erase($contents);
            }
        });

        if (! ($state['ok'] ?? false) || ! isset($state['metadata'])) {
            throw new RuntimeException((string) ($state['error'] ?? 'Spremljeni M SAN certifikat nije valjan.'));
        }

        $validUntil = strtotime((string) ($state['metadata']['valid_until'] ?? ''));
        if (! $validUntil || time() >= $validUntil) {
            Cache::forget(self::METADATA_CACHE_KEY);
            throw new RuntimeException('M SAN certifikat trenutačno nije vremenski valjan.');
        }

        return $state['metadata'];
    }

    public function hasCertificate(): bool
    {
        return $this->disk()->exists(self::STORAGE_PATH);
    }

    public function absolutePath(): string
    {
        if (! $this->hasCertificate()) {
            throw new RuntimeException('M SAN klijentski certifikat nije postavljen.');
        }

        $path = $this->disk()->path(self::STORAGE_PATH);
        if (! is_file($path) || ! is_readable($path) || is_link($path)) {
            throw new RuntimeException('M SAN klijentski certifikat nije sigurno dostupan.');
        }

        return $path;
    }

    public function caAbsolutePath(): ?string
    {
        if (! $this->disk()->exists(self::CA_STORAGE_PATH)) {
            return null;
        }

        $path = $this->disk()->path(self::CA_STORAGE_PATH);
        if (! is_file($path) || ! is_readable($path) || is_link($path)) {
            throw new RuntimeException('M SAN CA lanac nije sigurno dostupan.');
        }

        return $path;
    }

    /**
     * @return array{metadata: array{fingerprint: string, subject: string, issuer: string, valid_until: string}, ca_bundle: string}
     */
    private function inspectContents(#[\SensitiveParameter] string $contents, #[\SensitiveParameter] string $pin): array
    {
        if (trim($pin) === '') {
            throw new RuntimeException('PIN M SAN certifikata ne smije biti prazan.');
        }

        $certificates = [];
        if (! @openssl_pkcs12_read($contents, $certificates, $pin)) {
            throw new RuntimeException('M SAN P12 certifikat ili pripadajući PIN nisu valjani.');
        }

        $certificate = $certificates['cert'] ?? null;
        $privateKey = $certificates['pkey'] ?? null;
        if (! is_string($certificate) || $certificate === '' || ! is_string($privateKey) || $privateKey === '') {
            throw new RuntimeException('M SAN P12 datoteka mora sadržavati klijentski certifikat i privatni ključ.');
        }

        $parsed = @openssl_x509_parse($certificate, short_names: true);
        $fingerprint = @openssl_x509_fingerprint($certificate, 'sha256');
        if (! is_array($parsed) || ! is_string($fingerprint) || $fingerprint === '') {
            throw new RuntimeException('M SAN certifikat nije moguće analizirati.');
        }

        $validFrom = (int) ($parsed['validFrom_time_t'] ?? 0);
        $validUntil = (int) ($parsed['validTo_time_t'] ?? 0);
        $now = time();
        if ($validFrom <= 0 || $validUntil <= 0 || $now < $validFrom || $now >= $validUntil) {
            throw new RuntimeException('M SAN certifikat trenutačno nije vremenski valjan.');
        }

        $subject = $this->distinguishedName(is_array($parsed['subject'] ?? null) ? $parsed['subject'] : []);
        $issuer = $this->distinguishedName(is_array($parsed['issuer'] ?? null) ? $parsed['issuer'] : []);
        if ($subject === '' || $issuer === '') {
            throw new RuntimeException('M SAN certifikat nema čitljiv subject ili issuer.');
        }

        $extraCertificates = $certificates['extracerts'] ?? [];
        if (is_string($extraCertificates)) {
            $extraCertificates = [$extraCertificates];
        }
        $caCertificates = [];
        foreach (is_array($extraCertificates) ? $extraCertificates : [] as $extraCertificate) {
            if (! is_string($extraCertificate) || @openssl_x509_parse($extraCertificate) === false) {
                continue;
            }

            $caCertificates[] = trim($extraCertificate);
        }
        $caBundle = $caCertificates === [] ? '' : implode("\n", $caCertificates)."\n";
        if ($caBundle === '') {
            throw new RuntimeException('M SAN P12 datoteka ne sadrži potreban CA lanac.');
        }

        $this->erase($privateKey);
        unset($certificates['pkey']);

        return [
            'metadata' => [
                'fingerprint' => strtolower($fingerprint),
                'subject' => $subject,
                'issuer' => $issuer,
                'valid_until' => gmdate('Y-m-d\TH:i:s\Z', $validUntil),
            ],
            'ca_bundle' => $caBundle,
        ];
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function distinguishedName(array $values): string
    {
        $parts = [];
        foreach (['CN', 'O', 'OU', 'C'] as $key) {
            $value = $values[$key] ?? null;
            if (is_array($value)) {
                $value = implode('+', array_filter(array_map('strval', $value)));
            }

            $value = trim((string) $value);
            if ($value !== '') {
                $parts[] = $key.'='.$value;
            }
        }

        return implode(', ', $parts);
    }

    private function disk(): FilesystemAdapter
    {
        return Storage::disk('local');
    }

    private function erase(#[\SensitiveParameter] string &$value): void
    {
        if ($value !== '' && function_exists('sodium_memzero')) {
            sodium_memzero($value);
        }

        $value = '';
    }
}
