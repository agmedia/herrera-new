<?php

namespace App\Jobs\Integrations\Msan;

use App\Models\Integrations\Msan\MsanProduct;
use App\Services\Integrations\Msan\MsanClient;
use App\Services\Integrations\Msan\MsanFtpClient;
use App\Services\Integrations\Msan\MsanSettingsService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class ImportMsanProductImageJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 4;

    public int $timeout = 360;

    public function __construct(private readonly int $msanProductId) {}

    public function middleware(): array
    {
        return [(new WithoutOverlapping('msan-image-'.$this->msanProductId))->releaseAfter(30)->expireAfter(480)];
    }

    public function handle(
        MsanClient $client,
        MsanFtpClient $ftp,
        MsanSettingsService $settings,
    ): void {
        /** @var MsanProduct|null $source */
        $source = MsanProduct::query()->with('localProduct.media')->find($this->msanProductId);
        $product = $source?->localProduct;
        $imageUrl = trim((string) ($source?->image_url ?? ''));
        if (! $source || ! $product || $imageUrl === '') {
            return;
        }

        $current = $product->getFirstMedia('product_main');
        if ($current && (string) $current->getCustomProperty('msan_source_url') === $imageUrl) {
            return;
        }

        $safeSourceKey = substr(hash('sha256', (string) $source->external_code), 0, 32);
        $relativePath = 'integrations/msan/images/'.$safeSourceKey.'-'.bin2hex(random_bytes(6)).'.download';
        $absolutePath = Storage::disk('local')->path($relativePath);

        try {
            Storage::disk('local')->makeDirectory(dirname($relativePath));
            $transfer = $this->downloadImage($imageUrl, $absolutePath, $client, $ftp, $settings);

            if (! is_file($absolutePath) || filesize($absolutePath) <= 0 || filesize($absolutePath) > 12 * 1024 * 1024) {
                throw new RuntimeException('M SAN slika je prazna ili veća od 12 MB.');
            }

            $mime = mime_content_type($absolutePath) ?: '';
            if (! in_array($mime, ['image/jpeg', 'image/png', 'image/webp', 'image/avif'], true)) {
                throw new RuntimeException('M SAN datoteka nije podržana slika.');
            }

            $extension = match ($mime) {
                'image/png' => 'png',
                'image/webp' => 'webp',
                'image/avif' => 'avif',
                default => 'jpg',
            };

            $product->addMedia($absolutePath)
                ->usingFileName('msan-'.$safeSourceKey.'.'.$extension)
                ->withCustomProperties([
                    'supplier_source' => 'msan',
                    'msan_source_url' => $imageUrl,
                    'msan_transfer' => $transfer,
                ])
                ->toMediaCollection('product_main');
        } catch (Throwable $exception) {
            $source->forceFill(['last_error' => mb_substr($exception->getMessage(), 0, 1500)])->save();
            throw $exception;
        } finally {
            if (is_file($absolutePath)) {
                @unlink($absolutePath);
            }
        }
    }

    public function failed(?Throwable $exception): void
    {
        $message = preg_replace(
            '/(password|passphrase|pin)\s*[=:]\s*\S+/iu',
            '$1=[skriveno]',
            $exception?->getMessage() ?: 'M SAN sliku nije moguće preuzeti.',
        );

        MsanProduct::query()->whereKey($this->msanProductId)->update([
            'last_error' => mb_substr(trim((string) $message), 0, 1500),
        ]);
    }

    private function downloadImage(
        string $imageUrl,
        string $destinationPath,
        MsanClient $client,
        MsanFtpClient $ftp,
        MsanSettingsService $settings,
    ): string {
        if ($settings->ftpEnabled()) {
            try {
                $ftp->downloadImage($this->ftpPathFromImageUrl($imageUrl), $destinationPath);

                return 'ftps';
            } catch (Throwable) {
                // The legacy FTP path is optional. Fall back to the authenticated
                // HTTPS image endpoint without exposing or hotlinking its URL.
            }
        }

        $client->downloadProductImage($this->httpsImageUrl($imageUrl), $destinationPath);

        return 'https';
    }

    private function ftpPathFromImageUrl(string $imageUrl): string
    {
        $parts = $this->validatedImageUrlParts($imageUrl);
        $path = rawurldecode((string) ($parts['path'] ?? ''));

        if ($path === '') {
            throw new RuntimeException('M SAN URL slike nema FTP putanju.');
        }

        return $path;
    }

    private function httpsImageUrl(string $imageUrl): string
    {
        $parts = $this->validatedImageUrlParts($imageUrl);
        $path = (string) ($parts['path'] ?? '');
        if ($path === '') {
            throw new RuntimeException('M SAN URL slike nema putanju.');
        }

        return 'https://'.MsanClient::HOST.$path
            .(isset($parts['query']) ? '?'.$parts['query'] : '');
    }

    /** @return array<string, mixed> */
    private function validatedImageUrlParts(string $imageUrl): array
    {
        $parts = parse_url($imageUrl);
        if (! is_array($parts)) {
            throw new RuntimeException('M SAN URL slike nije valjan.');
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if (! in_array($scheme, ['http', 'https'], true)
            || strtolower((string) ($parts['host'] ?? '')) !== MsanClient::HOST
            || isset($parts['user'])
            || isset($parts['pass'])
            || (isset($parts['port']) && ! in_array((int) $parts['port'], [80, 443], true))
        ) {
            throw new RuntimeException('M SAN URL slike nije valjan.');
        }

        return $parts;
    }
}
