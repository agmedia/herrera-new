<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Import\HerreraCustomerCategoryDiscountImportService;
use Illuminate\Console\Command;

class HerreraImportCustomerDiscounts extends Command
{
    protected $signature = 'herrera:import-customer-discounts {--apply : Prenesi pravila iz lokalno arhiviranih definicija} {--actor= : ID administratora za zapis promjene}';

    protected $description = 'Vraća izvorne popuste kupaca po kategorijama, bez stvaranja zasebnih cijena artikala';

    public function handle(HerreraCustomerCategoryDiscountImportService $service): int
    {
        $actor = $this->option('actor') ? User::query()->findOrFail((int) $this->option('actor')) : null;
        $summary = $this->option('apply') ? $service->import($actor) : $service->preview();
        $this->table(['Provjera', 'Broj'], collect($summary)->except('issues')->map(fn ($count, $key) => [$key, $count])->all());
        if ($summary['issues']) {
            $this->warn(count($summary['issues']).' zapisa nije preneseno; izvorne cijene tih zapisa ostaju sačuvane.');
        }
        $this->info($this->option('apply') ? 'Pravila po kategorijama su prenesena. Novi artikli automatski dobivaju važeći popust.' : 'Ovo je pregled. Za prijenos upotrijebite --apply.');

        return self::SUCCESS;
    }
}
