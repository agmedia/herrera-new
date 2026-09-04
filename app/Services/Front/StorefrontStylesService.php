<?php

namespace App\Services\Front;

class StorefrontStylesService
{
    public function __construct(
        private readonly NavigationMenuService $navigation,
        private readonly StoreSettingsService $storeSettings
    ) {}

    public function css(): string
    {
        $appearance = $this->navigation->appearance();
        $topBar = $this->navigation->topBar();
        $announcement = $this->storeSettings->announcement();

        return sprintf(
            ':root{--storefront-container-width:%dpx;--header-content-width:%dpx;--navigation-item-height:%dpx;--navigation-font-size:%dpx;--header-logo-height:%dpx;--navigation-background-color:%s;--navigation-text-color:%s;--navigation-highlight-color:%s;--top-bar-height:%dpx;--top-bar-font-size:%dpx;--top-bar-background-color:%s;--top-bar-text-color:%s;--top-bar-border-color:%s;--store-announcement-background-color:%s;--store-announcement-text-color:%s;--store-announcement-duration:%ds;}',
            $appearance['container_width'],
            $appearance['header_content_width'],
            $appearance['item_height'],
            $appearance['font_size'],
            $appearance['logo_height'],
            $appearance['background_color'],
            $appearance['text_color'],
            $appearance['highlight_color'],
            $topBar['height'],
            $topBar['font_size'],
            $topBar['background_color'],
            $topBar['text_color'],
            $topBar['border_color'],
            $announcement['background_color'],
            $announcement['text_color'],
            $announcement['scroll_duration_seconds'],
        );
    }
}
