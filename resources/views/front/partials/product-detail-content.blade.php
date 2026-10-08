@php
    $commentFormHasErrors = $errors->has('author_name')
        || $errors->has('author_email')
        || $errors->has('body')
        || $errors->has('rating');
    $commentUser = auth()->user();
    $hasAttributeSpecifications = $product->relationLoaded('attributes')
        && $product->attributes->contains(
            fn ($attribute): bool => ! $attribute->isMsanManaged(),
        );
    $technicalSpecificationRows = app(\App\Support\ProductSpecificationPresenter::class)
        ->remainingRows($product, $locale, $fallbackLocale);
    $hasImportedSpecifications = $technicalSpecificationRows->isNotEmpty();
    $hasSpecificationAttributes = $hasAttributeSpecifications || $hasImportedSpecifications;
    $firstDetailSectionId = $hasProductStory
        ? 'product-description'
        : ($hasSpecificationAttributes ? 'product-specifications' : 'product-comments');
    $isHerreraProductContent = str_contains(strtolower((string) ($storeSettings['branding']['store_name'] ?? config('app.name'))), 'herrera');
@endphp

@if ($isHerreraProductContent)
    @once
        @push('styles')
            <link rel="stylesheet" href="{{ asset('front-theme/styles/herrera-product-tabs.css') }}?v={{ filemtime(public_path('front-theme/styles/herrera-product-tabs.css')) }}">
        @endpush
    @endonce
@endif

<section class="product-detail-lower" data-product-detail-lower>
    @unless ($isHerreraProductContent)
    <nav class="product-detail-tabs" aria-label="{{ __('ui.product.detail_navigation') }}" data-product-detail-tabs>
        @if ($hasProductStory)
            <a
                href="#product-description"
                class="{{ $firstDetailSectionId === 'product-description' ? 'is-active' : '' }}"
                data-product-detail-tab
                @if ($firstDetailSectionId === 'product-description') aria-current="true" @endif
            >
                <span class="product-detail-tab-description-full">{{ __('ui.product.description') }}</span>
                <span class="product-detail-tab-description-short">{{ __('ui.product.description_short') }}</span>
            </a>
        @endif
        @if ($hasSpecificationAttributes)
            <a
                href="#product-specifications"
                class="{{ $firstDetailSectionId === 'product-specifications' ? 'is-active' : '' }}"
                data-product-detail-tab
                @if ($firstDetailSectionId === 'product-specifications') aria-current="true" @endif
            >
                {{ __('ui.product.specifications') }}
            </a>
        @endif
        <a
            href="#product-comments"
            class="{{ $firstDetailSectionId === 'product-comments' ? 'is-active' : '' }}"
            data-product-detail-tab
            @if ($firstDetailSectionId === 'product-comments') aria-current="true" @endif
        >
            {{ __('ui.product.comments_title') }}
        </a>
    </nav>
    @endunless

    @if ($hasProductStory)
        <section id="product-description" class="product-detail-content-section" data-product-detail-section>
            <header class="product-detail-section-intro">
                <h2 class="product-detail-section-heading">{{ __('ui.product.description') }}</h2>
            </header>

            <div class="product-detail-section-body">
                @if (! empty($translation?->description))
                    <div class="product-detail-description-content">{!! $translation->description !!}</div>
                @elseif (! empty($translation?->excerpt))
                    <p class="product-detail-description-content">{{ $translation->excerpt }}</p>
                @endif
            </div>
        </section>
    @endif

    @if ($hasSpecificationAttributes)
        <section id="product-specifications" class="product-detail-content-section" data-product-detail-section>
            <header class="product-detail-section-intro">
                <h2 class="product-detail-section-heading">{{ __('ui.product.specifications') }}</h2>
            </header>

            <div class="product-detail-section-body">
                @if ($hasAttributeSpecifications)
                    @include('front.partials.product-attribute-panels', [
                        'product' => $product,
                        'locale' => $locale,
                        'fallbackLocale' => $fallbackLocale,
                        'containerClass' => 'product-detail-attribute-panels',
                    ])
                @endif
                @if ($hasImportedSpecifications)
                    @include('front.partials.product-technical-specifications', [
                        'rows' => $technicalSpecificationRows,
                    ])
                @endif
            </div>
        </section>
    @endif

    <section id="product-comments" class="product-comments-anchor product-detail-content-section" data-product-detail-section>
        <header class="product-detail-section-intro product-detail-comments-heading">
            <h2 class="product-detail-section-heading">{{ __('ui.product.comments_title') }}</h2>
            <button
                type="button"
                class="product-detail-comment-toggle"
                data-comment-form-toggle
                aria-expanded="{{ $commentFormHasErrors ? 'true' : 'false' }}"
            >
                {{ __('ui.product.comment_form.toggle') }}
            </button>
        </header>

        <div class="product-detail-section-body">
            <div class="{{ $commentFormHasErrors ? '' : 'hidden' }} product-detail-comment-form" data-comment-form-panel>
                <form method="POST" action="{{ route('products.comments.store', ['slug' => $translation?->slug ?? request()->route('slug')]) }}">
                    @csrf
                    <div class="product-detail-comment-grid">
                        <div class="product-detail-field">
                            <label for="product-comment-author">{{ __('ui.product.comment_form.name') }} <x-front.required-mark :required="! $commentUser" /></label>
                            <input id="product-comment-author" type="text" name="author_name" value="{{ old('author_name', $commentUser?->name ?? '') }}" @if($commentUser) readonly @else required @endif>
                            @error('author_name') <p class="product-detail-field-error">{{ $message }}</p> @enderror
                        </div>
                        <div class="product-detail-field">
                            <label for="product-comment-email">{{ __('ui.product.comment_form.email') }} <x-front.required-mark :required="! $commentUser" /></label>
                            <input id="product-comment-email" type="email" name="author_email" value="{{ old('author_email', $commentUser?->email ?? '') }}" @if($commentUser) readonly @else required @endif>
                            @error('author_email') <p class="product-detail-field-error">{{ $message }}</p> @enderror
                        </div>
                    </div>

                    <div class="product-detail-field">
                        <label for="product-comment-rating">{{ __('ui.product.comment_form.rating') }}</label>
                        <select id="product-comment-rating" name="rating">
                            <option value="">{{ __('ui.product.comment_form.rating_optional') }}</option>
                            @for ($i = 5; $i >= 1; $i--)
                                <option value="{{ $i }}" @selected((string) old('rating') === (string) $i)>{{ $i }} ★</option>
                            @endfor
                        </select>
                        @error('rating') <p class="product-detail-field-error">{{ $message }}</p> @enderror
                    </div>

                    <div class="product-detail-field">
                        <label for="product-comment-body">{{ __('ui.product.comment_form.body') }} <x-front.required-mark /></label>
                        <textarea id="product-comment-body" name="body" rows="5" required>{{ old('body') }}</textarea>
                        @error('body') <p class="product-detail-field-error">{{ $message }}</p> @enderror
                    </div>

                    <button type="submit" class="product-detail-comment-submit">
                        {{ __('ui.product.comment_form.submit') }}
                    </button>
                </form>
            </div>

            @if (($comments ?? collect())->isNotEmpty())
                <div class="product-detail-comments-list">
                    @foreach ($comments as $comment)
                        <article class="product-detail-comment">
                            <div class="product-detail-comment-meta">
                                <p>{{ $comment->author_name ?: ($comment->user?->name ?? __('ui.product.comments_anonymous')) }}</p>
                                @if ((int) ($comment->rating ?? 0) > 0)
                                    <p aria-label="{{ (int) $comment->rating }} / 5">{{ str_repeat('★', (int) $comment->rating) }}</p>
                                @endif
                            </div>
                            <p class="product-detail-comment-body">{{ $comment->body }}</p>
                        </article>
                    @endforeach
                </div>
            @else
                <p class="product-detail-comments-empty">{{ __('ui.product.comments_empty') }}</p>
            @endif
        </div>
    </section>
</section>
