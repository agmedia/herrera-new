<x-front.desktop.product-card
    :product="$product"
    :locale="$locale ?? null"
    :fallback-locale="$fallbackLocale ?? null"
    :flat="(bool) ($flat ?? false)"
    :lined="(bool) ($lined ?? false)"
    :priority-image="(bool) ($priorityImage ?? false)"
    :heading-level="(int) ($headingLevel ?? 3)"
/>
