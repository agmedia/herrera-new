<div class="inline-flex flex-wrap items-center gap-1">
    <button type="button" wire:click="openPreview({{ $actionBlock->id }}, {{ $actionSlot?->id ?? 'null' }})" class="rounded-lg border border-cyan-200 px-2 py-1 text-xs font-semibold text-cyan-800 hover:bg-cyan-50">{{ __('Preview') }}</button>
    @if ($canUpdateBlocks)
        <a href="{{ route('admin.content.blocks.edit', ['block' => $actionBlock->id]) }}" class="rounded-lg border border-slate-300 px-2 py-1 text-xs font-semibold text-slate-700 hover:bg-slate-100">{{ __('Edit') }}</a>
    @endif
    @if ($canDeleteBlocks && ! in_array($actionBlock->code, \App\Support\HerreraHomeWidgets::FIXED_CODES, true))
        <button type="button" wire:click="delete({{ $actionBlock->id }})" wire:confirm="{{ __('Delete this content block?') }}" class="rounded-lg border border-rose-200 px-2 py-1 text-xs font-semibold text-rose-700 hover:bg-rose-50">{{ __('Delete') }}</button>
    @endif
</div>
