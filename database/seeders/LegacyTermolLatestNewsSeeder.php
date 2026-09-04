<?php

namespace Database\Seeders;

use App\Models\Content\Blog\BlogPost;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class LegacyTermolLatestNewsSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->articles() as $article) {
            $post = BlogPost::query()->updateOrCreate(
                ['code' => $article['code']],
                [
                    'is_active' => true,
                    'is_featured' => true,
                    'published_at' => $article['published_at'],
                    'sort_order' => 0,
                    'payload' => [
                        'legacy_source_url' => $article['source_url'],
                        'legacy_news_id' => $article['legacy_news_id'],
                    ],
                ]
            );

            $post->translations()->updateOrCreate(
                ['locale' => 'hr'],
                [
                    'title' => $article['title'],
                    'slug' => $article['slug'],
                    'excerpt' => $article['excerpt'],
                    'body_html' => $article['body_html'],
                    'meta_title' => $article['title'],
                    'meta_description' => $article['excerpt'],
                    'payload' => [
                        'legacy_source_url' => $article['source_url'],
                    ],
                ]
            );

            $this->importCover($post, $article['cover_url'], $article['cover_file_name']);
        }
    }

    private function importCover(BlogPost $post, string $url, string $fileName): void
    {
        if ($post->getFirstMedia('blog_cover') !== null) {
            return;
        }

        $temporaryPath = tempnam(sys_get_temp_dir(), 'termol-news-');

        if ($temporaryPath === false) {
            Log::warning('Could not create a temporary file for a legacy Termol news cover.', [
                'post_id' => $post->id,
                'url' => $url,
            ]);

            return;
        }

        try {
            $response = Http::withHeaders([
                'Accept' => 'image/avif,image/webp,image/apng,image/svg+xml,image/*,*/*;q=0.8',
                'Referer' => 'https://www.termol.hr/',
                'User-Agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 Chrome/126.0 Safari/537.36',
            ])
                ->timeout(30)
                ->retry(2, 300)
                ->get($url);

            $response->throw();

            if (! str_starts_with((string) $response->header('Content-Type'), 'image/')) {
                throw new \RuntimeException('Legacy news cover response is not an image.');
            }

            file_put_contents($temporaryPath, $response->body());

            $post
                ->addMedia($temporaryPath)
                ->usingName(pathinfo($fileName, PATHINFO_FILENAME))
                ->usingFileName($fileName)
                ->withCustomProperties([
                    'alt' => [
                        'hr' => $post->translations()->where('locale', 'hr')->value('title'),
                    ],
                    'legacy_source_url' => $url,
                ])
                ->toMediaCollection('blog_cover');
        } catch (\Throwable $exception) {
            Log::warning('Legacy Termol news cover import failed.', [
                'post_id' => $post->id,
                'url' => $url,
                'message' => $exception->getMessage(),
            ]);
        } finally {
            if (is_file($temporaryPath)) {
                @unlink($temporaryPath);
            }
        }
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function articles(): array
    {
        return [
            [
                'code' => 'legacy-besplatna-dostava-hladnjaka',
                'legacy_news_id' => 'b54eb9b4-a3cc-4391-87c8-67430362c392',
                'source_url' => 'https://www.termol.hr/Novost.aspx?news=b54eb9b4-a3cc-4391-87c8-67430362c392',
                'cover_url' => 'https://vivax.com/wp-content/uploads/2026/06/VX_hladnjak_Lending-page-prag.jpg',
                'cover_file_name' => 'besplatna-dostava-hladnjaka.jpg',
                'published_at' => '2026-06-10 09:00:00',
                'title' => 'Besplatna dostava hladnjaka',
                'slug' => 'besplatna-dostava-hladnjaka',
                'excerpt' => 'Kupite odabrani VIVAX hladnjak od 1. lipnja do 15. kolovoza 2026. i ostvarite pogodnost besplatne dostave.',
                'body_html' => <<<'HTML'
<h2>Kupite odabrani VIVAX hladnjak i ostvarite besplatnu dostavu</h2>
<p>Promocija traje od 1. lipnja do 15. kolovoza 2026. ili do isteka zaliha. Sada je pravo vrijeme za odabir novog hladnjaka.</p>
<h3>Zašto odabrati VIVAX hladnjak?</h3>
<ul>
    <li>Total No Frost tehnologija na odabranim modelima sprječava stvaranje leda i olakšava održavanje bez odmrzavanja.</li>
    <li>Energetski učinkoviti uređaji omogućuju optimiziranu potrošnju električne energije.</li>
    <li>Veliki kapacitet i praktičan raspored polica olakšavaju organizaciju namirnica.</li>
    <li>Moderan dizajn lako se uklapa u kuhinjski prostor.</li>
    <li>Multi Air Flow sustav pomaže produljiti svježinu namirnica.</li>
    <li>Digitalno upravljanje, LED osvjetljenje i tih rad pružaju veću udobnost u svakodnevnom korištenju.</li>
</ul>
<h3>Uređaji koji sudjeluju u promociji</h3>
<ul>
    <li>VIVAX HOME HLADNJAK CFS-516DFD X</li>
    <li>VIVAX HOME HLADNJAK CF-310ENF X</li>
    <li>VIVAX HOME HLADNJAK CF-3781DNF BG</li>
    <li>VIVAX HOME HLADNJAK CF-259ELF W</li>
    <li>VIVAX HOME HLADNJAK CF-344EDNF BX</li>
    <li>VIVAX HOME HLADNJAK CF-259ELF S</li>
    <li>VIVAX HOME HLADNJAK CF-290ENF BX</li>
    <li>VIVAX HOME UGRADBENI HLADNJAK CFRB-248ENF</li>
    <li>VIVAX HOME HLADNJAK CF-310EDNF X</li>
    <li>VIVAX HOME UGRADBENI HLADNJAK CFRB-271ELF</li>
</ul>
<h3>Kako ostvariti besplatnu dostavu?</h3>
<ol>
    <li><strong>Odaberite VIVAX hladnjak.</strong> Pronađite jedan od modela uključenih u promociju.</li>
    <li><strong>Kupite tijekom promotivnog razdoblja.</strong> Ponuda vrijedi za kupnje od 1. lipnja do 15. kolovoza 2026. ili do isteka zaliha.</li>
    <li><strong>Iskoristite pogodnost.</strong> Besplatna dostava ostvaruje se automatski za uključene proizvode kupljene kod partnera koji sudjeluje u promociji.</li>
</ol>
<p>Odaberite svoj novi hladnjak i iskoristite pogodnost besplatne dostave na vrijeme.</p>
HTML,
            ],
            [
                'code' => 'legacy-super-cijene-povrat-do-80-eura',
                'legacy_news_id' => 'ffe48857-56af-41b1-b4b1-529a3a76c9f3',
                'source_url' => 'https://www.termol.hr/Novost.aspx?news=ffe48857-56af-41b1-b4b1-529a3a76c9f3',
                'cover_url' => 'https://vivax.com/wp-content/uploads/2026/06/Untitled-design-27.png',
                'cover_file_name' => 'vivax-povrat-do-80-eura.png',
                'published_at' => '2026-05-20 09:00:00',
                'title' => 'Super cijene i povrat do 80€!',
                'slug' => 'super-cijene-i-povrat-do-80-eura',
                'excerpt' => 'Iskoristite sezonsku promociju VIVAX klima uređaja uz promotivne cijene i povrat novca do 80 €.',
                'body_html' => <<<'HTML'
<h2>Ohladi na VIVAX račun</h2>
<p>Od 20. svibnja do 31. srpnja 2026. iskoristite sezonsku promociju VIVAX klima uređaja i osigurajte hlađenje uz promotivne cijene i povrat novca do 80 €.</p>
<p>Promocija se odnosi na odabrane VIVAX modele R+, R PRO i H+ Design.</p>
<h3>Modeli i iznosi povrata</h3>
<ul>
    <li>ACP-24CH70AERI PRO R32 — 80 €</li>
    <li>ACP-24CH70AERI+ R32 — 80 €</li>
    <li>ACP-18CH50AEHI+ R32 — 80 €</li>
    <li>ACP-18CH50AEHI+ R32 SILVER — 80 €</li>
    <li>ACP-18CH50AEHI+ R32 GOLD — 80 €</li>
    <li>ACP-18CH50AEHI+ R32 GRAY MIRROR — 80 €</li>
    <li>ACP-18CH50AERI+ R32 — 80 €</li>
    <li>ACP-18CH50AERI+ R32 SILVER MIRROR — 80 €</li>
    <li>ACP-18CH50AERI PRO R32 — 80 €</li>
    <li>ACP-12CH35AEHI+ R32 — 60 €</li>
    <li>ACP-12CH35AEHI+ R32 SILVER — 60 €</li>
    <li>ACP-12CH35AEHI+ R32 GOLD — 60 €</li>
    <li>ACP-12CH35AEHI+ R32 GRAY MIRROR — 60 €</li>
    <li>ACP-12CH35AERI PRO R32 — 60 €</li>
    <li>ACP-12CH35AERI+ R32 — 60 €</li>
    <li>ACP-12CH35AERI+ R32 GOLD — 60 €</li>
    <li>ACP-12CH35AERI+ R32 SILVER — 60 €</li>
    <li>ACP-12CH35AERI+ R32 RED — 60 €</li>
    <li>ACP-12CH35AERI+ R32 SILVER MIRROR — 60 €</li>
    <li>ACP-09CH25AERI PRO R32 — 60 €</li>
    <li>ACP-09CH25AERI+ R32 — 60 €</li>
    <li>ACP-09CH25AERI+ R32 GOLD — 60 €</li>
    <li>ACP-09CH25AERI+ R32 SILVER — 60 €</li>
</ul>
<h3>Kako do povrata?</h3>
<ol>
    <li><strong>Kupite uređaj.</strong> Odaberite uključeni VIVAX klima uređaj R+, R PRO ili H+ Design tijekom promotivnog razdoblja.</li>
    <li><strong>Ispunite online obrazac.</strong> U roku od 30 dana od kupnje ispunite obrazac i priložite račun.</li>
    <li><strong>Primite isplatu.</strong> Povrat se isplaćuje na navedeni IBAN nakon zaprimanja valjanog zahtjeva, u skladu s pravilima promocije.</li>
</ol>
HTML,
            ],
            [
                'code' => 'legacy-kako-ukrotiti-troskove-energije-2026',
                'legacy_news_id' => '156ad4c1-9b3a-4cfe-8103-f22eeeff4e50',
                'source_url' => 'https://www.termol.hr/Novost.aspx?news=156ad4c1-9b3a-4cfe-8103-f22eeeff4e50',
                'cover_url' => 'https://www.termol.hr/Files/SWMBinaryLibrary/HR_VK_TERMOL_bnm869/156ad4c1-9b3a-4cfe-8103-f22eeeff4e50.jpg',
                'cover_file_name' => 'kako-ukrotiti-troskove-energije-2026.jpg',
                'published_at' => '2026-02-01 09:00:00',
                'title' => 'Kako ukrotiti visoke troškove energije u 2026.?',
                'slug' => 'kako-ukrotiti-visoke-troskove-energije-u-2026',
                'excerpt' => 'Pametan sustav grijanja, zonska regulacija i automatizacija mogu očuvati udobnost doma uz bolju kontrolu potrošnje.',
                'body_html' => <<<'HTML'
<p>Iako su se veliki potresi na energetskom tržištu donekle stabilizirali, visoka osnovna cijena energenata postala je nova svakodnevica. Ključno je pitanje kako zadržati toplinsku udobnost bez prevelikog opterećenja kućnog proračuna.</p>
<p>Odgovor nije samo u odricanju, nego u inteligentnoj optimizaciji.</p>
<h2>Energetski učinkovit sustav kao temelj financijske otpornosti</h2>
<p>Sustav grijanja jedna je od najvažnijih i najskupljih investicija u domu. Zato ga više ne biramo samo prema početnoj cijeni uređaja, nego i prema njegovoj otpornosti na promjene cijena energije i buduće ekološke zahtjeve.</p>
<h2>Mit o najjeftinijem gorivu</h2>
<p>Najjeftinija energija je ona koju nismo potrošili. Kod klasičnih goriva treba uračunati vrijeme, održavanje i skladištenje, dok isplativost električne energije sve više ovisi o vlastitoj proizvodnji i pametnom upravljanju potrošnjom.</p>
<h2>Najbrži put do uštede: pametno upravljanje</h2>
<p>Promjena cijelog sustava grijanja može biti skupa i zahtjevna, ali digitalna automatizacija i zonska regulacija mogu donijeti mjerljive rezultate bez obzira na energent koji koristite.</p>
<p>Moderni sustavi omogućuju da svaka prostorija ima svoj temperaturni režim, uz nadzor senzora i upravljanje mobilnom aplikacijom.</p>
<h3>Kako regulacija grijanja smanjuje račune?</h3>
<ul>
    <li><strong>Nema pregrijavanja.</strong> Ako sunce zagrije dnevni boravak, termostat može odmah smanjiti ili isključiti grijanje u toj zoni.</li>
    <li><strong>Nema nepotrebnog grijanja.</strong> Sustav može održavati nižu temperaturu dok nikoga nema kod kuće i podići je prije povratka ukućana.</li>
    <li><strong>Svaka prostorija dobiva svoj režim.</strong> Hodnik, kupaonica i spavaća soba ne moraju biti zagrijani jednako.</li>
</ul>
<h2>Zaključak: štedite glavom, a ne smrzavanjem</h2>
<p>Najbolje rezultate donosi pametan nadzor nad sustavom. Kvalitetni termostati i upravljačke jedinice mogu se isplatiti kroz nižu potrošnju, a pritom zadržavaju željenu udobnost doma.</p>
HTML,
            ],
        ];
    }
}
