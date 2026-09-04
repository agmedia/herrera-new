<?php

namespace App\Livewire\Admin\Content\Comment;

use App\Models\Catalog\Product\Product;
use App\Models\Content\Blog\BlogPost;
use App\Models\Content\Page\InfoPage;
use App\Models\Content\Support\Comment;
use App\Models\Content\Support\Faq;
use App\Services\Settings\SystemSettingsService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Validation\Rule;
use Livewire\Component;
use Livewire\WithPagination;

class Manager extends Component
{
    use WithPagination;

    public string $search = '';

    public string $locale = 'all';

    public string $status = Comment::STATUS_PENDING;

    public string $target = 'all';

    public ?int $editingId = null;

    public ?int $recordId = null;

    public bool $editPage = false;

    /**
     * @var array<string, mixed>
     */
    public array $editForm = [
        'author_name' => '',
        'author_email' => '',
        'locale' => '',
        'rating' => '',
        'status' => Comment::STATUS_PENDING,
        'is_featured' => false,
        'body' => '',
    ];

    public function mount(?int $recordId = null, bool $editPage = false): void
    {
        $this->editPage = $editPage;
        $this->recordId = $recordId;

        $search = trim((string) request()->query('search', ''));
        $status = (string) request()->query('status', Comment::STATUS_PENDING);
        $target = (string) request()->query('target', 'all');
        $locale = (string) request()->query('locale', 'all');

        $this->search = $search;
        $this->status = array_key_exists($status, $this->statusOptions()) ? $status : Comment::STATUS_PENDING;
        $this->target = array_key_exists($target, $this->targetOptions()) ? $target : 'all';
        $this->locale = $locale !== '' ? $locale : 'all';

        if ($this->editPage) {
            abort_unless($recordId !== null && $recordId > 0, 404);
            $this->edit($recordId);
            abort_unless($this->editingId !== null, 404);
        }
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedLocale(): void
    {
        $this->resetPage();
    }

    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    public function updatedTarget(): void
    {
        $this->resetPage();
    }

    public function edit(int $commentId): void
    {
        $comment = Comment::query()->find($commentId);
        if (! $comment || $comment->trashed()) {
            if ($this->editPage) {
                abort(404);
            }

            $this->dispatch('notify', type: 'warning', message: __('Comment not found.'));

            return;
        }

        $this->editingId = $comment->id;
        $this->recordId = $comment->id;
        $this->editForm = [
            'author_name' => (string) ($comment->author_name ?? ''),
            'author_email' => (string) ($comment->author_email ?? ''),
            'locale' => (string) ($comment->locale ?? ''),
            'rating' => $comment->rating ? (string) $comment->rating : '',
            'status' => (string) $comment->status,
            'is_featured' => (bool) $comment->is_featured,
            'body' => (string) $comment->body,
        ];

        $this->resetValidation();
    }

    public function saveEdit()
    {
        if (! $this->editingId) {
            return;
        }

        $validated = $this->validate($this->editRules());
        $payload = $validated['editForm'];

        $comment = Comment::query()->find($this->editingId);
        if (! $comment || $comment->trashed()) {
            $message = __('Comment not found.');

            if ($this->editPage) {
                return redirect()
                    ->route('admin.content.comments.index', $this->listRouteParameters())
                    ->with('notify', [
                        'type' => 'warning',
                        'message' => $message,
                    ]);
            }

            $this->dispatch('notify', type: 'warning', message: $message);
            $this->resetEditForm();

            return;
        }

        $status = (string) ($payload['status'] ?? Comment::STATUS_PENDING);

        $comment->update([
            'author_name' => $this->nullableTrim($payload['author_name'] ?? null),
            'author_email' => $this->nullableTrim($payload['author_email'] ?? null),
            'locale' => $this->nullableTrim($payload['locale'] ?? null),
            'rating' => $this->nullableInt($payload['rating'] ?? null),
            'status' => $status,
            'is_featured' => (bool) ($payload['is_featured'] ?? false),
            'body' => trim((string) ($payload['body'] ?? '')),
            'reviewed_by' => $status === Comment::STATUS_PENDING ? null : auth()->id(),
            'reviewed_at' => $status === Comment::STATUS_PENDING ? null : now(),
        ]);

        activity('content_comments')
            ->performedOn($comment)
            ->causedBy(auth()->user())
            ->event('updated')
            ->withProperties([
                'status' => $comment->status,
                'rating' => $comment->rating,
                'is_featured' => $comment->is_featured,
            ])
            ->log(__('Comment updated'));

        $message = __('Comment updated.');

        if ($this->editPage) {
            $this->resetEditForm();

            return redirect()
                ->route('admin.content.comments.index', $this->listRouteParameters())
                ->with('notify', [
                    'type' => 'success',
                    'message' => $message,
                ]);
        }

        $this->dispatch('notify', type: 'success', message: $message);
        $this->resetEditForm();
    }

    public function cancelEdit(): void
    {
        $this->resetEditForm();
    }

    public function approve(int $commentId): void
    {
        $this->setStatus($commentId, Comment::STATUS_APPROVED);
    }

    public function reject(int $commentId): void
    {
        $this->setStatus($commentId, Comment::STATUS_REJECTED);
    }

    public function spam(int $commentId): void
    {
        $this->setStatus($commentId, Comment::STATUS_SPAM);
    }

    public function delete(int $commentId): void
    {
        $comment = Comment::query()->find($commentId);
        if (! $comment) {
            $this->dispatch('notify', type: 'warning', message: __('Comment not found.'));

            return;
        }

        $comment->delete();

        activity('content_comments')
            ->performedOn($comment)
            ->causedBy(auth()->user())
            ->event('deleted')
            ->log(__('Comment moved to trash'));

        if ($this->editingId === $commentId) {
            $this->resetEditForm();
        }

        $this->dispatch('notify', type: 'info', message: __('Comment moved to trash.'));
    }

    public function restore(int $commentId): void
    {
        $comment = Comment::query()->withTrashed()->find($commentId);
        if (! $comment || ! $comment->trashed()) {
            $this->dispatch('notify', type: 'warning', message: __('Comment not in trash.'));

            return;
        }

        $comment->restore();

        activity('content_comments')
            ->performedOn($comment)
            ->causedBy(auth()->user())
            ->event('restored')
            ->log(__('Comment restored'));

        $this->dispatch('notify', type: 'success', message: __('Comment restored.'));
    }

    public function render()
    {
        $perPage = app(SystemSettingsService::class)->getInt(
            'admin_items_per_page',
            (int) config('admin_ui.pagination.admin_items_per_page', 20),
            5,
            200
        );

        $query = Comment::query()
            ->with([
                'user:id,name,email',
                'reviewer:id,name',
                'commentable' => function (MorphTo $morphTo): void {
                    $locale = $this->locale !== 'all' ? $this->locale : (string) config('app.locale', 'en');
                    $morphTo->morphWith([
                        Product::class => ['translations' => fn ($q) => $q->where('locale', $locale)],
                        BlogPost::class => ['translations' => fn ($q) => $q->where('locale', $locale)],
                        InfoPage::class => ['translations' => fn ($q) => $q->where('locale', $locale)],
                        Faq::class => ['translations' => fn ($q) => $q->where('locale', $locale)],
                    ]);
                },
            ]);

        if ($this->status === 'deleted') {
            $query->onlyTrashed();
        } else {
            if ($this->status === 'all') {
                $query->withTrashed();
            } else {
                $query->where('status', $this->status);
            }
        }

        if ($this->target !== 'all') {
            $targetClass = $this->targetClass($this->target);
            if ($targetClass) {
                $query->where('commentable_type', $targetClass);
            }
        }

        if ($this->locale !== 'all') {
            $query->where('locale', $this->locale);
        }

        if ($this->search !== '') {
            $query->where(function (Builder $q): void {
                $q->where('body', 'like', '%'.$this->search.'%')
                    ->orWhere('author_name', 'like', '%'.$this->search.'%')
                    ->orWhere('author_email', 'like', '%'.$this->search.'%');
            });
        }

        $rows = $query
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($perPage);

        return view('livewire.admin.content.comment.manager', [
            'rows' => $rows,
            'perPage' => $perPage,
            'statusOptions' => $this->statusOptions(),
            'editableStatusOptions' => $this->editableStatusOptions(),
            'targetOptions' => $this->targetOptions(),
        ]);
    }

    private function setStatus(int $commentId, string $status): void
    {
        if (! in_array($status, Comment::statuses(), true)) {
            return;
        }

        $comment = Comment::query()->find($commentId);
        if (! $comment) {
            $this->dispatch('notify', type: 'warning', message: __('Comment not found.'));

            return;
        }

        $comment->update([
            'status' => $status,
            'reviewed_by' => auth()->id(),
            'reviewed_at' => now(),
        ]);

        activity('content_comments')
            ->performedOn($comment)
            ->causedBy(auth()->user())
            ->event('moderated')
            ->withProperties(['status' => $status])
            ->log(__('Comment status changed'));

        $this->dispatch('notify', type: 'success', message: __('Comment status updated.'));
    }

    /**
     * @return array<string, string>
     */
    private function statusOptions(): array
    {
        return [
            Comment::STATUS_PENDING => __('Pending'),
            Comment::STATUS_APPROVED => __('Approved'),
            Comment::STATUS_REJECTED => __('Rejected'),
            Comment::STATUS_SPAM => __('Spam'),
            'all' => __('All'),
            'deleted' => __('Trash'),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function editableStatusOptions(): array
    {
        return [
            Comment::STATUS_PENDING => __('Pending'),
            Comment::STATUS_APPROVED => __('Approved'),
            Comment::STATUS_REJECTED => __('Rejected'),
            Comment::STATUS_SPAM => __('Spam'),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function targetOptions(): array
    {
        return [
            'all' => __('All Targets'),
            'product' => __('Products'),
            'blog' => __('Blog Posts'),
            'page' => __('Info Pages'),
            'faq' => __('FAQs'),
        ];
    }

    private function targetClass(string $key): ?string
    {
        return match ($key) {
            'product' => Product::class,
            'blog' => BlogPost::class,
            'page' => InfoPage::class,
            'faq' => Faq::class,
            default => null,
        };
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    private function editRules(): array
    {
        return [
            'editForm.author_name' => ['nullable', 'string', 'max:120'],
            'editForm.author_email' => ['nullable', 'email', 'max:190'],
            'editForm.locale' => ['nullable', 'string', 'max:12'],
            'editForm.rating' => ['nullable', 'integer', 'min:1', 'max:5'],
            'editForm.status' => ['required', 'string', Rule::in(Comment::statuses())],
            'editForm.is_featured' => ['boolean'],
            'editForm.body' => ['required', 'string', 'max:5000'],
        ];
    }

    private function resetEditForm(): void
    {
        $this->editingId = null;
        $this->recordId = null;
        $this->editForm = [
            'author_name' => '',
            'author_email' => '',
            'locale' => '',
            'rating' => '',
            'status' => Comment::STATUS_PENDING,
            'is_featured' => false,
            'body' => '',
        ];
        $this->resetValidation();
    }

    /**
     * @return array<string, string>
     */
    private function listRouteParameters(): array
    {
        return array_filter([
            'search' => $this->search !== '' ? $this->search : null,
            'status' => $this->status !== Comment::STATUS_PENDING ? $this->status : null,
            'target' => $this->target !== 'all' ? $this->target : null,
            'locale' => $this->locale !== 'all' ? $this->locale : null,
        ], static fn (?string $value): bool => $value !== null);
    }

    private function nullableTrim(mixed $value): ?string
    {
        $trimmed = trim((string) ($value ?? ''));

        return $trimmed !== '' ? $trimmed : null;
    }

    private function nullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (int) $value;
    }
}
