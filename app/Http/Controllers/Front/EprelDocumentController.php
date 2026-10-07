<?php

namespace App\Http\Controllers\Front;

use App\Http\Controllers\Controller;
use App\Models\Catalog\Product\Product;
use App\Services\Integrations\Msan\EprelDocumentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class EprelDocumentController extends Controller
{
    public function __invoke(Request $request, Product $product, int $declaration, string $document, EprelDocumentService $documents): RedirectResponse
    {
        abort_unless($product->is_active, 404);
        $linkedDeclaration = $product->energyDeclarations()->findOrFail($declaration);

        return redirect()->away($documents->redirectUrl($product, $linkedDeclaration, $document), 302, [
            'Cache-Control' => 'private, no-store, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'no-referrer',
        ]);
    }
}
