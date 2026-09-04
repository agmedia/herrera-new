<?php

namespace Database\Seeders;

use App\Services\Import\TermolInfoPageImportService;
use Illuminate\Database\Seeder;

class TermolInfoPageSeeder extends Seeder
{
    public function run(TermolInfoPageImportService $importer): void
    {
        $stats = $importer->import();

        $this->command?->info(sprintf(
            'Imported %d Termol info pages and configured %d footer columns.',
            $stats['pages_imported'],
            $stats['footer_columns_configured']
        ));
    }
}
