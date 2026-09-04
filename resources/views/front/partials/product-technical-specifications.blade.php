@php
    $specificationGroups = collect($rows ?? [])
        ->filter(function ($row): bool {
            return trim((string) ($row->item_name ?? '')) !== ''
                && collect((array) ($row->values ?? []))
                    ->flatten()
                    ->contains(fn ($value): bool => is_scalar($value) && trim((string) $value) !== '');
        })
        ->groupBy(fn ($row): string => trim((string) ($row->group_name ?? '')) ?: __('ui.product.specifications_general'));
@endphp

@if ($specificationGroups->isNotEmpty())
    <div class="{{ ! empty($hasAttributeSpecifications) ? 'mt-6 border-t border-slate-200 pt-6' : '' }} space-y-5" data-product-technical-specifications>
        @foreach ($specificationGroups as $groupName => $groupRows)
            <section>
                <h3 class="text-sm font-bold uppercase tracking-wide text-slate-800">{{ $groupName }}</h3>
                <dl class="mt-2 divide-y divide-slate-200 border-y border-slate-200">
                    @foreach ($groupRows as $specification)
                        @php
                            $valueText = collect((array) $specification->values)
                                ->flatten()
                                ->filter(fn ($value): bool => is_scalar($value) && trim((string) $value) !== '')
                                ->map(fn ($value): string => trim((string) $value))
                                ->unique()
                                ->implode(', ');
                            $measure = trim((string) ($specification->measure ?? ''));
                            $showMeasure = $measure !== ''
                                && ! \Illuminate\Support\Str::endsWith(\Illuminate\Support\Str::lower($valueText), \Illuminate\Support\Str::lower($measure));
                        @endphp
                        <div class="grid gap-1 py-2.5 text-sm sm:grid-cols-[minmax(12rem,40%)_1fr] sm:gap-4">
                            <dt class="font-medium text-slate-600">{{ $specification->item_name }}</dt>
                            <dd class="text-slate-900">
                                {{ $valueText }}@if ($showMeasure) {{ $measure }}@endif
                            </dd>
                        </div>
                    @endforeach
                </dl>
            </section>
        @endforeach
    </div>
@endif
