<?php

namespace App\Services\Integrations\Msan;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

class MsanFtpClient
{
    private const IMAGE_EXTENSIONS = ['gif', 'jpeg', 'jpg', 'png', 'webp'];

    private MsanFtpTransportInterface $transport;

    private MsanCertificateService $certificates;

    public function __construct(
        private readonly MsanSettingsService $settings,
        ?MsanFtpTransportInterface $transport = null,
        ?MsanCertificateService $certificates = null,
    ) {
        $this->transport = $transport ?? new CurlMsanFtpTransport;
        $this->certificates = $certificates ?? app(MsanCertificateService::class);
    }

    /**
     * Verifies the encrypted FTPS credentials without returning a directory listing.
     *
     * @return array{ok: true, host: string, checked_at: string}
     */
    public function testConnection(): array
    {
        $this->settings->assertFtpEnabled();
        $credentials = $this->settings->ftpCredentials();

        try {
            $this->transport->testConnection(
                username: $credentials['username'],
                password: $credentials['password'],
                connectTimeout: $this->settings->ftpConnectTimeout(),
                timeout: $this->settings->ftpTimeout(),
                caPath: $this->certificates->caAbsolutePath(),
                maxBytes: 16 * 1024 * 1024,
            );
        } catch (Throwable) {
            throw new RuntimeException('M SAN FTPS provjera veze nije uspjela.');
        } finally {
            $credentials['password'] = '';
        }

        return [
            'ok' => true,
            'host' => MsanClient::HOST,
            'checked_at' => gmdate('Y-m-d\TH:i:s\Z'),
        ];
    }

    /**
     * Downloads an image into a local file. No remote/hotlink URL is returned.
     */
    public function downloadImage(string $remotePath, string $destinationPath): void
    {
        $this->settings->assertFtpEnabled();
        $remotePath = $this->validatedRemotePath($remotePath);
        $destinationPath = $this->absoluteDestinationPath($destinationPath);
        $directory = dirname($destinationPath);
        if ((! is_dir($directory) && ! @mkdir($directory, 0750, true)) || ! is_writable($directory)) {
            throw new RuntimeException('Odredišni direktorij za M SAN sliku nije zapisiv.');
        }
        if (is_link($destinationPath)) {
            throw new RuntimeException('Odredišna M SAN slika ne smije biti simbolička poveznica.');
        }

        $temporaryPath = $destinationPath.'.part-'.Str::uuid();
        $credentials = $this->settings->ftpCredentials();

        try {
            $this->transport->downloadToFile(
                remotePath: $remotePath,
                destinationPath: $temporaryPath,
                username: $credentials['username'],
                password: $credentials['password'],
                connectTimeout: $this->settings->ftpConnectTimeout(),
                timeout: $this->settings->ftpTimeout(),
                caPath: $this->certificates->caAbsolutePath(),
                maxBytes: 16 * 1024 * 1024,
            );
        } catch (Throwable) {
            @unlink($temporaryPath);
            throw new RuntimeException('M SAN FTPS dohvat slike nije uspio.');
        } finally {
            $credentials['password'] = '';
        }

        if (! is_file($temporaryPath) || filesize($temporaryPath) === 0) {
            @unlink($temporaryPath);
            throw new RuntimeException('M SAN FTPS odgovor za sliku je prazan.');
        }

        if (is_link($destinationPath) || ! @rename($temporaryPath, $destinationPath)) {
            @unlink($temporaryPath);
            throw new RuntimeException('M SAN sliku nije moguće atomarno spremiti.');
        }

        @chmod($destinationPath, 0600);
    }

    private function validatedRemotePath(string $path): string
    {
        $path = trim($path);
        $segments = explode('/', str_replace('\\', '/', ltrim($path, '/')));
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        if ($path === ''
            || str_contains($path, "\0")
            || str_contains($path, '://')
            || str_contains($path, '?')
            || str_contains($path, '#')
            || in_array('..', $segments, true)
            || ! in_array($extension, self::IMAGE_EXTENSIONS, true)
        ) {
            throw new InvalidArgumentException('M SAN FTP putanja slike nije valjana.');
        }

        return '/'.implode('/', array_filter($segments, static fn (string $segment): bool => $segment !== '' && $segment !== '.'));
    }

    private function absoluteDestinationPath(string $path): string
    {
        $path = trim($path);
        if ($path === '' || str_contains($path, "\0")) {
            throw new InvalidArgumentException('Odredišna putanja M SAN slike nije valjana.');
        }

        if (str_starts_with($path, DIRECTORY_SEPARATOR)
            || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1
        ) {
            return $path;
        }

        return Storage::disk('local')->path(ltrim($path, '/\\'));
    }
}
