<p>{{ count($rule->manufacturer_ids ?? []) ? collect($rule->manufacturer_ids)->map(fn ($id) => $manufacturerLabels[$id] ?? '#'.$id)->join(', ') : __('Svi dobavljači / brendovi') }}</p>
<p class="mt-1 text-xs text-slate-500">{{ count($rule->category_ids ?? []) ? collect($rule->category_ids)->map(fn ($id) => $categoryLabels[$id] ?? '#'.$id)->join(', ') : __('Sve kategorije') }}</p>
@if (count($rule->category_ids ?? []))
    <p class="mt-1 text-xs text-slate-500">{{ $rule->include_descendants ? __('Uključuje podkategorije') : __('Bez podkategorija') }}</p>
@endif
@if (count($rule->excluded_product_ids ?? []))
    <p class="mt-1 text-xs text-slate-500">{{ count($rule->excluded_product_ids) }} {{ __('izuzetih artikala') }}</p>
@endif
