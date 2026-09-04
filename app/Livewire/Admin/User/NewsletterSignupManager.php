<?php

namespace App\Livewire\Admin\User;

use App\Models\User\NewsletterSignup;
use App\Services\Settings\SystemSettingsService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Schema;
use Livewire\Component;
use Livewire\WithPagination;
use Silber\Bouncer\BouncerFacade as Bouncer;

class NewsletterSignupManager extends Component
{
    use WithPagination;

    private const PAGE_NAME = 'adminNewsletterSignupsPage';

    public string $search = '';
    public string $provider = '';
    public string $syncStatus = '';

    public function mount(): void
    {
        $this->authorizeAccess();
    }

    public function updatedSearch(): void
    {
        $this->resetPage(pageName: self::PAGE_NAME);
    }

    public function updatedProvider(): void
    {
        $this->resetPage(pageName: self::PAGE_NAME);
    }

    public function updatedSyncStatus(): void
    {
        $this->resetPage(pageName: self::PAGE_NAME);
    }

    public function delete(int $signupId): void
    {
        $this->authorizeAccess();

        if (! Schema::hasTable('newsletter_signups')) {
            return;
        }

        NewsletterSignup::query()->whereKey($signupId)->delete();

        $this->resetPage(pageName: self::PAGE_NAME);
        $this->dispatch('notify', type: 'success', message: __('Newsletter signup deleted.'));
    }

    public function render()
    {
        $settings = app(SystemSettingsService::class);
        $perPage = $settings->getInt(
            'admin_items_per_page',
            (int) config('admin_ui.pagination.admin_items_per_page', 20),
            5,
            200
        );

        if (! Schema::hasTable('newsletter_signups')) {
            return view('livewire.admin.user.newsletter-signup-manager', [
                'rows' => new LengthAwarePaginator([], 0, $perPage, 1, [
                    'path' => request()->url(),
                    'pageName' => self::PAGE_NAME,
                ]),
                'perPage' => $perPage,
                'tableReady' => false,
            ]);
        }

        $rows = NewsletterSignup::query()
            ->with('user:id,name,email')
            ->when($this->search !== '', function (Builder $query): void {
                $query->where(function (Builder $q): void {
                    $q->where('email', 'like', '%'.$this->search.'%')
                        ->orWhere('provider_error', 'like', '%'.$this->search.'%')
                        ->orWhereHas('user', function (Builder $userQuery): void {
                            $userQuery->where('name', 'like', '%'.$this->search.'%')
                                ->orWhere('email', 'like', '%'.$this->search.'%');
                        });
                });
            })
            ->when($this->provider !== '', fn (Builder $query) => $query->where('provider', $this->provider))
            ->when($this->syncStatus !== '', fn (Builder $query) => $query->where('sync_status', $this->syncStatus))
            ->orderByDesc('subscribed_at')
            ->orderByDesc('id')
            ->paginate($perPage, ['*'], self::PAGE_NAME);

        return view('livewire.admin.user.newsletter-signup-manager', [
            'rows' => $rows,
            'perPage' => $perPage,
            'tableReady' => true,
        ]);
    }

    private function authorizeAccess(): void
    {
        $user = auth()->user();
        abort_unless(
            $user && (Bouncer::is($user)->an('superadmin') || $user->can('users.newsletter.view')),
            403
        );
    }
}
