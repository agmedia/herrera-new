<?php

namespace App\Livewire\Admin\Content\ReferenceProjects;

use App\Models\Content\Reference\ReferenceProject;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class Manager extends Component
{
    use WithFileUploads;
    use WithPagination;

    #[Locked]
    public ?int $projectId = null;

    public string $search = '';

    public string $status = '';

    public bool $showForm = false;

    public array $photos = [];

    public array $captions = [];

    public array $form = [];

    public function mount(): void
    {
        $this->authorizeAccess('view');
        $this->resetForm();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    public function create(): void
    {
        $this->authorizeAccess('create');
        $this->resetForm();
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        $this->authorizeAccess('update');
        $this->resetForm();
        $project = ReferenceProject::query()->with(['translations', 'media'])->findOrFail($id);
        $this->projectId = (int) $project->id;
        $this->form = array_replace($this->form, [
            'location' => $project->location ?? '',
            'completed_year' => $project->completed_year ?? '',
            'project_type' => $project->project_type ?? '',
            'is_active' => (bool) $project->is_active,
            'published_at' => $project->published_at?->format('Y-m-d\TH:i') ?? '',
            'sort_order' => (int) $project->sort_order,
        ]);
        foreach (['hr', 'en'] as $locale) {
            $translation = $project->translations->firstWhere('locale', $locale);
            foreach (['title', 'slug', 'excerpt', 'description'] as $field) {
                $this->form[$locale][$field] = $translation?->{$field} ?? '';
            }
        }
        $this->loadCaptions($project);
        $this->showForm = true;
    }

    public function cancel(): void
    {
        $this->authorizeAccess('view');
        $this->resetForm();
    }

    public function generateSlug(string $locale): void
    {
        $this->authorizeAccess($this->projectId ? 'update' : 'create');
        abort_unless(in_array($locale, ['hr', 'en'], true), 422);
        $this->form[$locale]['slug'] = Str::slug((string) ($this->form[$locale]['title'] ?? ''));
    }

    public function save(): void
    {
        $this->authorizeAccess($this->projectId ? 'update' : 'create');
        foreach (['hr', 'en'] as $locale) {
            foreach (['title', 'slug', 'excerpt', 'description'] as $field) {
                $this->form[$locale][$field] = trim((string) ($this->form[$locale][$field] ?? ''));
            }
            if ($this->form[$locale]['title'] !== '' && $this->form[$locale]['slug'] === '') {
                $this->form[$locale]['slug'] = Str::slug($this->form[$locale]['title']);
            }
        }
        $validated = $this->validate($this->rules());
        $project = $this->projectId ? ReferenceProject::query()->findOrFail($this->projectId) : new ReferenceProject;
        $existingCount = $project->exists ? $project->getMedia(ReferenceProject::GALLERY)->count() : 0;
        if ($existingCount + count($this->photos) > 30) {
            $this->addError('photos', __('reference_projects.photo_limit'));

            return;
        }
        if ($validated['form']['is_active'] && $existingCount + count($this->photos) === 0) {
            $this->addError('form.is_active', __('reference_projects.photo_required'));

            return;
        }

        $addedMedia = [];
        try {
            DB::transaction(function () use ($project, $validated, &$addedMedia): void {
                $data = $validated['form'];
                $project->fill([
                    'location' => trim((string) $data['location']) ?: null,
                    'completed_year' => $data['completed_year'] ?: null,
                    'project_type' => trim((string) $data['project_type']) ?: null,
                    'is_active' => (bool) $data['is_active'],
                    'published_at' => $data['published_at'] ?: null,
                    'sort_order' => (int) ($data['sort_order'] ?? 0),
                    'updated_by' => auth()->id(),
                ]);
                if (! $project->exists) {
                    $project->created_by = auth()->id();
                }
                $project->save();
                foreach (['hr', 'en'] as $locale) {
                    if ($data[$locale]['title'] === '') {
                        $project->translations()->where('locale', $locale)->delete();

                        continue;
                    }
                    $project->translations()->updateOrCreate(['locale' => $locale], $data[$locale]);
                }
                foreach ($this->photos as $photo) {
                    $addedMedia[] = $project->addMedia($photo->getRealPath())->preservingOriginal()
                        ->usingName(pathinfo($photo->getClientOriginalName(), PATHINFO_FILENAME))
                        ->usingFileName(Str::uuid().'.'.$photo->guessExtension())
                        ->toMediaCollection(ReferenceProject::GALLERY);
                }
                activity('reference_projects')->performedOn($project)->causedBy(auth()->user())
                    ->event($this->projectId ? 'updated' : 'created')
                    ->withProperties(['is_active' => $project->is_active, 'photos_added' => count($addedMedia)])
                    ->log('Reference project saved');
            });
        } catch (\Throwable $exception) {
            foreach ($addedMedia as $media) {
                $media->delete();
            }
            throw $exception;
        }

        if ($this->canAccess('update')) {
            $this->edit((int) $project->id);
        } else {
            $this->resetForm();
        }
        $this->dispatch('notify', type: 'success', message: __('reference_projects.saved'));
        $this->resetPage();
    }

    public function delete(int $id): void
    {
        $this->authorizeAccess('delete');
        $project = ReferenceProject::query()->findOrFail($id);
        activity('reference_projects')->performedOn($project)->causedBy(auth()->user())->event('deleted')->log('Reference project deleted');
        $project->delete();
        if ($this->projectId === $id) {
            $this->resetForm();
        }
        $this->resetPage();
        $this->dispatch('notify', type: 'success', message: __('reference_projects.deleted'));
    }

    public function deletePhoto(int $mediaId): void
    {
        $this->authorizeAccess('delete');
        $project = ReferenceProject::query()->findOrFail($this->projectId);
        $media = $project->media()->where('collection_name', ReferenceProject::GALLERY)->find($mediaId);
        abort_unless($media, 404);
        $media->delete();
        if (! $project->media()->where('collection_name', ReferenceProject::GALLERY)->exists()) {
            $project->update(['is_active' => false, 'updated_by' => auth()->id()]);
            $this->form['is_active'] = false;
        }
        $this->loadCaptions($project->refresh());
    }

    public function saveCaption(int $mediaId): void
    {
        $this->authorizeAccess('update');
        $this->validate(['captions.'.$mediaId => ['nullable', 'string', 'max:500']]);
        $project = ReferenceProject::query()->findOrFail($this->projectId);
        $media = $project->media()->where('collection_name', ReferenceProject::GALLERY)->find($mediaId);
        abort_unless($media, 404);
        $media->setCustomProperty('caption', trim((string) ($this->captions[$mediaId] ?? '')))->save();
        $this->dispatch('notify', type: 'success', message: __('reference_projects.caption_saved'));
    }

    public function movePhoto(int $mediaId, string $direction): void
    {
        $this->authorizeAccess('update');
        abort_unless(in_array($direction, ['up', 'down'], true), 422);
        $project = ReferenceProject::query()->findOrFail($this->projectId);
        $ids = $project->getMedia(ReferenceProject::GALLERY)->sortBy('order_column')->pluck('id')->values()->all();
        $position = array_search($mediaId, $ids, true);
        abort_if($position === false, 404);
        $next = $position + ($direction === 'up' ? -1 : 1);
        if (isset($ids[$next])) {
            [$ids[$position], $ids[$next]] = [$ids[$next], $ids[$position]];
            Media::setNewOrder($ids);
        }
    }

    public function render()
    {
        $this->authorizeAccess('view');
        $rows = ReferenceProject::query()->with(['translations', 'media'])
            ->when($this->status === 'published', fn ($query) => $query->where('is_active', true))
            ->when($this->status === 'draft', fn ($query) => $query->where('is_active', false))
            ->when(trim($this->search) !== '', function ($query): void {
                $query->where(fn ($match) => $match->where('location', 'like', '%'.trim($this->search).'%')
                    ->orWhereHas('translations', fn ($translation) => $translation->where('title', 'like', '%'.trim($this->search).'%')));
            })
            ->orderBy('sort_order')->orderByDesc('id')->paginate(20);
        $project = $this->projectId ? ReferenceProject::query()->with('media')->find($this->projectId) : null;

        return view('livewire.admin.content.reference-projects.manager', [
            'rows' => $rows,
            'project' => $project,
            'gallery' => $project?->getMedia(ReferenceProject::GALLERY)->sortBy('order_column')->values() ?? collect(),
            'canCreate' => $this->canAccess('create'),
            'canUpdate' => $this->canAccess('update'),
            'canDelete' => $this->canAccess('delete'),
        ]);
    }

    private function rules(): array
    {
        $rules = [
            'form.location' => ['nullable', 'string', 'max:255'],
            'form.completed_year' => ['nullable', 'integer', 'min:1900', 'max:'.(now()->year + 1)],
            'form.project_type' => ['nullable', 'string', 'max:120'],
            'form.is_active' => ['required', 'boolean'],
            'form.published_at' => ['nullable', 'date'],
            'form.sort_order' => ['nullable', 'integer', 'min:0', 'max:999999'],
            'photos' => ['array', 'max:30'],
            'photos.*' => ['image', 'mimes:jpg,jpeg,png,webp', 'max:8192', 'dimensions:max_width=12000,max_height=12000'],
        ];
        foreach (['hr', 'en'] as $locale) {
            $rules['form.'.$locale.'.title'] = [$locale === 'hr' ? 'required' : 'nullable', 'string', 'max:255'];
            $rules['form.'.$locale.'.slug'] = [Rule::requiredIf(fn () => $this->form[$locale]['title'] !== ''), 'nullable', 'string', 'max:191', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::unique('content_reference_project_translations', 'slug')->where('locale', $locale)->ignore($this->projectId, 'project_id')];
            $rules['form.'.$locale.'.excerpt'] = ['nullable', 'string', 'max:1500'];
            $rules['form.'.$locale.'.description'] = ['nullable', 'string', 'max:30000'];
        }

        return $rules;
    }

    private function authorizeAccess(string $action): void
    {
        abort_unless($this->canAccess($action), 403);
    }

    private function canAccess(string $action): bool
    {
        $user = auth()->user();

        return $user && ($user->account_type !== 'staff' || $user->admin_login_enabled)
            && ($user->isA('superadmin') || ($user->can('admin.access') && $user->can('content.references.'.$action)));
    }

    private function loadCaptions(ReferenceProject $project): void
    {
        $this->captions = $project->getMedia(ReferenceProject::GALLERY)->mapWithKeys(fn (Media $media) => [$media->id => (string) $media->getCustomProperty('caption', '')])->all();
    }

    private function resetForm(): void
    {
        $this->resetValidation();
        $this->projectId = null;
        $this->showForm = false;
        $this->photos = [];
        $this->captions = [];
        $this->form = [
            'location' => '', 'completed_year' => '', 'project_type' => '', 'is_active' => false,
            'published_at' => '', 'sort_order' => 0,
            'hr' => ['title' => '', 'slug' => '', 'excerpt' => '', 'description' => ''],
            'en' => ['title' => '', 'slug' => '', 'excerpt' => '', 'description' => ''],
        ];
    }
}
