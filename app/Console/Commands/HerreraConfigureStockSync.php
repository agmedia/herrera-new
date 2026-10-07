<?php

namespace App\Console\Commands;

use App\Services\Integrations\Stock\LegacyStockEnvironmentReader;
use App\Services\Integrations\Stock\StockSyncSettingsService;
use App\Support\Integrations\Stock\StockSyncRegistry;
use Illuminate\Console\Command;
use RuntimeException;

class HerreraConfigureStockSync extends Command
{
    protected $signature = 'herrera:configure-stock
        {--legacy-path= : Path to the original Herrera project; defaults to sibling herrera}
        {--legacy-env= : Private production env.php containing import.api credentials}
        {--brock-script= : Original standalone updateqty_brock.php file}
        {--connection-file= : Private JSON file keyed by supplier with url and credentials}
        {--replace : Replace existing connections with provided settings}';

    protected $description = 'Import legacy stock feed settings and provision protected EasyCron URLs without displaying credentials';

    public function handle(StockSyncSettingsService $settings, LegacyStockEnvironmentReader $reader): int
    {
        $path = rtrim((string) ($this->option('legacy-path') ?: dirname(base_path()).'/herrera'), '/');
        $connections = $this->legacyConnections($path);
        $brock = (string) ($this->option('brock-script') ?: $path.'/upload/updateqty_brock.php');
        if (is_file($brock) && preg_match('/\$xmlUrl\s*=\s*[\'\"]([^\'\"]+)[\'\"]/', file_get_contents($brock), $matches)) {
            $connections['brock'] = ['url' => $matches[1]];
        }
        $env = (string) ($this->option('legacy-env') ?: $path.'/upload/env.php');
        if (is_file($env)) {
            try {
                if ($api = $reader->read($env)) {
                    $connections['eracuni'] = $api;
                }
            } catch (\Throwable) {
                $this->error('Produkcijske API postavke nije moguće pročitati.');

                return self::FAILURE;
            }
        }
        if ($file = $this->option('connection-file')) {
            try {
                $provided = json_decode(file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
                if (! is_array($provided) || array_diff(array_keys($provided), array_keys(StockSyncRegistry::all()))) {
                    throw new RuntimeException;
                }
                $connections = array_replace($connections, $provided);
            } catch (\Throwable) {
                $this->error('Privatna datoteka postavki nije valjana. Očekuje se JSON objekt s oznakama integracija.');

                return self::FAILURE;
            }
        }
        foreach (StockSyncRegistry::all() as $supplier => $definition) {
            try {
                $settings->ensureToken($supplier);
                if (isset($connections[$supplier]) && ($this->option('replace') || ! $settings->configured($supplier))) {
                    $settings->saveConnection($supplier, $connections[$supplier]);
                }
                $this->line($definition['label'].': '.($settings->configured($supplier) ? 'postavke spremne' : 'nedostaje izvor ili pristupni podaci'));
            } catch (\Throwable) {
                $this->error($definition['label'].': postavke nisu spremljene.');

                return self::FAILURE;
            }
        }
        $this->info('Zaštićene cron URL-ove preuzmite u adminu: Integracije / Zalihe i cronovi.');
        $this->line('Ova naredba ne pokreće sinkronizaciju i ne mijenja rasporede u EasyCronu.');

        return self::SUCCESS;
    }

    private function legacyConnections(string $path): array
    {
        $connections = [];
        $parserPath = $path.'/storage/vendor/agmedia/api/src/Connection/Csv/';
        foreach (['braytron' => 'Braytron', 'master' => 'Master', 'videx' => 'Allegro', 'dpm' => 'Dpm'] as $supplier => $class) {
            $file = $parserPath.$class.'.php';
            if (is_file($file) && preg_match('/private\s+\$url\s*=\s*[\'\"]([^\'\"]+)[\'\"]/', file_get_contents($file), $matches)) {
                $connections[$supplier] = ['url' => $matches[1]];
            }
        }
        $controller = $path.'/upload/admin/controller/extension/module/agm_api.php';
        if (is_file($controller)) {
            $source = file_get_contents($controller);
            foreach (['vayox' => 'Vayox', 'enovalite' => 'Enovalite'] as $supplier => $action) {
                if (preg_match('/function\s+updateQuantity'.$action.'\s*\([^)]*\).*?file_get_contents\(\s*[\'\"]([^\'\"]+)[\'\"]/s', $source, $matches)) {
                    $connections[$supplier] = ['url' => $matches[1]];
                }
            }
        }

        return $connections;
    }
}
