<?php

namespace App\Observers\Settings;

use App\Services\Settings\LocalSettingsService;
use Illuminate\Database\Eloquent\Model;

class LocalSettingObserver
{
    public function saved(Model $model): void
    {
        app(LocalSettingsService::class)->forget($model::class);
    }

    public function deleted(Model $model): void
    {
        app(LocalSettingsService::class)->forget($model::class);
    }
}
