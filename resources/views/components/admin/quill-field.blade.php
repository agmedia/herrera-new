@props([
    'rows' => 8,
])

<div class="admin-quill-field">
    <textarea {{ $attributes->merge([
        'rows' => $rows,
        'data-quill-editor' => '',
        'class' => 'hidden',
    ]) }}></textarea>
    <div wire:ignore class="admin-quill" data-quill-mount>
        <div class="admin-quill-editor"></div>
    </div>
</div>
