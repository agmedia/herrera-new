# Osvježavanje testne trgovine i prijelaz na novu Herrera trgovinu

## Okruženja i granice

| Namjena | Stranica | Baza |
| --- | --- | --- |
| Aktualna trgovina | `https://herrera.hr` — OpenCart | `herrera_shop` |
| Nova testna trgovina | `https://herrera.herrera.hr` — Laravel | `herrera_redesign` |
| Lokalni pregled | `http://herrera-new.test` | `herrera_new_migration` |
| Lokalna proba | Nije javno objavljena | Nove `herrera_rehearsal_target_*` i `herrera_rehearsal_source_*` baze |

OpenCart i Laravel imaju različite sheme. Kopiranje `herrera_shop` preko
`herrera_redesign` nije postupak migracije. Podaci se prenose preko provjerenih
mapiranja izvornih ID-eva, u zasebnu kopiju nove baze. Izvorna i postojeća ciljna
baza ostaju sačuvane.

Ove pripreme i automatske probe rade **samo lokalno**. Naredbe za završno
usklađivanje odbijaju produkcijsko/staging okruženje i udaljene veze. Lokalna
proba nije dopuštenje za prebacivanje javne domene ili promjenu žive baze.

## Koji podaci imaju prednost

Aktualni OpenCart je mjerodavan za artikle i njihovu dostupnost, kupce i njihove
izvorne grupe, povijest narudžbi, apsolutne cijene po grupama/kupcima, količinske
cijene i akcije s datumima i prioritetima. Cijene se uspoređuju na četiri decimale;
izvorne apsolutne cijene ne dobivaju još jedan rabat.

Nova trgovina zadržava ručno dodane fotografije i dokumente, vanjske PDF
poveznice, nove opcije artikala, CMS/dizajn, djelatnosti/divizije, CRM povijest i
provjerene ručne dodjele. Ako su isto polje ili ista veza mijenjani na obje
strane, postupak bilježi konflikt umjesto tihog prepisivanja.

Objavljeni cjenik ostaje nepromijenjen. Svježi izvor dobiva novi neaktivni cjenik.
Provjera prvo mora dokazati podudaranje izvornog cjenika. Poseban plan za nove
ručne cijene i pravila rabata zatim otkriva svaki mogući utjecaj na izvorne cijene.
Preklapanja zahtijevaju usklađenje; ne aktiviraju se automatski. Dopuštene
neovisne dopune mogu dobiti zasebnu neaktivnu radnu kopiju, s tragom prijenosa.

Automatska financijska provjera pokriva osnovne cijene, cijene po grupi, cijene
po kupcu, količinske cijene i akcije. Dodatne stare tablice grupnih cijena opcija
ili drugih nepodržanih cijena ne smiju se prešutjeti ako se pojave u svježoj
kopiji: tada je aktivacija blokirana do dopune prijenosa i provjere. Stari
podaci za referentne cijene arhiviraju se, a statusi modula `anchor_price` i
`digital_pricelist` provjeravaju zasebno; uključeni modul zahtijeva i dokaz
ispravnog prikaza referentnih cijena.

## 1. Priprema i proba

1. Zabilježiti vrijeme izvorne kopije i napraviti privatne sigurnosne kopije obje
   baze, konfiguracije i potrebnih originalnih datoteka. Lozinke, SQL dumpovi i
   podaci kupaca ostaju izvan Git repozitorija i javnog web direktorija. Lokalni
   Laravel ulaz mora biti obnovljen iz aktualne testne baze, uključujući sve
   najnovije ručne dorade; stara lokalna kopija nije zamjena za novije podatke.
2. Vratiti OpenCart kopiju u novu lokalnu `herrera_live_snapshot_*` bazu. Za
   početno uspostavljanje vlasništva sačuvati i izvornu kopiju korištenu pri
   prvoj migraciji. Datum u imenu ne smije prikriti stvarnu starost podataka.
3. Zamrznute lokalne kopije ostaviti bez drugih upisa tijekom kopiranja. Za
   InnoDB koristiti konzistentan izvoz; za MyISAM posebno osigurati
   konzistentnost. Završni izvoz radi se dok su stari webshop i njegovi
   automatski upisi zaustavljeni. Postojeća kopija ima 231 InnoDB tablicu i
   MyISAM tablicu `oc_order_manager_sales`; sam `--single-transaction` izvoz
   zato ne jamči konzistentnost cijele baze. Izvoz MyISAM povijesti mora biti
   zaštićen od upisa, a početni izvoz bez prekida rada planiran zasebno.
4. Pokrenuti provjeru, zatim probu u novoimenovanim lokalnim bazama:

```bash
php artisan herrera:rehearse-cutover --source-date=2026-10-06
php artisan herrera:rehearse-cutover --source-date=2026-10-06 \
  --admin-connection=herrera_rehearsal_admin --apply
```

Datum iz primjera označava postojeću probnu kopiju od 6. listopada 2026.;
**nije svježi izvoz produkcije**. Za stvarno osvježavanje upisati datum stvarnog
izvoza. Opcionalna `herrera_rehearsal_admin` veza koristi samo lokalnog korisnika
s pravom stvaranja novih baza. Privatne vrijednosti
`HERRERA_REHEARSAL_DB_USERNAME` / `HERRERA_REHEARSAL_DB_PASSWORD` postaviti u
lokalnom okruženju. Naredba provjerava localhost i istu instancu baze; ne dodjeljuje
nova prava aplikacijskom korisniku.

Za odvojeno kopiranje i ponovljivo nastavljanje:

```bash
php artisan herrera:rehearse-cutover --source-date=2026-10-06 \
  --admin-connection=herrera_rehearsal_admin --apply --copy-only
php artisan herrera:rehearse-cutover --source-date=2026-10-06 \
  --admin-connection=herrera_rehearsal_admin --apply \
  --resume=herrera/cutover-rehearsal-YYYYMMDD-HHMMSS.json
```

Za stvarni prijenos novije kopije postaviti `herrera_source` na svježe obnovljenu
lokalnu bazu (`HERRERA_SOURCE_DB_DATABASE`), a `herrera_baseline` ostaviti na
originalnoj kopiji iz prvog uvoza (`HERRERA_BASELINE_DB_DATABASE`). Tada koristiti
oba stvarna datuma, primjerice tek nakon pribavljanja kopije od 14. listopada:

```bash
php artisan herrera:rehearse-cutover --source-date=2026-10-14 \
  --baseline-connection=herrera_baseline --baseline-date=2026-10-06 \
  --admin-connection=herrera_rehearsal_admin --apply
```

Obje veze moraju pokazivati na zamrznute lokalne kopije na istoj instanci.
Ponovno korištenje kopije od 6. listopada kao ulaza i izvora provjerava sigurno
ponavljanje, ali samo po sebi ne dokazuje prijenos svježih produkcijskih razlika.

Naredba nikada ne zamjenjuje već postojeću rehearsal bazu. Izvještaji su privatni
agregati u `storage/app/private/herrera`, bez lozinki i sadržaja narudžbi. Nove
baze ostaju za pregled nakon uspjeha ili pogreške. Njihovo uklanjanje zaseban je
namjeran korak nakon pregleda; izvorne baze nisu predmet čišćenja.

Ako je puni prijenos već završen, ali se kasnija provjera zaustavila, moguće je
nastaviti iz njegova izvještaja dodavanjem `--continue-after-refresh` uz
`--apply --resume=...`. Prihvaća se samo dovršen završni uvoz s pripadajućim
neaktivnim izvornim cjenikom. Sve provjere identiteta, iznosa, cijena, očuvanih
dorada i ponovljenog uvoza ipak se izvršavaju; nastavak ne odobrava aktivaciju.

Uvoz u probnu bazu koristi `herrera:import --final-sync`; redovni uvoz zadržava
dosadašnje ponašanje. Izvorni snapshot uspostavlja trostranu usporedbu
staro-izvorno / novo-izvorno / novo-ručno. Za osoblje postoji zaseban
`herrera:import-staff --final-sync --apply`. Novi način čuva uklonjene i
zamijenjene dodjele i ne vraća povučene vjerodajnice. Promjene lozinki iz
OpenCarta prenose se samo uz dokazanu, još aktivnu legacy vjerodajnicu;
neprovjerljivi slučajevi idu na usklađenje.
Razlika prema staroj početnoj kopiji sama se ne proglašava riješenom. Za
operativne podatke artikla može je zatvoriti samo nova provjera u kojoj izvor,
mapiranje, nova zapamćena početna slika i cilj dokazuju potpuno jednaku svježu
vrijednost. Ostali konflikti ostaju za pregled; tragovi starih problema se ne brišu.

Povijest `oc_order_manager_sales` prenosi zaseban
`herrera:import-historical-crm --apply` na izoliranoj probnoj bazi. Svaki izvorni
red arhivira se privatno, uključujući izvorne napomene i vremena. Sesije se
prikazuju samo uz dokazano mapiranje kupca; komercijalist i narudžba povezuju se
isključivo uz provjerene identitete. Nedostajuće stare veze ostaju vidljive u
agregatima za pregled, a nepotvrđen ishod ne prikazuje se kao uspješna narudžba
ili ponuda. Nove ručno unesene CRM sesije ostaju sačuvane.
Stari OpenCart pri odjavi može prepisati početak sesije, pa zabilježeno trajanje
nije pouzdano trajanje posjeta. Nevaljani početak ostaje samo u arhivi;
nevaljani ili obrnuti završetak čuva se izvorno uz nepotvrđen ishod.

## 2. Obavezne provjere prije osvježavanja testne baze

- Sve aktualne izvorne stavke imaju očekivano mapiranje; nema izgubljenih kupaca,
  dupliranih identiteta, narudžbi ili stavki. Karantena identiteta je obavezna
  stavka pregleda, a ne dokaz da je kupac prenesen.
- Iznosi narudžbi, stavki, poreza i zbrojeva te sve valjane izvorne cjenovne
  stavke odgovaraju izvoru na četiri decimale.
- Provjereni su stvarni odobreni kupci iz svih grupa, individualne cijene,
  količinski pragovi, datum početka i kraja akcija, prioriteti te buduće i
  istekle akcije. Provjera cjenovnih zapisa je potpuna; kombinacije ponašanja
  provjeravaju se ciljanim uzorcima i graničnim slučajevima.
  `herrera:audit-pricing --cover-groups` provjerava svaku grupu s dostupnim
  odobrenim računom te primjenjive grupne, akcijske i količinske scenarije.
  Provjerava trenutke prije, na i poslije početka/kraja akcije te količine
  ispod, na i iznad praga. Ukupno ograničenje je 4000 efektivnih scenarija;
  skraćena pokrivenost ruši provjeru. Grupe bez prikladnog računa izlistavaju
  se posebno i ne smiju ostati prešućene.
- Native fotografije/dokumenti/opcije, djelatnosti/divizije, CRM i dogovorene
  dodjele ostaju sačuvani. Ne koristiti bulk media importer s
  `clearExisting=true`; datoteke kopirati uz očuvanje postojećih novih medija.
  Dobavljački PDF ostaje vanjska poveznica.
- Pregledani su svi konflikti iz uvoza i plana native cijena. Ne prolaziti gate
  samo zato što je naredba završila bez tehničke pogreške.
- Posebno označiti testne račune/narudžbe i razlikovati ih od stvarne povijesti.
  Ništa se ne briše niti prenosi na živu trgovinu samo na temelju pretpostavke.
- Provjeriti prijave postojećih kupaca, ovlasti osoblja, naručivanje, varijante,
  cijene i poreze, kontaktne podatke, SEO preusmjerenja i dokumente.
- ERP/e-mail/payment integracije i automatski poslovi na testu ostaju izolirani.
  Proba ne smije slati stvarne obavijesti ili duplicirati narudžbe u ERP-u.
- Zabilježiti i usporediti izvorni PHP i DB timezone te ciljni `APP_TIMEZONE`
  i DB timezone. Stara aplikacija postavlja i DB session timezone prema
  tadašnjem pomaku. Provjeriti zimske/ljetne datume akcija i CRM zapisa;
  preuzeti CRM zapisi zadržavaju izvorna vremena bez nagađanja o pomaku.

Prije zamjene testne baze privremeno zaustaviti upise u novu testnu trgovinu
(uključujući admin, CRM, datoteke i automatske poslove). Potvrditi da od njezine
ulazne kopije nema novih upisa; ako ih ima, sačuvati ih i ponoviti prijenos iz
aktualne ciljne kopije ili ih zasebno dokazano uskladiti. Tek zatim u dogovorenom
terminu zamijeniti konfiguraciju provjerenom obnovljenom bazom, uz privatnu
sigurnosnu kopiju. Osvježavanje testne stranice ne mijenja OpenCart, njegovu
domenu ili cronove.

## 3. Konačni prijelaz na `herrera.hr`

1. Puni probni uvoz i najveći dio provjera napraviti unaprijed, uz mjerenje vremena.
   Potvrditi kapacitet diska za staru bazu, novu bazu i privatne sigurnosne kopije.
2. Potvrditi DocumentRoot, PHP 8.4, HTTPS, `.env`, aplikacijski ključ,
   postojeće šifrirane integracije, storage, originalne datoteke i preusmjerenja.
   Ako domena ostaje na istom serveru, provjeriti prebacivanje web korijena bez
   nepotrebne promjene DNS-a. Primarna cPanel domena može zahtijevati poseban
   postupak; putanju potvrditi prije termina, bez promjene stare trgovine.
3. U dogovorenom terminu kratko zatvoriti upise u OpenCart (narudžbe,
   registracije, administracija i automatska ažuriranja); zabilježiti zadnji
   order/customer ID i vrijeme. Zaustaviti stare cronove i popisati eventualne
   vanjske upise/callbackove. Zaustaviti ili isprazniti queue workere i
   dolazne payment/ERP callbackove kontrolirano privremeno spremiti ili
   zaustaviti; nijedan ne smije pisati poslije završnog izvornog izvoza.
   Uskladiti već poslane, nepotvrđene zahtjeve i planirati sigurno ponavljanje
   spremljenih callbackova bez dvostruke obrade. Maksimalni ID-evi sami ne
   otkrivaju naknadnu promjenu statusa postojeće narudžbe.
4. Napraviti završni konzistentni izvoz, prenijeti zadnje razlike u zasebnu novu
   bazu te ponoviti financijske i identitetske provjere. Kod bilo kojeg
   neriješenog konflikta ili odstupanja odgoditi prebacivanje.
5. Eksplicitno aktivirati provjereni cjenik, konfigurirati `APP_URL` i
   produkcijske integracije, prebaciti korijen domene na novu aplikaciju te
   osvježiti cache. Kod prvog pregleda zadržati javno naručivanje zatvorenim.
6. Provjeriti prijavu, više grupa kupaca, akcije, košaricu, dostavu/plaćanje,
   dokumente i odabrane stare URL-ove. Tek tada otvoriti naručivanje i uključiti
   nove dogovorene cronove/integracije. Stari i novi posao ne smiju istodobno
   ažurirati iste poslovne podatke.

Cilj prekida je 15–30 minuta; za termin zasad rezervirati do sat vremena.
To je planski okvir, ne izmjereno jamstvo. Konačnu procjenu dati nakon probe sa
svježim podacima i mjerenja izvoza, prijenosa, završnog uvoza i provjera.

## Povrat i zaštita novih narudžbi

Prije otvaranja novog naručivanja moguć je povrat korijena domene i konfiguracije
na sačuvani OpenCart. Nakon što nova trgovina primi stvarne narudžbe,
registracije ili ERP potvrde, slijepi povrat stare baze izgubio bi te podatke.
Tada ponovno zatvoriti upise, izvesti i sačuvati novi delta skup te odlučiti o
kontroliranom povratu s prijenosom promjena ili popravku nove aplikacije.
Sigurnosne kopije i prethodni kod ostaju dostupni; CRM tablice se ne ruše pri
povratu koda.

## Provjerena lokalna kopija — 14. listopada 2026.

Izvor ove probe je kopija **od 6. listopada**, valuta EUR (tečaj 1), vremenska
zona Europe/Zagreb. Nije izvršen novi izvoz produkcije, osvježavanje javne testne
baze ni prebacivanje domene.

- Kopiranje dvije izolirane baze: 51,41 s; uspostavljanje početnog stanja:
  388,12 s; puni probni refresh: 718,14 s. To nisu ukupni minuti prekida rada:
  mjerenja ne uključuju svježi izvoz, mrežni prijenos, vraćanje baze na server,
  promjenu domene i završni pregled.
- Financijska provjera: **PASS**, svih 724.730 važećih cjenovnih stavki na
  četiri decimale. Dodatnih 31.970 starih stavki bez valjanog artikla ili opsega
  sačuvano je u privatnoj arhivi. Nijedna takva cijena nije objavljena.
- Provjereno je 255 efektivnih scenarija za svih 11 grupa s dostupnim odobrenim
  računom. Tri grupe nemaju takav račun. Postojeće količinsko pravilo nema
  valjani povezani artikl/opseg i ostaje arhivirano; stvarno aktivnih
  količinskih cijena u ovoj kopiji nema. Opća logika pragova dodatno je
  provjerena automatskim testovima.
- Svih 11.376 starih CRM zapisa je sačuvano: 11.369 povezanih sesija, sedam
  arhivskih zapisa bez postojećeg kupca. Nisu izmišljani identiteti ni ishodi.
  Trinaest računa osoblja i 1.061 dodjela kupaca ostali su sačuvani.
- Oba modula referentnih cijena isključena su u ovoj kopiji; dodatne tablice
  grupnih cijena opcija su prazne. Svježa kopija mora ponovno proći te provjere.
- **1.381 artikl ima konflikt zaliha**: nova lokalna trgovina sadrži novija
  ažuriranja od starog izvora. Postupak ih čuva i blokira prijelaz do svježeg
  izvora ili provjerenog usklađenja. Cjenik ostaje neaktivan.

Provjeru identiteta baze treba izvršiti prije ORM upisa i migracija. Kopirana
konfiguracija odbacuje izvorno ime veze; inače model može koristiti drugu bazu
od one iz koje je čitao. Regresije pokrivaju taj slučaj, prekinute prijenose,
stare lozinke, lažne prijevode i ponovljivu CRM migraciju. Financijske provjere
veza narudžbi koriste indeksirano povezivanje tekstualnih izvornih ID-eva.

Konačni privatni izvještaj sadrži rezultat ponavljanja i svih preostalih
automatskih uvjeta. Financijski PASS sam po sebi nije dopuštenje za prijelaz;
svježi podaci i ručni koraci iz ovog dokumenta ostaju obavezni.
