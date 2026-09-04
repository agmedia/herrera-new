<?php

namespace App\Livewire\Admin\User;

use App\Models\User\UserTrackingEvent;
use App\Services\Settings\SystemSettingsService;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Component;
use Livewire\WithPagination;
use Silber\Bouncer\BouncerFacade as Bouncer;
use Spatie\Activitylog\Models\Activity;

class ActivityManager extends Component
{
    use WithPagination;

    private const ADMIN_PAGE_NAME = 'adminAuditPage';
    private const TRACKING_PAGE_NAME = 'userTrackingPage';

    public string $source = 'admin';
    public string $search = '';

    public function mount(): void
    {
        $this->authorizeAccess();
    }

    public function updatedSource(): void
    {
        $this->resetPage(pageName: self::ADMIN_PAGE_NAME);
        $this->resetPage(pageName: self::TRACKING_PAGE_NAME);
    }

    public function updatedSearch(): void
    {
        $this->resetPage(pageName: self::ADMIN_PAGE_NAME);
        $this->resetPage(pageName: self::TRACKING_PAGE_NAME);
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
        $loyaltyEnabled = (bool) $settings->get(
            'user_loyalty_enabled',
            (bool) config('user_features.flags.user_loyalty_enabled', false)
        );

        if (! $loyaltyEnabled && $this->source === 'loyalty') {
            $this->source = 'admin';
        }

        if ($this->source === 'tracking') {
            $rows = UserTrackingEvent::query()
                ->with('user:id,name,email')
                ->when($this->search !== '', function (Builder $query): void {
                    $query->where(function (Builder $q): void {
                        $q->where('event', 'like', '%'.$this->search.'%')
                            ->orWhere('url', 'like', '%'.$this->search.'%')
                            ->orWhere('referrer', 'like', '%'.$this->search.'%')
                            ->orWhere('ip_address', 'like', '%'.$this->search.'%')
                            ->orWhereHas('user', function (Builder $uq): void {
                                $uq->where('name', 'like', '%'.$this->search.'%')
                                    ->orWhere('email', 'like', '%'.$this->search.'%');
                            });
                    });
                })
                ->orderByDesc('occurred_at')
                ->orderByDesc('id')
                ->paginate($perPage, ['*'], self::TRACKING_PAGE_NAME);
        } else {
            $rows = Activity::query()
                ->with('causer:id,name,email')
                ->when($this->source === 'admin', function (Builder $query): void {
                    $query->where(function (Builder $q): void {
                        $q->whereNull('log_name')
                            ->orWhere('log_name', '!=', 'loyalty');
                    });
                })
                ->when($this->source === 'loyalty', function (Builder $query): void {
                    $query->where('log_name', 'loyalty');
                })
                ->when($this->search !== '', function (Builder $query): void {
                    $query->where(function (Builder $q): void {
                        $q->where('log_name', 'like', '%'.$this->search.'%')
                            ->orWhere('event', 'like', '%'.$this->search.'%')
                            ->orWhere('description', 'like', '%'.$this->search.'%')
                            ->orWhere('subject_type', 'like', '%'.$this->search.'%')
                            ->orWhereHas('causer', function (Builder $uq): void {
                                $uq->where('name', 'like', '%'.$this->search.'%')
                                    ->orWhere('email', 'like', '%'.$this->search.'%');
                            });
                    });
                })
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->paginate($perPage, ['*'], self::ADMIN_PAGE_NAME);
        }

        return view('livewire.admin.user.activity-manager', [
            'rows' => $rows,
            'perPage' => $perPage,
            'loyaltyEnabled' => $loyaltyEnabled,
        ]);
    }

    private function authorizeAccess(): void
    {
        $user = auth()->user();
        abort_unless(
            $user && (Bouncer::is($user)->an('superadmin') || $user->can('users.activity.view')),
            403
        );
    }
}
