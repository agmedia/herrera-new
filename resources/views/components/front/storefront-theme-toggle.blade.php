@props(['mobile' => false])

<button
    type="button"
    class="storefront-theme-toggle {{ $mobile ? 'storefront-theme-toggle--mobile responsive-header-action' : '' }}"
    role="switch"
    aria-checked="false"
    aria-label="{{ __('herrera.theme.dark_mode') }}"
    title="{{ __('herrera.theme.enable_dark') }}"
    data-storefront-theme-toggle
    data-theme-label-dark="{{ __('herrera.theme.enable_dark') }}"
    data-theme-label-light="{{ __('herrera.theme.enable_light') }}"
    hidden
>
    <x-fa-icon name="lightbulb-on" style="light" :inline="true" data-theme-icon="light" />
    <x-fa-icon name="lightbulb" style="light" :inline="true" data-theme-icon="dark" />
</button>
