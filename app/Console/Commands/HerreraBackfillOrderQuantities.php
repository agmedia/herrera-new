<?php

namespace App\Console\Commands;

use App\Services\Import\LegacyOrderQuantityBackfill;
use Illuminate\Console\Command;

class HerreraBackfillOrderQuantities extends Command
{
    protected $signature = 'herrera:backfill-order-quantities {--apply : Spremi pregledane popravke} {--product=* : Ograniči na ID artikla}';

    protected $description = 'Pregleda minimalne količine/pakiranja iz sačuvanih podataka stare platforme; čuva ručne izmjene.';

    public function handle(LegacyOrderQuantityBackfill $service): int
    {
        $ids = $this->option('product');
        foreach ($ids as $id) {
            if (! ctype_digit((string) $id) || (int) $id < 1) {
                $this->error('ID artikla mora biti pozitivan cijeli broj.');

                return self::FAILURE;
            }
        }
        $result = $service->run((bool) $this->option('apply'), array_map('intval', $ids));
        $this->info($result['applied'] ? 'Popravak je spremljen.' : 'Pregled bez spremanja.');
        $this->table(['Za dopunu', 'Već ispravno', 'Sačuvane ručne izmjene', 'Bez izvora'], [[$result['changed'], $result['already_correct'], $result['manual_preserved'], $result['no_source']]]);

        return self::SUCCESS;
    }
}
