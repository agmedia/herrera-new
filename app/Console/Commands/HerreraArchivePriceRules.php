<?php

namespace App\Console\Commands;

use App\Services\Import\HerreraLegacyPriceRuleArchiveService;
use Illuminate\Console\Command;
use RuntimeException;

class HerreraArchivePriceRules extends Command
{
    protected $signature = 'herrera:archive-price-rules {--source-connection=herrera_source} {--prefix=oc_} {--catalog= : Izvorni verificirani cjenik; zadano najstariji cjenik ovog snapshota}';

    protected $description = 'Arhivira izvorne definicije B2B popusta za pregled, bez promjene ili objave cijena';

    public function handle(HerreraLegacyPriceRuleArchiveService $archive): int
    {
        try {
            $catalogId = $this->option('catalog');
            if ($catalogId !== null && (! ctype_digit((string) $catalogId) || (int) $catalogId < 1)) {
                throw new RuntimeException('ID cjenika nije valjan.');
            }
            $summary = $archive->archive((string) $this->option('source-connection'), (string) $this->option('prefix'), $catalogId === null ? null : (int) $catalogId);
            $this->table(['Provjera', 'Broj'], collect($summary)->map(fn ($count, $key) => [$key, $count])->all());
            $this->info('Izvorne definicije dostupne su samo za čitanje. Nije promijenjena nijedna cijena niti objavljen novi cjenik.');

            return self::SUCCESS;
        } catch (\Throwable $exception) {
            $this->error(get_class($exception) === RuntimeException::class ? $exception->getMessage() : 'Arhiviranje je sigurno zaustavljeno ('.get_class($exception).').');

            return self::FAILURE;
        }
    }
}
