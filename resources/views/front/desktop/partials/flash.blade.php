@if (session('status'))
    <div class="mb-6 border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-800" data-flash-message>
        <div class="flex items-start gap-3">
            <span class="mt-0.5 inline-flex h-5 w-5 shrink-0 items-center justify-center text-emerald-700" aria-hidden="true">
                <x-fa-icon name="circle-check" class="h-5 w-5" />
            </span>
            <p class="flex-1">{{ session('status') }}</p>
            <button type="button" class="inline-flex h-6 w-6 items-center justify-center text-emerald-700 hover:text-emerald-900" aria-label="{{ __('ui.notifications.close') }}" data-flash-dismiss>
                <x-fa-icon name="xmark" class="h-4 w-4" />
            </button>
        </div>
    </div>
@endif

@if (session('warning'))
    <div class="mb-6 border border-amber-200 bg-amber-50 px-4 py-3 text-sm font-medium text-amber-900" data-flash-message role="status">
        <div class="flex items-start gap-3">
            <span class="mt-0.5 inline-flex h-5 w-5 shrink-0 items-center justify-center text-amber-700" aria-hidden="true">
                <x-fa-icon name="triangle-exclamation" class="h-5 w-5" />
            </span>
            <p class="flex-1">{{ session('warning') }}</p>
            <button type="button" class="inline-flex h-6 w-6 items-center justify-center text-amber-700 hover:text-amber-900" aria-label="{{ __('ui.notifications.close') }}" data-flash-dismiss>
                <x-fa-icon name="xmark" class="h-4 w-4" />
            </button>
        </div>
    </div>
@endif
