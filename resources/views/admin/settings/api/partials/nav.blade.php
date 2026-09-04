<div class="admin-panel admin-search-panel p-4">
    <div class="flex flex-wrap items-center gap-2">
        @if (app(\App\Services\Catalog\CatalogFeatureService::class)->useApi())
            <a
                href="{{ route('admin.settings.api.wholesale') }}"
                class="rounded-xl px-4 py-2 text-sm font-semibold {{ request()->routeIs('admin.settings.api.wholesale') ? 'bg-slate-900 text-white' : 'border border-slate-300 text-slate-700 hover:bg-slate-100' }}"
            >
                {{ __('Wholesale API') }}
            </a>
        @endif
    </div>
</div>
