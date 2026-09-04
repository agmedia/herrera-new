<div class="space-y-6">
    <div class="admin-panel admin-search-panel p-6">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <p class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">{{ __('Admin / Users') }}</p>
                <h1 class="mt-2 text-2xl font-semibold tracking-tight text-slate-900">{{ __('Edit User') }}</h1>
                <p class="mt-2 text-sm text-slate-600">{{ __('Update identity, segmentation, profile and addresses in one place.') }}</p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <span class="admin-chip">{{ __('User ID') }}: {{ $userId }}</span>
                <button type="button" wire:click="backToList" class="rounded-xl border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-100">{{ __('Back to List') }}</button>
            </div>
        </div>
    </div>

    <form wire:submit="save" class="space-y-6">
        <div class="admin-panel admin-form-panel p-6">
            <p class="admin-section-title">{{ __('Core Data') }}</p>

            <div class="mt-4 grid gap-3" style="grid-template-columns: repeat(12, minmax(0, 1fr));">
                <div style="grid-column: span 4;">
                    <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Name') }}</label>
                    <input type="text" wire:model="form.name" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm" />
                    @error('form.name') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                </div>
                <div style="grid-column: span 4;">
                    <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Email') }}</label>
                    <input type="email" wire:model="form.email" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm" />
                    @error('form.email') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                </div>
                <div style="grid-column: span 2;">
                    <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Role') }}</label>
                    <select wire:model="form.role" data-tom-select data-tom-no-search="1" class="admin-select w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">
                        @foreach ($roles as $role)
                            <option value="{{ $role->name }}" @selected($form['role'] === $role->name)>{{ $role->title ?: ucfirst($role->name) }}</option>
                        @endforeach
                    </select>
                    @error('form.role') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                </div>
                <div style="grid-column: span 2;">
                    <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Segments') }}</label>
                    <select wire:model="form.customer_groups" multiple data-tom-select data-tom-placeholder="{{ __('Select groups...') }}" class="admin-select w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">
                        @foreach ($customerGroups as $group)
                            <option value="{{ $group->id }}" @selected(in_array((string) $group->id, array_map('strval', (array) ($form['customer_groups'] ?? [])), true))>{{ $group->name }}</option>
                        @endforeach
                    </select>
                    @error('form.customer_groups.*') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                </div>
            </div>

            <div class="mt-4 flex flex-wrap items-center gap-3">
                <button
                    type="button"
                    wire:click="$toggle('form.email_verified')"
                    class="admin-switch"
                    data-state="{{ $form['email_verified'] ? 'on' : 'off' }}"
                    role="switch"
                    aria-checked="{{ $form['email_verified'] ? 'true' : 'false' }}"
                    aria-label="{{ __('Toggle email verified state') }}"
                >
                    <span class="admin-switch-track"><span class="admin-switch-thumb"></span></span>
                    <span class="admin-switch-label">{{ $form['email_verified'] ? __('Email Verified') : __('Email Unverified') }}</span>
                </button>
            </div>
        </div>

        <div class="admin-panel admin-form-panel p-6">
            <p class="admin-section-title">{{ __('Profile') }}</p>

            <div class="mt-4 grid gap-3" style="grid-template-columns: repeat(12, minmax(0, 1fr));">
                <div style="grid-column: span 2;">
                    <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('First Name') }}</label>
                    <input type="text" wire:model="form.profile.first_name" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm" />
                </div>
                <div style="grid-column: span 2;">
                    <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Last Name') }}</label>
                    <input type="text" wire:model="form.profile.last_name" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm" />
                </div>
                <div style="grid-column: span 2;">
                    <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Phone') }}</label>
                    <input type="text" wire:model="form.profile.phone" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm" />
                </div>
                <div style="grid-column: span 2;">
                    <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Birthday') }}</label>
                    <input type="date" wire:model="form.profile.birthday" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm" />
                </div>
                <div style="grid-column: span 2;">
                    <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Gender') }}</label>
                    <select wire:model="form.profile.gender" data-tom-select data-tom-no-search="1" class="admin-select w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">
                        <option value="">-</option>
                        <option value="male">{{ __('Male') }}</option>
                        <option value="female">{{ __('Female') }}</option>
                        <option value="other">{{ __('Other') }}</option>
                    </select>
                </div>
                <div style="grid-column: span 2;">
                    <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Affiliate Name') }}</label>
                    <input type="text" wire:model="form.profile.affiliate_name" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm" />
                </div>
                <div style="grid-column: span 3;">
                    <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Company') }}</label>
                    <input type="text" wire:model="form.profile.company" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm" />
                </div>
                <div style="grid-column: span 2;">
                    <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('OIB') }}</label>
                    <input type="text" wire:model="form.profile.oib" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm" />
                </div>
                <div style="grid-column: span 2;">
                    <div class="mt-6 flex items-center">
                        <button
                            type="button"
                            wire:click="$toggle('form.profile.newsletter_opt_in')"
                            class="admin-switch"
                            data-state="{{ $form['profile']['newsletter_opt_in'] ? 'on' : 'off' }}"
                            role="switch"
                            aria-checked="{{ $form['profile']['newsletter_opt_in'] ? 'true' : 'false' }}"
                            aria-label="{{ __('Toggle newsletter opt in') }}"
                        >
                            <span class="admin-switch-track"><span class="admin-switch-thumb"></span></span>
                            <span class="admin-switch-label">{{ $form['profile']['newsletter_opt_in'] ? __('Newsletter On') : __('Newsletter Off') }}</span>
                        </button>
                    </div>
                </div>
                <div style="grid-column: span 5;">
                    <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Bio') }}</label>
                    <textarea wire:model="form.profile.bio" rows="3" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm"></textarea>
                </div>
            </div>
        </div>

        <div class="admin-panel admin-form-panel p-6">
            <p class="admin-section-title">{{ __('Billing Address') }}</p>
            <div class="mt-4 grid gap-3" style="grid-template-columns: repeat(12, minmax(0, 1fr));">
                <div style="grid-column: span 2;">
                    <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('First Name') }}</label>
                    <input type="text" wire:model="form.billing_address.first_name" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm" />
                </div>
                <div style="grid-column: span 2;">
                    <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Last Name') }}</label>
                    <input type="text" wire:model="form.billing_address.last_name" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm" />
                </div>
                <div style="grid-column: span 3;">
                    <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Company') }}</label>
                    <input type="text" wire:model="form.billing_address.company" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm" />
                </div>
                <div style="grid-column: span 2;">
                    <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('OIB') }}</label>
                    <input type="text" wire:model="form.billing_address.oib" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm" />
                </div>
                <div style="grid-column: span 3;">
                    <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('VAT ID') }}</label>
                    <input type="text" wire:model="form.billing_address.vat_id" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm" />
                </div>
                <div style="grid-column: span 6;">
                    <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Address Line 1') }}</label>
                    <input type="text" wire:model="form.billing_address.address_line_1" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm" />
                </div>
                <div style="grid-column: span 6;">
                    <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Address Line 2') }}</label>
                    <input type="text" wire:model="form.billing_address.address_line_2" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm" />
                </div>
                <div style="grid-column: span 2;">
                    <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Postal Code') }}</label>
                    <input type="text" wire:model="form.billing_address.postal_code" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm" />
                </div>
                <div style="grid-column: span 4;">
                    <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('City') }}</label>
                    <input type="text" wire:model="form.billing_address.city" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm" />
                </div>
                <div style="grid-column: span 2;">
                    <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('CC') }}</label>
                    <input type="text" wire:model="form.billing_address.country_code" maxlength="2" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm uppercase" />
                </div>
                <div style="grid-column: span 4;">
                    <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Phone') }}</label>
                    <input type="text" wire:model="form.billing_address.phone" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm" />
                </div>
            </div>
        </div>

        <div class="admin-panel admin-form-panel p-6">
            <p class="admin-section-title">{{ __('Shipping Address') }}</p>
            <div class="mt-4 grid gap-3" style="grid-template-columns: repeat(12, minmax(0, 1fr));">
                <div style="grid-column: span 3;">
                    <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('First Name') }}</label>
                    <input type="text" wire:model="form.shipping_address.first_name" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm" />
                </div>
                <div style="grid-column: span 3;">
                    <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Last Name') }}</label>
                    <input type="text" wire:model="form.shipping_address.last_name" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm" />
                </div>
                <div style="grid-column: span 3;">
                    <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('OIB') }}</label>
                    <input type="text" wire:model="form.shipping_address.oib" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm" />
                </div>
                <div style="grid-column: span 3;">
                    <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('VAT ID') }}</label>
                    <input type="text" wire:model="form.shipping_address.vat_id" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm" />
                </div>
                <div style="grid-column: span 6;">
                    <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Address Line 1') }}</label>
                    <input type="text" wire:model="form.shipping_address.address_line_1" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm" />
                </div>
                <div style="grid-column: span 6;">
                    <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Address Line 2') }}</label>
                    <input type="text" wire:model="form.shipping_address.address_line_2" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm" />
                </div>
                <div style="grid-column: span 2;">
                    <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Postal Code') }}</label>
                    <input type="text" wire:model="form.shipping_address.postal_code" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm" />
                </div>
                <div style="grid-column: span 4;">
                    <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('City') }}</label>
                    <input type="text" wire:model="form.shipping_address.city" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm" />
                </div>
                <div style="grid-column: span 2;">
                    <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('CC') }}</label>
                    <input type="text" wire:model="form.shipping_address.country_code" maxlength="2" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm uppercase" />
                </div>
                <div style="grid-column: span 4;">
                    <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Phone') }}</label>
                    <input type="text" wire:model="form.shipping_address.phone" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm" />
                </div>
            </div>
        </div>

        <div class="admin-panel admin-form-panel p-6">
            <p class="admin-section-title">{{ __('Password Reset (Optional)') }}</p>
            <p class="mt-1 text-sm text-slate-600">{{ __('Leave blank to keep current password.') }}</p>

            <div class="mt-4 grid gap-3" style="grid-template-columns: repeat(12, minmax(0, 1fr));">
                <div style="grid-column: span 3;">
                    <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('New Password') }}</label>
                    <input type="password" wire:model="form.password" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm" autocomplete="new-password" />
                    @error('form.password') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                </div>
                <div style="grid-column: span 3;">
                    <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Confirm Password') }}</label>
                    <input type="password" wire:model="form.password_confirmation" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm" autocomplete="new-password" />
                </div>
            </div>
        </div>

        <div class="admin-form-actions flex items-center gap-2 pt-2">
            <button type="submit" class="rounded-xl bg-cyan-700 px-4 py-2 text-sm font-semibold text-white hover:bg-cyan-800">
                {{ __('Update User') }}
            </button>
            <button type="button" wire:click="backToList" class="rounded-xl border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-100">
                {{ __('Cancel') }}
            </button>
        </div>
    </form>
</div>
