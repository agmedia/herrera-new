<?php

namespace App\Livewire\Admin\Settings\System;

use App\Support\AssetVersion;
use Illuminate\Foundation\Http\MaintenanceModeBypassCookie;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cookie;
use Livewire\Component;
use Silber\Bouncer\BouncerFacade as Bouncer;

class RuntimeTools extends Component
{
    public bool $isMaintenance = false;

    public function mount(): void
    {
        $this->refreshState();
    }

    public function toggleMaintenance(): void
    {
        $this->ensurePrivilegedAdmin();

        if ($this->isMaintenance) {
            Artisan::call('up');
            $this->refreshState();
            $this->dispatch('notify', type: 'success', message: __('Maintenance mode switched OFF.'));

            return;
        }

        $secret = (string) config('app.maintenance_bypass_secret', 'agshop-admin-bypass');
        Artisan::call('down', ['--secret' => $secret]);
        Cookie::queue(MaintenanceModeBypassCookie::create($secret));

        $this->refreshState();
        $this->dispatch('notify', type: 'warning', message: __('Maintenance mode switched ON. Redirecting to bypass URL.'));
        $this->redirect('/'.$secret, navigate: false);
    }

    public function clearCache(): void
    {
        $this->ensurePrivilegedAdmin();

        Artisan::call('cache:clear');
        Artisan::call('config:clear');
        Artisan::call('view:clear');
        Artisan::call('route:clear');
        app(AssetVersion::class)->bump();

        $this->dispatch('notify', type: 'success', message: __('Application cache has been cleared.'));
    }

    public function refreshState(): void
    {
        $this->isMaintenance = app()->isDownForMaintenance();
    }

    private function ensurePrivilegedAdmin(): void
    {
        $user = auth()->user();

        abort_unless(
            $user && (Bouncer::is($user)->an('superadmin') || $user->can('settings.system.runtime.manage')),
            403
        );
    }

    public function render()
    {
        return view('livewire.admin.settings.system.runtime-tools');
    }
}
