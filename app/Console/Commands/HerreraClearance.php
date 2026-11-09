<?php

namespace App\Console\Commands;

use App\Services\Catalog\Clearance\ClearanceService;
use Illuminate\Console\Command;
use RuntimeException;

class HerreraClearance extends Command
{
    protected $signature = 'herrera:clearance {--apply : Primijeni pregledane kandidate i deaktiviraj prodanu vlastitu zalihu}';

    protected $description = 'Pregled ili obrada rasprodaje 100 dana bez ukupnih ERP izlaza; bez potvrđenih podataka ne dodaje kandidate.';

    public function handle(ClearanceService $service): int
    {
        try {
            $result = $service->run((bool) $this->option('apply'));
            $this->info($result['disabled'] ? 'Automatika je isključena.' : ($result['applied'] ? 'Obrada je spremljena.' : 'Pregled bez spremanja.'));
            $this->table(['Novi kandidati', 'Aktivirano', 'Prodana vlastita zaliha', 'Bez svježeg dokaza'], [[$result['eligible'], $result['activated'], $result['retired'], $result['stale_or_unproven']]]);

            return self::SUCCESS;
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
