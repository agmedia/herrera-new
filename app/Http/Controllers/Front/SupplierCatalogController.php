<?php

namespace App\Http\Controllers\Front;

use App\Http\Controllers\Controller;
use App\Models\Content\SupplierCatalog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SupplierCatalogController extends Controller
{
    public function index(Request $request): View
    {
        $brand = is_string($request->query('brand')) ? trim($request->query('brand')) : '';
        $all = SupplierCatalog::query()->published()->orderBy('sort_order')->orderByDesc('year')->orderBy('title')->get()
            ->filter(fn (SupplierCatalog $catalog) => $catalog->publicUrl() !== null);

        return view('front.desktop.supplier-catalogs.index', [
            'catalogs' => $all->when($brand !== '', fn ($rows) => $rows->where('brand', $brand))->values(),
            'brands' => $all->pluck('brand')->unique()->sort()->values(),
            'selectedBrand' => $brand,
        ]);
    }

    public function document(SupplierCatalog $catalog): StreamedResponse
    {
        abort_unless($catalog->is_active && $catalog->source_type === 'upload'
            && $catalog->ownsPath($catalog->document_path, 'documents'), 404);
        abort_unless(Storage::disk('local')->exists($catalog->document_path), 404);

        return Storage::disk('local')->response($catalog->document_path, (Str::slug($catalog->title) ?: 'catalog').'.pdf', [
            'Content-Type' => 'application/pdf',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ], 'inline');
    }

    public function cover(SupplierCatalog $catalog): StreamedResponse
    {
        abort_unless($catalog->publicUrl() !== null && $catalog->ownsPath($catalog->cover_path, 'covers'), 404);
        abort_unless(Storage::disk('local')->exists($catalog->cover_path), 404);

        return Storage::disk('local')->response($catalog->cover_path, null, [
            'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, max-age=3600',
        ]);
    }
}
