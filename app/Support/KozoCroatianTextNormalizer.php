<?php

namespace App\Support;

class KozoCroatianTextNormalizer
{
    /**
     * @var array<string, string>
     */
    private array $replacements = [
        '?ešljani' => 'Češljani',
        'Jam?imo' => 'Jamčimo',
        'Dje?ja' => 'Dječja',
        'Dje?je' => 'Dječje',
        'Dje?ji' => 'Dječji',
        'Zahvaljuju?i' => 'Zahvaljujući',
        'Kombiniraju?i' => 'Kombinirajući',
        'Kop?anje' => 'Kopčanje',
        'Zatvara?' => 'Zatvarač',
        'Pamu?ni' => 'Pamučni',
        'Ru?no' => 'Ručno',
        'Ru?nik' => 'Ručnik',
        'Boži?na' => 'Božićna',
        'Boži?ne' => 'Božićne',
        'Boži?ni' => 'Božićni',
        'Boži?nim' => 'Božićnim',
        'Minimalisti?ki' => 'Minimalistički',
        'Klasi?an' => 'Klasičan',
        'Prozra?na' => 'Prozračna',
        'Veli?ina' => 'Veličina',
        'Vre?a' => 'Vreća',
        'Hla?e' => 'Hlače',
        'Spava?ica' => 'Spavaćica',
        '?aroliji' => 'čaroliji',
        'izra?enih' => 'izrađenih',
        'izra?ena' => 'izrađena',
        'izra?ene' => 'izrađene',
        'izra?en' => 'izrađen',
        'obra?ena' => 'obrađena',
        'obra?en' => 'obrađen',
        'gla?a' => 'glađa',
        'prilago?ava' => 'prilagođava',
        'prilago?avaju' => 'prilagođavaju',
        'izme?u' => 'između',
        'pre?e' => 'pređe',
        'le?ima' => 'leđima',
        'najsla?e' => 'najslađe',
        'jednostavnoš?u' => 'jednostavnošću',
        'nježnoš?u' => 'nježnošću',
        've?eri' => 'večeri',
        'boži?im' => 'božićnim',
        'boži?ni' => 'božićni',
        '?vrš?a' => 'čvršća',
        '?vrsto?u' => 'čvrstoću',
        '?vrsta' => 'čvrsta',
        '?ine' => 'čine',
        '?ak' => 'čak',
        '?e' => 'će',
        '?ete' => 'ćete',
        '?ini' => 'čini',
        '?ipkom' => 'čipkom',
        've?' => 'već',
        'uklju?uju?i' => 'uključujući',
        'razli?ite' => 'različite',
        'toksi?ne' => 'toksične',
        'jam?i' => 'jamči',
        'eti?no' => 'etično',
        'plasti?ne' => 'plastične',
        'pamu?nima' => 'pamučnima',
        'o?i' => 'oči',
        'meko?i' => 'mekoći',
        'meko?a' => 'mekoća',
        'meko?e' => 'mekoće',
        'prozra?nosti' => 'prozračnosti',
        'prozra?nost' => 'prozračnost',
        'prozra?na' => 'prozračna',
        'prozra?no' => 'prozračno',
        'prozra?nog' => 'prozračnog',
        'prozra?nima' => 'prozračnima',
        'prozra?an' => 'prozračan',
        'osje?aj' => 'osjećaj',
        'osje?a' => 'osjeća',
        'osje?ati' => 'osjećati',
        'elasti?ne' => 'elastične',
        'elasti?nom' => 'elastičnom',
        'elasti?na' => 'elastična',
        'elasti?nosti' => 'elastičnosti',
        'elasti?nost' => 'elastičnost',
        'omogu?ava' => 'omogućava',
        'omogu?avaju' => 'omogućavaju',
        'problemati?nom' => 'problematičnom',
        'zahvaljuju?i' => 'zahvaljujući',
        'koriste?i' => 'koristeći',
        'kop?anje' => 'kopčanje',
        'kop?anjem' => 'kopčanjem',
        'lako?om' => 'lakoćom',
        'lako?u' => 'lakoću',
        'lako?e' => 'lakoće',
        'fino?om' => 'finoćom',
        'duga?kim' => 'dugačkim',
        'ja?e' => 'jače',
        'upijaju?a' => 'upijajuća',
        'klasi?nog' => 'klasičnog',
        'klasi?nom' => 'klasičnom',
        'klasi?nim' => 'klasičnim',
        'klasi?nih' => 'klasičnih',
        'obi?nog' => 'običnog',
        'odje?u' => 'odjeću',
        'odje?e' => 'odjeće',
        'mogu?e' => 'moguće',
        'vra?a' => 'vraća',
        'sva?ijim' => 'svačijim',
        'kapulja?om' => 'kapuljačom',
        'zatvara?em' => 'zatvaračem',
        'uop?e' => 'uopće',
        'slin?ek' => 'slinček',
        'dje?ju' => 'dječju',
        'dje?ja' => 'dječja',
        'dje?ji' => 'dječji',
        'dje?ake' => 'dječake',
        'djevoj?ice' => 'djevojčice',
        'novoro?en?e' => 'novorođenče',
        'veli?ine' => 'veličine',
        'veli?ina' => 'veličina',
        'obla?enja' => 'oblačenja',
        'romanti?ne' => 'romantične',
        'romanti?nim' => 'romantičnim',
        'spava?ica' => 'spavaćica',
        'to?kice' => 'točkice',
        'to?kica' => 'točkica',
        'to?kastog' => 'točkastog',
        'ga?e' => 'gaće',
        'ga?a' => 'gaća',
        'ga?ice' => 'gaćice',
        'ga?ica' => 'gaćica',
        'hla?e' => 'hlače',
        'ru?nik' => 'ručnik',
    ];

    public function normalize(?string $value): string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return '';
        }

        $normalized = str_replace(["\r\n", "\r", "\u{00A0}"], ["\n", "\n", ' '], $value);
        $normalized = strtr($normalized, $this->replacements);
        $normalized = preg_replace('/[ \t]+/u', ' ', $normalized) ?? $normalized;
        $normalized = preg_replace("/ *\n */u", "\n", $normalized) ?? $normalized;
        $normalized = preg_replace("/\n{3,}/u", "\n\n", $normalized) ?? $normalized;

        return trim($normalized);
    }

    /**
     * @return array<int, string>
     */
    public function unresolvedTokens(?string $value): array
    {
        $value = trim((string) $value);
        if ($value === '' || ! str_contains($value, '?')) {
            return [];
        }

        preg_match_all('/[\pL?]+/u', $value, $matches);

        return collect($matches[0] ?? [])
            ->filter(fn (string $token): bool => str_contains($token, '?'))
            ->unique()
            ->values()
            ->all();
    }
}
