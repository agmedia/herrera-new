<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Integrations\Msan\MsanProduct;
use App\Services\Integrations\Msan\Exceptions\MsanProductImageNotFoundException;
use App\Services\Integrations\Msan\Exceptions\MsanProductImagePreviewUnavailableException;
use App\Services\Integrations\Msan\MsanProductImagePreviewService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class MsanProductImageController extends Controller
{
    public function __invoke(
        Request $request,
        MsanProduct $product,
        MsanProductImagePreviewService $images,
    ): Response {
        abort_if(trim((string) $product->image_url) === '', 404);

        try {
            $path = $images->cachedPath($product);
            $mime = $images->mimeType($path);
        } catch (MsanProductImageNotFoundException) {
            abort(404);
        } catch (MsanProductImagePreviewUnavailableException $exception) {
            report($exception);

            return response('', 503, [
                'Cache-Control' => 'private, no-store',
                'Retry-After' => '10',
                'X-Content-Type-Options' => 'nosniff',
            ]);
        }

        $response = response()->file($path, [
            'Cache-Control' => 'private, no-cache, must-revalidate',
            'Content-Disposition' => 'inline',
            'Content-Type' => $mime,
            'X-Content-Type-Options' => 'nosniff',
        ]);
        $response->setPrivate();
        $response->setEtag((string) hash_file('sha256', $path));
        $response->setLastModified((new \DateTimeImmutable)->setTimestamp((int) filemtime($path)));

        if ($response->isNotModified($request)) {
            return $response;
        }

        return $response;
    }
}
