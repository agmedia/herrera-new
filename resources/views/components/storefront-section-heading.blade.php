<div {{ $attributes->class(['text-left']) }}>
    <span
        class="mb-[0.9rem] block h-1 w-11"
        style="background: var(--navigation-background-color, #e65100);"
        aria-hidden="true"
    ></span>
    <h2 class="text-3xl font-extrabold leading-[1.1] tracking-tight text-slate-900">{{ $slot }}</h2>
</div>
