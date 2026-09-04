<?php

namespace App\Observers\Content;

use App\Services\Content\ContentBlockResolver;

class ContentCacheObserver
{
    public function created(): void
    {
        ContentBlockResolver::bumpCacheVersion();
    }

    public function updated(): void
    {
        ContentBlockResolver::bumpCacheVersion();
    }

    public function deleted(): void
    {
        ContentBlockResolver::bumpCacheVersion();
    }

    public function restored(): void
    {
        ContentBlockResolver::bumpCacheVersion();
    }
}

