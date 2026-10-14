<?php

namespace App\Livewire\Admin\Content\SupplierCatalog;

use App\Models\Content\SupplierCatalog;
use App\Support\SupplierCatalogAccess;
use App\Support\SupplierCatalogUploadLimits;
use App\Support\SupplierCatalogUrl;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithPagination;

class Manager extends Component
{
    use WithPagination;

    #[Locked]
    public ?int $catalogId = null;

    public string $search = '';

    public array $form = [];

    public function mount(): void
    {
        $this->authorizeAccess();
        $this->newCatalog();
        $edit = request()->integer('edit');
        if ($edit > 0) {
            $this->edit($edit);
        }
    }

    public function newCatalog(): void
    {
        $this->authorizeAccess();
        $this->catalogId = null;
        $this->form = [
            'code' => '', 'brand' => '', 'title' => '', 'description' => '', 'year' => '', 'language' => '',
            'source_type' => 'external', 'format' => 'pdf', 'external_url' => '', 'source_url' => '', 'cover_url' => '',
            'is_active' => false, 'sort_order' => 0,
        ];
        $this->resetValidation();
    }

    public function edit(int $id): void
    {
        $this->authorizeAccess();
        $catalog = SupplierCatalog::query()->findOrFail($id);
        $this->catalogId = $catalog->id;
        foreach (array_keys($this->form) as $key) {
            $this->form[$key] = $catalog->{$key} ?? '';
        }
        $this->resetValidation();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function save(): void
    {
        $this->authorizeAccess();
        $urlRule = function (string $attribute, mixed $value, \Closure $fail): void {
            if ($value !== null && $value !== '' && ! SupplierCatalogUrl::isSafe((string) $value)) {
                $fail(__('supplier_catalogs.safe_url'));
            }
        };
        $validated = $this->validate([
            'form.code' => ['required', 'string', 'max:255', 'regex:/^[a-zA-Z0-9_-]+$/', Rule::unique('supplier_catalogs', 'code')->ignore($this->catalogId)],
            'form.brand' => ['required', 'string', 'max:120'],
            'form.title' => ['required', 'string', 'max:255'],
            'form.description' => ['nullable', 'string', 'max:3000'],
            'form.year' => ['nullable', 'integer', 'between:2000,2100'],
            'form.language' => ['nullable', 'string', 'max:80'],
            'form.source_type' => ['required', Rule::in(['external', 'upload'])],
            'form.format' => ['required', Rule::in(['pdf', 'web'])],
            'form.external_url' => ['nullable', 'required_if:form.source_type,external', 'string', 'max:2048', $urlRule],
            'form.source_url' => ['nullable', 'string', 'max:2048', $urlRule],
            'form.cover_url' => ['nullable', 'string', 'max:2048', function (string $attribute, mixed $value, \Closure $fail): void {
                if ($value && ! SupplierCatalogUrl::isSafe((string) $value)
                    && ! preg_match('#^/assets/supplier-catalogs/[a-zA-Z0-9_-]+\.(?:jpg|jpeg|png|webp)$#D', (string) $value)) {
                    $fail(__('supplier_catalogs.safe_url'));
                }
            }],
            'form.is_active' => ['required', 'boolean'],
            'form.sort_order' => ['required', 'integer', 'between:0,100000'],
        ])['form'];

        $catalog = $this->catalogId ? SupplierCatalog::query()->findOrFail($this->catalogId) : new SupplierCatalog;
        if ($validated['source_type'] === 'upload' && $validated['is_active'] && ! $catalog->ownsPath($catalog->document_path, 'documents')) {
            $this->addError('form.is_active', __('supplier_catalogs.upload_before_publish'));

            return;
        }
        foreach (['code', 'brand', 'title', 'description', 'language', 'external_url', 'source_url', 'cover_url'] as $key) {
            $validated[$key] = trim((string) ($validated[$key] ?? ''));
        }
        foreach (['description', 'language', 'external_url', 'source_url', 'cover_url', 'year'] as $key) {
            $validated[$key] = $validated[$key] === '' ? null : $validated[$key];
        }
        if ($validated['source_type'] === 'upload') {
            $validated['format'] = 'pdf';
            $validated['external_url'] = null;
        }

        $catalog->fill($validated + ['updated_by' => auth()->id()]);
        if (! $catalog->exists) {
            $catalog->created_by = auth()->id();
        }
        $catalog->save();
        $this->catalogId = $catalog->id;
        $this->dispatch('notify', type: 'success', message: __('supplier_catalogs.saved'));
    }

    public function delete(int $id): void
    {
        $this->authorizeAccess();
        $catalog = SupplierCatalog::query()->findOrFail($id);
        $paths = [];
        foreach (['document_path' => 'documents', 'cover_path' => 'covers'] as $field => $collection) {
            if ($catalog->ownsPath($catalog->{$field}, $collection)) {
                $paths[] = $catalog->{$field};
            }
        }
        $catalog->delete();
        Storage::disk('local')->delete($paths);
        if ($this->catalogId === $id) {
            $this->newCatalog();
        }
        $this->resetPage();
        $this->dispatch('notify', type: 'success', message: __('supplier_catalogs.deleted'));
    }

    private function authorizeAccess(): void
    {
        SupplierCatalogAccess::authorize(auth()->user());
    }

    public function render()
    {
        $this->authorizeAccess();

        return view('livewire.admin.content.supplier-catalog.manager', [
            'rows' => SupplierCatalog::query()->when($this->search !== '', fn ($q) => $q->where(fn ($q) => $q
                ->where('brand', 'like', '%'.$this->search.'%')->orWhere('title', 'like', '%'.$this->search.'%')))
                ->orderBy('sort_order')->orderByDesc('year')->orderBy('title')->paginate(20),
            'editingCatalog' => $this->catalogId ? SupplierCatalog::query()->find($this->catalogId) : null,
            'uploadLimitMb' => round(SupplierCatalogUploadLimits::pdfKilobytes() / 1024, 1),
            'uploadLimitBytes' => SupplierCatalogUploadLimits::pdfKilobytes() * 1024,
        ]);
    }
}
