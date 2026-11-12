<section class="admin-panel admin-form-panel p-6">
    <h2 class="admin-section-title">{{ __('Postavke widgeta') }}</h2>
    <div class="mt-4 grid gap-4 md:grid-cols-2 xl:grid-cols-3">
        @foreach ($fields as $key => $field)
            @php($fieldType = $field['type'] ?? 'text')
            <div wire:key="homepage-widget-field-{{ $key }}">
                <label for="homepage-widget-{{ $key }}" class="{{ $fieldType === 'checkbox' ? 'flex items-center gap-2 text-sm font-medium' : 'mb-1 block text-xs font-semibold text-slate-600' }}">
                    @if ($fieldType === 'checkbox')
                        <input type="checkbox" id="homepage-widget-{{ $key }}" wire:model="form.widget_fields.{{ $key }}" class="rounded border-slate-300 text-cyan-700" />
                    @endif
                    {{ __($field['label']) }}
                </label>
                @if ($fieldType !== 'checkbox')
                    <input
                        id="homepage-widget-{{ $key }}"
                        type="{{ $fieldType === 'number' ? 'number' : 'text' }}"
                        wire:model="form.widget_fields.{{ $key }}"
                        @if ($fieldType === 'number') min="1" max="{{ $field['max'] }}" @endif
                        @if ($fieldType === 'url') placeholder="{{ __('Zadana poveznica') }}" @endif
                        class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm"
                    />
                @endif
                @error('form.widget_fields.'.$key) <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
            </div>
        @endforeach
    </div>
</section>
