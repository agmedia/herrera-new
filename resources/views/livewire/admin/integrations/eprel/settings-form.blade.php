<section class="admin-panel admin-form-panel p-6">
    <form wire:submit="save" class="max-w-3xl space-y-6">
        <div class="rounded-xl border border-slate-200 bg-slate-50 p-4">
            <label class="flex items-start gap-3" for="eprel-enabled">
                <input id="eprel-enabled" type="checkbox" wire:model="form.eprel_enabled" class="mt-1 rounded border-slate-300 text-cyan-700 focus:ring-cyan-600">
                <span><span class="block text-sm font-semibold text-slate-900">{{ __('Omogući EPREL API') }}</span><span class="mt-1 block text-sm text-slate-600">{{ __('API koristi pojedinačni dohvat na artiklu i zasebnu skupnu obradu kataloga. Spremanje ovih postavki ne pokreće obradu i ne mijenja cijene ni zalihe.') }}</span></span>
            </label>
            @error('form.eprel_enabled') <p class="mt-2 text-xs text-rose-600">{{ $message }}</p> @enderror
        </div>

        <div>
            <label for="eprel-api-key" class="mb-1 block text-sm font-semibold text-slate-700">{{ __('EPREL API ključ') }}</label>
            <input id="eprel-api-key" type="password" wire:model="form.eprel_api_key" autocomplete="new-password" maxlength="2048" placeholder="{{ $apiKeyConfigured ? __('Ključ je spremljen — prazno ga zadržava') : __('Unesite EPREL API ključ') }}" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">
            <p class="mt-1 text-xs text-slate-500">{{ __('Ključ se sprema šifrirano i nikad se ponovno ne prikazuje. Za zamjenu unesite novi ključ; prazno polje zadržava postojeći.') }}</p>
            <p class="mt-2 text-xs font-semibold {{ $apiKeyConfigured ? 'text-emerald-700' : 'text-amber-700' }}">{{ $apiKeyConfigured ? __('API ključ je spremljen.') : __('API ključ još nije spremljen.') }}</p>
            @error('form.eprel_api_key') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
        </div>

        <div class="grid gap-4 sm:grid-cols-2">
            <div>
                <label for="eprel-connect-timeout" class="mb-1 block text-sm font-semibold text-slate-700">{{ __('Vrijeme spajanja (sekunde)') }}</label>
                <input id="eprel-connect-timeout" type="number" min="2" max="30" wire:model="form.eprel_connect_timeout" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">
                @error('form.eprel_connect_timeout') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="eprel-timeout" class="mb-1 block text-sm font-semibold text-slate-700">{{ __('Vrijeme dohvata (sekunde)') }}</label>
                <input id="eprel-timeout" type="number" min="5" max="120" wire:model="form.eprel_timeout" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">
                @error('form.eprel_timeout') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
            </div>
        </div>

        <div class="flex items-center gap-4 border-t border-slate-200 pt-5">
            <button type="submit" wire:loading.attr="disabled" wire:target="save" class="min-h-11 rounded-xl bg-cyan-700 px-5 py-2 text-sm font-semibold text-white hover:bg-cyan-800 disabled:cursor-wait disabled:opacity-60"><span wire:loading.remove wire:target="save">{{ __('Spremi EPREL postavke') }}</span><span wire:loading wire:target="save">{{ __('Spremam...') }}</span></button>
            <p wire:dirty class="text-sm text-amber-700">{{ __('Imate nespremljene promjene.') }}</p>
        </div>
    </form>
</section>
