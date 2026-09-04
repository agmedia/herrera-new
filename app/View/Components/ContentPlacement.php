<?php

namespace App\View\Components;

use App\Services\Content\ContentBlockResolver;
use Closure;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\View\Component;

class ContentPlacement extends Component
{
    public Collection $items;

    public function __construct(
        public string $placement,
        public ?string $locale = null,
        public ?string $targetType = null,
        public ?string $targetRef = null,
    ) {
        $this->items = app(ContentBlockResolver::class)->forPlacement(
            placement: $this->placement,
            locale: $this->locale,
            targetType: $this->targetType,
            targetRef: $this->targetRef
        );
    }

    public function render(): View|Closure|string
    {
        return view('components.content-placement');
    }
}

