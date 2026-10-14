<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Content\SupplierCatalog;
use App\Support\SupplierCatalogAccess;
use App\Support\SupplierCatalogUploadLimits;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SupplierCatalogUploadController extends Controller
{
    public function __invoke(Request $request, SupplierCatalog $catalog): RedirectResponse
    {
        SupplierCatalogAccess::authorize($request->user());
        $request->validate([
            'document' => ['nullable', 'required_without:cover', 'file', 'mimes:pdf', 'mimetypes:application/pdf', 'max:'.SupplierCatalogUploadLimits::pdfKilobytes()],
            'cover' => ['nullable', 'required_without:document', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ]);

        $paths = [];
        $previousPaths = [];
        $data = ['updated_by' => $request->user()->id];
        try {
            if ($file = $request->file('document')) {
                $handle = fopen($file->getRealPath(), 'rb');
                $header = $handle ? fread($handle, 1024) : false;
                if ($handle) {
                    fclose($handle);
                }
                if (! is_string($header) || ! str_starts_with($header, '%PDF-')) {
                    throw ValidationException::withMessages(['document' => __('supplier_catalogs.invalid_pdf')]);
                }
                $path = $file->storeAs('supplier-catalogs/'.$catalog->id.'/documents', Str::uuid().'.pdf', 'local');
                if (! $path) {
                    throw new \RuntimeException('Unable to store supplier catalog.');
                }
                $paths[] = $path;
                if ($catalog->ownsPath($catalog->document_path, 'documents')) {
                    $previousPaths[] = $catalog->document_path;
                }
                $data += [
                    'document_path' => $path,
                    'document_name' => Str::limit(basename($file->getClientOriginalName()), 250, ''),
                    'document_size' => $file->getSize(),
                    'source_type' => 'upload', 'format' => 'pdf', 'external_url' => null,
                ];
            }
            if ($file = $request->file('cover')) {
                $path = $file->storeAs('supplier-catalogs/'.$catalog->id.'/covers', Str::uuid().'.'.$file->extension(), 'local');
                if (! $path) {
                    throw new \RuntimeException('Unable to store supplier catalog cover.');
                }
                $paths[] = $path;
                if ($catalog->ownsPath($catalog->cover_path, 'covers')) {
                    $previousPaths[] = $catalog->cover_path;
                }
                $data['cover_path'] = $path;
            }
            $catalog->update($data);
        } catch (\Throwable $exception) {
            Storage::disk('local')->delete($paths);
            throw $exception;
        }
        Storage::disk('local')->delete($previousPaths);

        return redirect()->route('admin.content.supplier-catalogs.index', ['edit' => $catalog->id])
            ->with('notify', ['type' => 'success', 'message' => __('supplier_catalogs.uploaded')]);
    }
}
