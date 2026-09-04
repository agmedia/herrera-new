<div class="space-y-6">
    <div class="admin-panel admin-search-panel p-6">
        <div class="flex items-end justify-between gap-4">
            <div>
                <h1 class="text-xl font-semibold tracking-tight">{{ __('Newsletter Signups') }}</h1>
                <p class="mt-1 text-sm text-slate-600">{{ __('Newsletter subscriptions captured from storefront footer signup and provider sync.') }}</p>
                <p class="mt-2 text-xs text-slate-500">{{ __('Items per page') }}: <span class="admin-chip">{{ $perPage }}</span></p>
            </div>

            <div class="flex w-[66rem] max-w-full items-end justify-end gap-3">
                <div class="grid w-full max-w-[64rem] items-end gap-3" style="grid-template-columns: minmax(24rem, 1fr) 12rem 12rem;">
                    <div>
                        <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('admin.common.search') }}</label>
                        <input
                            type="text"
                            wire:model.live.debounce.300ms="search"
                            placeholder="{{ __('Email, user, sync error...') }}"
                            class="admin-search-input w-full rounded-xl border px-3 py-2 text-sm"
                        />
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Provider') }}</label>
                        <select wire:model.live="provider" data-tom-select data-tom-no-search="1" class="admin-select w-full rounded-xl border px-3 py-2 text-sm">
                            <option value="">{{ __('All providers') }}</option>
                            <option value="none">{{ __('None') }}</option>
                            <option value="database">{{ __('Database') }}</option>
                            <option value="mailchimp">{{ __('Mailchimp') }}</option>
                            <option value="klaviyo">{{ __('Klaviyo') }}</option>
                        </select>
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Sync Status') }}</label>
                        <select wire:model.live="syncStatus" data-tom-select data-tom-no-search="1" class="admin-select w-full rounded-xl border px-3 py-2 text-sm">
                            <option value="">{{ __('All sync states') }}</option>
                            <option value="pending">{{ __('Pending') }}</option>
                            <option value="synced">{{ __('Synced') }}</option>
                            <option value="skipped">{{ __('Skipped') }}</option>
                            <option value="failed">{{ __('Failed') }}</option>
                        </select>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="admin-panel admin-panel-soft p-5">
        <h2 class="admin-section-title">{{ __('admin.common.items') }}</h2>

        @if (! $tableReady)
            <div class="mt-4 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
                {{ __('Newsletter table is not created yet. Run the SQL from database/sql/newsletter_signups.sql first.') }}
            </div>
        @endif

        <div class="mt-4 overflow-x-auto">
            <table class="admin-items-table min-w-full text-sm">
                <thead class="text-slate-600">
                    <tr>
                        <th class="px-3 py-2 text-center font-semibold">{{ __('Time') }}</th>
                        <th class="px-3 py-2 text-left font-semibold">{{ __('Email') }}</th>
                        <th class="px-3 py-2 text-left font-semibold">{{ __('User') }}</th>
                        <th class="px-3 py-2 text-center font-semibold">{{ __('Provider') }}</th>
                        <th class="px-3 py-2 text-center font-semibold">{{ __('Sync') }}</th>
                        <th class="px-3 py-2 text-center font-semibold">{{ __('Consent') }}</th>
                        <th class="px-3 py-2 text-left font-semibold">{{ __('Details') }}</th>
                        <th class="px-3 py-2 text-right font-semibold">{{ __('admin.common.actions') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($rows as $row)
                        @php
                            $syncBadgeClass = match ($row->sync_status) {
                                'synced' => 'bg-emerald-100 text-emerald-800',
                                'failed' => 'bg-rose-100 text-rose-700',
                                'pending' => 'bg-amber-100 text-amber-800',
                                default => 'bg-slate-100 text-slate-700',
                            };
                            $providerLabel = match ($row->provider) {
                                'database' => __('Database'),
                                'mailchimp' => 'Mailchimp',
                                'klaviyo' => 'Klaviyo',
                                default => __('None'),
                            };
                        @endphp
                        <tr>
                            <td class="px-3 py-2 text-center text-xs text-slate-600">{{ $row->subscribed_at?->format('Y-m-d H:i:s') ?? optional($row->created_at)->format('Y-m-d H:i:s') ?? '-' }}</td>
                            <td class="px-3 py-2 text-slate-800">
                                <div class="font-medium">{{ $row->email }}</div>
                                <div class="mt-1 text-xs text-slate-500">{{ strtoupper((string) $row->locale) }} / {{ $row->source }}</div>
                            </td>
                            <td class="px-3 py-2 text-slate-700">
                                @if ($row->user)
                                    <a href="{{ route('admin.users.show', ['user' => $row->user->id]) }}" class="font-medium text-slate-800 hover:text-slate-600">{{ $row->user->name }}</a>
                                    <div class="text-xs text-slate-500">{{ $row->user->email }}</div>
                                @else
                                    <span class="text-xs text-slate-500">{{ __('Guest') }}</span>
                                @endif
                            </td>
                            <td class="px-3 py-2 text-center">
                                <span class="rounded-full bg-slate-100 px-2.5 py-1 text-xs font-semibold text-slate-700">{{ $providerLabel }}</span>
                            </td>
                            <td class="px-3 py-2 text-center">
                                <span class="rounded-full px-2.5 py-1 text-xs font-semibold {{ $syncBadgeClass }}">{{ ucfirst((string) $row->sync_status) }}</span>
                            </td>
                            <td class="px-3 py-2 text-center">
                                <span class="rounded-full px-2.5 py-1 text-xs font-semibold {{ $row->consent_accepted ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-100 text-slate-600' }}">
                                    {{ $row->consent_accepted ? __('admin.common.yes') : __('admin.common.no') }}
                                </span>
                            </td>
                            <td class="px-3 py-2 text-xs text-slate-700">
                                <div>{{ __('IP') }}: {{ $row->ip_address ?: '-' }}</div>
                                @if ($row->provider_reference)
                                    <div class="mt-1 text-slate-500">{{ __('Ref') }}: {{ $row->provider_reference }}</div>
                                @endif
                                @if ($row->provider_error)
                                    <div class="mt-1 max-w-[28rem] text-rose-600">{{ $row->provider_error }}</div>
                                @endif
                            </td>
                            <td class="px-3 py-2 text-right">
                                <button
                                    type="button"
                                    wire:click="delete({{ $row->id }})"
                                    wire:confirm="{{ __('Delete newsletter signup :email?', ['email' => $row->email]) }}"
                                    class="rounded-lg border border-rose-200 px-3 py-1.5 text-xs font-semibold text-rose-700 hover:bg-rose-50"
                                >
                                    {{ __('Delete') }}
                                </button>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="px-3 py-8 text-center text-sm text-slate-500">{{ __('No newsletter signups found.') }}</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="mt-4">
            {{ $rows->links() }}
        </div>
    </div>
</div>
