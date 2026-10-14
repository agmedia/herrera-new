<?php

namespace App\Console\Commands;

use App\Models\Content\Reference\ReferenceProject;
use App\Support\ReferenceDemoTarget;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class HerreraDemoReference extends Command
{
    protected $signature = 'herrera:demo-reference {--staging : Explicitly create the demo on the isolated Herrera staging site}';

    protected $description = 'Create one fictional reference on the local preview or explicitly selected isolated Herrera staging site';

    public function handle(): int
    {
        $staging = (bool) $this->option('staging');
        if ((! $staging && ! app()->environment('local')) || ($staging && ! app()->environment('staging'))) {
            $this->error($staging
                ? 'The --staging option requires the isolated staging environment. Nothing has been changed.'
                : 'Demo references are restricted to the isolated local Herrera preview unless --staging is explicitly selected.');

            return self::FAILURE;
        }
        $connection = DB::connection();
        $configured = (array) config('database.connections.'.config('database.default'), []);
        if (! ReferenceDemoTarget::allows([
            'explicit_staging' => $staging,
            'environment' => app()->environment(),
            'driver' => $connection->getDriverName(),
            'database' => $connection->getDatabaseName(),
            'host' => $connection->getConfig('host'),
            'app_url' => config('app.url'),
            'safe_mode' => config('commerce.local_safe_mode'),
            'split_connections' => $connection->getConfig('read') || $connection->getConfig('write')
                || ! empty($configured['read']) || ! empty($configured['write']),
        ])) {
            $this->error($staging
                ? 'Staging demo requires herrera_redesign, https://herrera.herrera.hr and enabled commerce safe mode. Nothing has been changed.'
                : 'Demo references require the local loopback herrera_new_migration MySQL database.');

            return self::FAILURE;
        }

        $markerPath = storage_path('app/private/herrera/demo-reference-project.json');
        if (is_file($markerPath)) {
            $marker = json_decode((string) file_get_contents($markerPath), true);
            if (! is_array($marker)
                || ! in_array($marker['purpose'] ?? '', $staging ? ['herrera-preview-demo-reference-v1'] : ['local-demo-reference-v1', 'herrera-preview-demo-reference-v1'], true)
                || (($marker['purpose'] ?? '') === 'herrera-preview-demo-reference-v1' && ($marker['database'] ?? '') !== $connection->getDatabaseName())
                || ! is_int($marker['project_id'] ?? null) || $marker['project_id'] < 1) {
                $this->error('The existing local demo marker is invalid. Nothing has been changed.');

                return self::FAILURE;
            }
            $project = ReferenceProject::query()->find($marker['project_id']);
            if (! $project) {
                $this->warn('The demo project was deleted. It will not be recreated automatically.');

                return self::SUCCESS;
            }
            $this->info('Existing demo preserved, including all edits and its current publication state.');
            $this->reportProject($project);

            return self::SUCCESS;
        }
        if (ReferenceProject::query()->whereHas('translations', fn ($query) => $query->where('locale', 'hr')->where('slug', 'demo-ogledni-projekt-rasvjete'))->exists()) {
            $this->warn('The demo address already exists. Existing content has been preserved.');

            return self::SUCCESS;
        }

        $assets = [
            'odabir-led-rasvjete-v1.jpg' => 'DEMO — Ilustrativni prikaz slojevite rasvjete; nije fotografija izvedenog projekta.',
            'lina-v1.jpg' => 'DEMO — Ilustrativni prikaz blagovaonice s linearnom rasvjetom; nije stvarna izvedba.',
            'bella-v1.jpg' => 'DEMO — Ilustrativni prikaz dnevnog prostora sa stropnom rasvjetom; nije stvarna izvedba.',
        ];
        $assetRoot = realpath(public_path('assets/blog/herrera'));
        foreach ($assets as $filename => $caption) {
            $path = realpath(public_path('assets/blog/herrera/'.$filename));
            if (! $assetRoot || ! $path || ! str_starts_with($path, $assetRoot.'/') || ! is_file($path) || filesize($path) === 0) {
                $this->error('A trusted local illustrative photograph is missing. Nothing has been changed.');

                return self::FAILURE;
            }
        }
        File::ensureDirectoryExists(dirname($markerPath), 0700);
        $photos = [];
        try {
            $project = DB::transaction(function () use ($assets, &$photos): ReferenceProject {
                $project = ReferenceProject::query()->create([
                    'location' => 'Ogledni Grad — izmišljena lokacija',
                    'completed_year' => 2026,
                    'project_type' => 'Stambeni interijer — DEMO',
                    'is_active' => true,
                    'published_at' => now(),
                    'sort_order' => 0,
                ]);
                $project->translations()->create([
                    'locale' => 'hr',
                    'title' => 'DEMO — Ogledni projekt rasvjete',
                    'slug' => 'demo-ogledni-projekt-rasvjete',
                    'excerpt' => 'Ogledni projekt s izmišljenim podacima i ilustrativnim fotografijama za pregled izgleda stranice. Ne prikazuje stvarnu izvedbu Herrera tima.',
                    'description' => "DEMO / OGLEDNI PROJEKT\n\nOvaj izmišljeni primjer služi isključivo za pregled rasporeda fotografija, podataka i opisa u sekciji referenci. Nije stvarni izvedeni projekt niti tvrdnja o radovima koje je izvela Herrera.\n\nPrikazujemo tri ilustrativna interijera: dnevni prostor sa slojevitom rasvjetom, blagovaonicu s linearnom rasvjetom i prostor sa stropnom svjetiljkom. Fotografije su ilustracije iz lokalnog bloga, a ne dokumentacija jednog stvarnog objekta.\n\nNa pravim projektima ovdje će biti opis prostora, zahtjevi investitora i izvedeno rješenje, uz odobrene autentične fotografije.",
                ]);
                $project->translations()->create([
                    'locale' => 'en',
                    'title' => 'DEMO — Sample lighting project',
                    'slug' => 'demo-sample-lighting-project',
                    'excerpt' => 'A fictional sample with illustrative images to preview this page. It does not represent an actual project completed by Herrera.',
                    'description' => "DEMO / SAMPLE PROJECT\n\nThis fictional example previews the layout of photographs, project details and descriptions. It is not an actual completed project or a claim about work carried out by Herrera.\n\nThe gallery contains three illustrative interiors from our local blog. They are not photographs documenting a single real property. Real project pages will use approved, authentic project photographs.",
                ]);
                foreach ($assets as $filename => $caption) {
                    $photos[] = $project->addMedia(public_path('assets/blog/herrera/'.$filename))->preservingOriginal()
                        ->usingName('DEMO — Ilustrativni prikaz rasvjete')
                        ->usingFileName(Str::uuid().'.jpg')
                        ->withCustomProperties(['caption' => $caption, 'local_demo' => true])
                        ->toMediaCollection(ReferenceProject::GALLERY);
                }

                return $project;
            });
        } catch (\Throwable $exception) {
            foreach ($photos as $photo) {
                $photo->delete();
            }
            throw $exception;
        }
        File::put($markerPath, json_encode([
            'purpose' => 'herrera-preview-demo-reference-v1', 'database' => $connection->getDatabaseName(), 'project_id' => (int) $project->id,
            'created_at' => now()->toIso8601String(),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        chmod($markerPath, 0600);
        $this->info('Created one clearly labelled fictional demo reference. Original blog photographs were preserved.');
        $this->reportProject($project);

        return self::SUCCESS;
    }

    private function reportProject(ReferenceProject $project): void
    {
        $this->line('Project ID: '.$project->id.'; photographs: '.$project->getMedia(ReferenceProject::GALLERY)->count());
        $translation = $project->translations()->where('locale', 'hr')->first();
        if ($translation) {
            $this->line(route('references.show', $translation->slug));
        }
    }
}
