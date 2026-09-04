<div class="space-y-6">
    <div class="admin-panel admin-search-panel p-6">
        <h1 class="text-xl font-semibold tracking-tight">{{ __('Runtime Controls') }}</h1>
        <p class="mt-2 text-sm text-slate-600">
            {{ __('Settings namespace:') }} <code>Settings/System/Runtime</code>
        </p>
    </div>

    <div class="grid gap-6 lg:grid-cols-2">
        <div class="admin-panel admin-panel-soft p-6">
            <p class="admin-section-title">{{ __('Maintenance Mode') }}</p>
            <div class="mt-3 flex items-center gap-3">
                <span class="inline-flex h-2.5 w-2.5 rounded-full {{ $isMaintenance ? 'bg-rose-500' : 'bg-emerald-500' }}"></span>
                <p class="text-sm font-medium {{ $isMaintenance ? 'text-rose-700' : 'text-emerald-700' }}">
                    {{ $isMaintenance ? __('ON (site in maintenance)') : __('OFF (site live)') }}
                </p>
            </div>
            <div class="mt-4 flex items-center gap-2">
                <button
                    type="button"
                    wire:click="toggleMaintenance"
                    class="admin-switch"
                    data-state="{{ $isMaintenance ? 'on' : 'off' }}"
                    role="switch"
                    aria-checked="{{ $isMaintenance ? 'true' : 'false' }}"
                    aria-label="{{ __('Toggle maintenance mode') }}"
                >
                    <span class="admin-switch-track">
                        <span class="admin-switch-thumb"></span>
                    </span>
                    <span class="admin-switch-label">{{ $isMaintenance ? __('On') : __('Off') }}</span>
                </button>
                <button type="button" wire:click="refreshState" class="rounded-xl border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-100">
                    {{ __('Refresh') }}
                </button>
            </div>
            <p class="mt-3 text-xs text-slate-500">
                {{ __('Turning ON sets maintenance mode and redirects via bypass secret so your admin session stays accessible.') }}
            </p>
        </div>

        <div class="admin-panel admin-panel-soft p-6">
            <p class="admin-section-title">{{ __('Cache') }}</p>
            <p class="mt-3 text-sm text-slate-600">{{ __('Clear application, config, route, and view cache.') }}</p>
            <button type="button" wire:click="clearCache" class="mt-4 rounded-xl bg-cyan-700 px-4 py-2 text-sm font-semibold text-white hover:bg-cyan-800">
                {{ __('Clean Cache') }}
            </button>
        </div>
    </div>
</div>
