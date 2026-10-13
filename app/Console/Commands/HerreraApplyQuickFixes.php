<?php

namespace App\Console\Commands;

use App\Services\Import\HerreraBlogRefreshService;
use App\Services\Import\HerreraHomeBlogCarouselService;
use App\Services\Import\HerreraQuickFixConfigurationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class HerreraApplyQuickFixes extends Command
{
    protected $signature = 'herrera:apply-quick-fixes';

    protected $description = 'Apply the agreed October Herrera B2B settings once, preserving subsequent admin edits';

    public function handle(HerreraQuickFixConfigurationService $configuration, HerreraBlogRefreshService $blog, HerreraHomeBlogCarouselService $carousel): int
    {
        $database = DB::connection()->getDatabaseName();
        $localPreview = app()->environment('local') && $database === 'herrera_new_migration';
        $staging = app()->environment('staging') && $database === 'herrera_redesign'
            && rtrim((string) config('app.url'), '/') === 'https://herrera.herrera.hr'
            && config('commerce.local_safe_mode');

        if (! $localPreview && ! $staging) {
            $this->error('This command is restricted to the isolated Herrera preview and staging databases.');

            return self::FAILURE;
        }

        $applied = $configuration->apply();
        $this->info($applied ? 'Herrera quick-fix settings applied.' : 'Herrera quick-fix settings already applied; administrator edits preserved.');
        $configuration->applyFilterDefaults();
        $this->info('Blog articles refreshed: '.$blog->apply());
        $carousel->apply();

        return self::SUCCESS;
    }
}
