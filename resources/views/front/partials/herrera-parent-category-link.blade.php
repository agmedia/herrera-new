<a href="{{ $catalogCategoryUrl($catalogParentCategory) }}" class="catalog-parent-category-link" data-parent-category-link>
    <x-fa-icon name="arrow-left" aria-hidden="true" />
    <span>{{ __('herrera.subcategories.back_to_parent', ['category' => $catalogParentTranslation?->name ?? $catalogParentCategory->code]) }}</span>
</a>
