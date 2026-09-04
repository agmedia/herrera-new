@php
    $mobileOffset = (bool) ($mobileOffset ?? false);
    $buttonLabel = app()->getLocale() === 'hr' ? 'Povratak na vrh' : 'Back to top';
@endphp

<button
    type="button"
    class="scroll-to-top-button{{ $mobileOffset ? ' scroll-to-top-button--mobile' : '' }}"
    data-scroll-to-top
    aria-label="{{ $buttonLabel }}"
>
    <x-fa-icon name="arrow-up" />
</button>
