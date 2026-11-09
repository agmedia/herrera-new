<?php

namespace App\Console\Commands;

use App\Models\Catalog\Category\Category;
use App\Services\Catalog\Clearance\ClearanceService;
use App\Services\Catalog\Clearance\CompanySalesClearanceSync;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class HerreraSyncClearanceSales extends Command
{
    protected $signature = 'herrera:sync-clearance-sales
        {--apply : Spremi potpuni API pregled prodaje i potvrđene kandidate}
        {--run-clearance : Nakon uspješne provjere primijeni uključeno pravilo rasprodaje}
        {--enable : Nakon uspješne provjere uključi pravilo s kategorijom Rasprodaja}';

    protected $description = 'Provjeri 100 dana ukupne prodaje tvrtke iz e-Računa, uključujući POS račune i otpremnice.';

    public function handle(CompanySalesClearanceSync $sync): int
    {
        try {
            if ($this->option('enable') && ! $this->option('apply')) {
                throw new RuntimeException('Uključivanje zahtijeva --apply i uspješan svježi API pregled.');
            }
            $result = $sync->run((bool) $this->option('apply'), (bool) $this->option('run-clearance') && ! $this->option('enable'));
            if ($this->option('enable')) {
                DB::transaction(function (): void {
                    $category = Category::query()->where('scope', 'catalog')->where(fn ($query) => $query->whereIn('code', ['rasprodaja', 'clearance'])
                        ->orWhereHas('translations', fn ($query) => $query->where('locale', 'hr')->where('name', 'Rasprodaja')))->first();
                    if (! $category) {
                        $category = Category::query()->create(['scope' => 'catalog', 'code' => 'rasprodaja', 'is_active' => true, 'show_in_menu' => true,
                            'payload' => ['source' => 'automatic_clearance']]);
                        $category->translations()->create(['locale' => 'hr', 'name' => 'Rasprodaja', 'slug' => 'rasprodaja']);
                    }
                    app(ClearanceService::class)->saveSettings(['enabled' => true, 'category_id' => $category->id]);
                });
                if ($this->option('run-clearance')) {
                    $result['clearance'] = app(ClearanceService::class)->run(true, $result['fingerprint']);
                }
                $this->info('Automatska rasprodaja je uključena.');
            }
            $this->info($result['applied'] ? 'Potpuna provjera ERP prodaje je spremljena.' : 'Pregled bez izmjene proizvoda ili dokaza rasprodaje.');
            $this->table(['Dokumenti', 'API pozivi', 'Bez prodaje 100 dana', 'Nedavna prodaja', 'Nepotvrđena veza/zaliha'], [[
                $result['document_count'], $result['request_count'], $result['observed'], $result['recent'], $result['unproven'],
            ]]);
            if (isset($result['clearance'])) {
                $this->line($result['clearance']['disabled'] ? 'Automatska rasprodaja je isključena.' : 'Aktivirano: '.$result['clearance']['activated'].'; prodana vlastita zaliha: '.$result['clearance']['retired'].'.');
            }

            return self::SUCCESS;
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
