<?php

namespace Database\Seeders;

use App\Models\Catalog\Category\Category;
use App\Models\User;
use App\Services\Front\NavigationMenuService;
use App\Services\Settings\SystemSettingsService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use JsonException;

class TermolCatalogSeeder extends Seeder
{
    /**
     * Snapshot of the public Termol category navigation fetched on 2026-07-24.
     *
     * @throws JsonException
     */
    public function run(): void
    {
        /** @var array<int, array{name:string,source_url:string,children:array}> $tree */
        $tree = json_decode(<<<'JSON'
[{"name":"Klimatizacija","source_url":"/klimatizacija.aspx","children":[{"name":"Aux","source_url":"/klimatizacija/aux-1.aspx","children":[{"name":"Setovi","source_url":"/klimatizacija/aux-1/setovi-19.aspx","children":[]}]},{"name":"Daikin","source_url":"/klimatizacija/daikin.aspx","children":[{"name":"Setovi","source_url":"/klimatizacija/daikin/setovi-4.aspx","children":[]},{"name":"Unutarnje jedinice","source_url":"/klimatizacija/daikin/unutarnje-jedinice-3.aspx","children":[]},{"name":"Vanjske jedinice","source_url":"/klimatizacija/daikin/vanjske-jedinice-1.aspx","children":[]},{"name":"Dodatna oprema","source_url":"/klimatizacija/daikin/dodatna-oprema-7.aspx","children":[]}]},{"name":"Gree","source_url":"/klimatizacija/gree.aspx","children":[{"name":"Unutarnje jedinice","source_url":"/klimatizacija/gree/unutarnje-jedinice-2.aspx","children":[]},{"name":"Setovi","source_url":"/klimatizacija/gree/setovi-13.aspx","children":[]},{"name":"Vanjske jedinice","source_url":"/klimatizacija/gree/vanjske-jedinice-9.aspx","children":[]}]},{"name":"Haier","source_url":"/klimatizacija/haier.aspx","children":[{"name":"Setovi","source_url":"/klimatizacija/haier/setovi-1.aspx","children":[]},{"name":"Unutarnje jedinice","source_url":"/klimatizacija/haier/unutarnje-jedinice-1.aspx","children":[]},{"name":"Vanjske jedinice","source_url":"/klimatizacija/haier/vanjske-jedinice.aspx","children":[]}]},{"name":"LG","source_url":"/klimatizacija/lg.aspx","children":[{"name":"Setovi","source_url":"/klimatizacija/lg/setovi-5.aspx","children":[]}]},{"name":"Maxon","source_url":"/klimatizacija/maxon.aspx","children":[{"name":"Setovi","source_url":"/klimatizacija/maxon/setovi.aspx","children":[]},{"name":"Vanjske jedinice","source_url":"/klimatizacija/maxon/vanjske-jedinice-5.aspx","children":[]}]},{"name":"Mitsubishi","source_url":"/klimatizacija/mitsubishi.aspx","children":[{"name":"Setovi","source_url":"/klimatizacija/mitsubishi/setovi-7.aspx","children":[]},{"name":"Unutarnje jedinice","source_url":"/klimatizacija/mitsubishi/unutarnje-jedinice-5.aspx","children":[]},{"name":"Vanjske jedinice","source_url":"/klimatizacija/mitsubishi/vanjske-jedinice-4.aspx","children":[]},{"name":"Dodatna oprema","source_url":"/klimatizacija/mitsubishi/dodatna-oprema-4.aspx","children":[]}]},{"name":"Toshiba","source_url":"/klimatizacija/toshiba.aspx","children":[{"name":"Setovi","source_url":"/klimatizacija/toshiba/setovi-3.aspx","children":[]},{"name":"Unutarnje jedinice","source_url":"/klimatizacija/toshiba/unutarnje-jedinice.aspx","children":[]},{"name":"Vanjske jedinice","source_url":"/klimatizacija/toshiba/vanjske-jedinice-3.aspx","children":[]},{"name":"Dodatna oprema","source_url":"/klimatizacija/toshiba/dodatna-oprema-3.aspx","children":[]}]},{"name":"Vivax","source_url":"/klimatizacija/vivax.aspx","children":[{"name":"Setovi E+ design","source_url":"/klimatizacija/vivax/setovi-e-design.aspx","children":[]},{"name":"Setovi Q design","source_url":"/klimatizacija/vivax/setovi-q-design.aspx","children":[]},{"name":"Setovi Y design","source_url":"/klimatizacija/vivax/setovi-y-design.aspx","children":[]},{"name":"Setovi M design","source_url":"/klimatizacija/vivax/setovi-m-design.aspx","children":[]},{"name":"Dodatna oprema","source_url":"/klimatizacija/vivax/dodatna-oprema.aspx","children":[]},{"name":"Vanjske jedinice","source_url":"/klimatizacija/vivax/vanjske-jedinice-2.aspx","children":[]},{"name":"Unutarnje jedinice","source_url":"/klimatizacija/vivax/unutarnje-jedinice-4.aspx","children":[]},{"name":"Setovi S design","source_url":"/klimatizacija/vivax/setovi-s-design.aspx","children":[]},{"name":"Setovi H+ design","source_url":"/klimatizacija/vivax/setovi-h-design.aspx","children":[]},{"name":"Setovi R+ design","source_url":"/klimatizacija/vivax/setovi-r-design-1.aspx","children":[]},{"name":"Setovi N design","source_url":"/klimatizacija/vivax/setovi-n-design.aspx","children":[]},{"name":"Setovi X design","source_url":"/klimatizacija/vivax/setovi-x-design.aspx","children":[]},{"name":"Setovi R PRO","source_url":"/klimatizacija/vivax/setovi-r-pro.aspx","children":[]},{"name":"Setovi M PRO","source_url":"/klimatizacija/vivax/setovi-m-pro.aspx","children":[]},{"name":"Setovi T design","source_url":"/klimatizacija/vivax/setovi-t-design.aspx","children":[]}]},{"name":"Ostalo za klime","source_url":"/klimatizacija/ostalo.aspx","children":[{"name":"Čišćenje klima uređaja","source_url":"/klimatizacija/ostalo/ciscenje.aspx","children":[]},{"name":"Oprema za ugradnju klima","source_url":"/klimatizacija/ostalo/oprema-za-ugradnju-klima.aspx","children":[]}]},{"name":"Vaillant","source_url":"/klimatizacija/vaillant-4.aspx","children":[{"name":"Setovi","source_url":"/klimatizacija/vaillant-4/setovi-8.aspx","children":[]}]},{"name":"Fujitsu","source_url":"/klimatizacija/fujitsu.aspx","children":[{"name":"Setovi","source_url":"/klimatizacija/fujitsu/setovi-2.aspx","children":[]},{"name":"Unutarnje jedinice","source_url":"/klimatizacija/fujitsu/unutarnje-jedinice-8.aspx","children":[]},{"name":"Vanjske jedinice","source_url":"/klimatizacija/fujitsu/vanjske-jedinice-6.aspx","children":[]},{"name":"Dodatna oprema","source_url":"/klimatizacija/fujitsu/dodatna-oprema-6.aspx","children":[]}]},{"name":"Sinclair","source_url":"/klimatizacija/sinclair-1.aspx","children":[{"name":"Setovi","source_url":"/klimatizacija/sinclair-1/setovi-10.aspx","children":[]},{"name":"Unutarnje jedinice","source_url":"/klimatizacija/sinclair-1/unutarnje-jedinice-7.aspx","children":[]},{"name":"Vanjske jedinice","source_url":"/klimatizacija/sinclair-1/vanjske-jedinice-7.aspx","children":[]},{"name":"Dodatna oprema","source_url":"/klimatizacija/sinclair-1/dodatna-oprema-8.aspx","children":[]}]},{"name":"Azuri","source_url":"/klimatizacija/azuri.aspx","children":[{"name":"Setovi","source_url":"/klimatizacija/azuri/setovi-12.aspx","children":[]},{"name":"Unutarnje jedinice","source_url":"/klimatizacija/azuri/unutarnje-jedinice-12.aspx","children":[]},{"name":"Vanjske jedinice","source_url":"/klimatizacija/azuri/vanjske-jedinice-12.aspx","children":[]}]},{"name":"Qzen","source_url":"/klimatizacija/qzen-1.aspx","children":[{"name":"Setovi","source_url":"/klimatizacija/qzen-1/setovi-11.aspx","children":[]},{"name":"Vanjske jedinice","source_url":"/klimatizacija/qzen-1/vanjske-jedinice-13.aspx","children":[]},{"name":"Unutarnje jedinice","source_url":"/klimatizacija/qzen-1/unutarnje-jedinice-13.aspx","children":[]}]},{"name":"Hyundai","source_url":"/klimatizacija/hyundai.aspx","children":[{"name":"Setovi","source_url":"/klimatizacija/hyundai/setovi-16.aspx","children":[]},{"name":"Vanjske jedinice","source_url":"/klimatizacija/hyundai/vanjske-jedinice-11.aspx","children":[]},{"name":"Unutarnje jedinice","source_url":"/klimatizacija/hyundai/unutarnje-jedinice-11.aspx","children":[]}]},{"name":"Ariston","source_url":"/klimatizacija/ariston-1.aspx","children":[{"name":"Setovi","source_url":"/klimatizacija/ariston-1/setovi-17.aspx","children":[]}]},{"name":"Montaža i servis","source_url":"/klimatizacija/montaza-i-servis.aspx","children":[{"name":"Montaža klima uređaja","source_url":"/klimatizacija/montaza-i-servis/montaza-klima-uredjaja.aspx","children":[]}]},{"name":"Midea","source_url":"/klimatizacija/midea.aspx","children":[{"name":"Setovi","source_url":"/klimatizacija/midea/setovi-23.aspx","children":[]}]},{"name":"Hitachi","source_url":"/klimatizacija/hitachi.aspx","children":[{"name":"Setovi","source_url":"/klimatizacija/hitachi/setovi-21.aspx","children":[]}]},{"name":"QTherm","source_url":"/klimatizacija/qtherm.aspx","children":[{"name":"Setovi","source_url":"/klimatizacija/qtherm/setovi-20.aspx","children":[]},{"name":"Mobilne klime","source_url":"/klimatizacija/qtherm/mobilne-klime.aspx","children":[]}]},{"name":"Korel","source_url":"/klimatizacija/korel.aspx","children":[{"name":"Setovi","source_url":"/klimatizacija/korel/setovi-22.aspx","children":[]},{"name":"Vanjske jedinice","source_url":"/klimatizacija/korel/vanjske-jedinice-14.aspx","children":[]},{"name":"Unutarnje jedinice","source_url":"/klimatizacija/korel/unutarnje-jedinice-14.aspx","children":[]}]},{"name":"Alat","source_url":"/klimatizacija/alat.aspx","children":[{"name":"Value","source_url":"/klimatizacija/alat/value.aspx","children":[]}]},{"name":"XIAOMI","source_url":"/klimatizacija/xiaomi.aspx","children":[{"name":"Setovi","source_url":"/klimatizacija/xiaomi/setovi-24.aspx","children":[]}]},{"name":"Ariston","source_url":"/klimatizacija/ariston-2.aspx","children":[{"name":"Mobilne klime","source_url":"/klimatizacija/ariston-2/mobilne-klime-1.aspx","children":[]}]}]},{"name":"Kupaonica i kuhinja","source_url":"/kupaonica.aspx","children":[{"name":"Miješalice","source_url":"/kupaonica/mijesalice.aspx","children":[{"name":"Miješalice za umivaonik","source_url":"/kupaonica/mijesalice/mijesalice-za-umivaonik.aspx","children":[]},{"name":"Miješalice za tuš kadu","source_url":"/kupaonica/mijesalice/mijesalice-za-tus-kadu.aspx","children":[]},{"name":"Pribor za miješalice","source_url":"/kupaonica/mijesalice/pribor-za-mijesalice.aspx","children":[]},{"name":"Miješalice za kadu","source_url":"/kupaonica/mijesalice/mijesalice-za-kadu.aspx","children":[]},{"name":"Miješalice za bide","source_url":"/kupaonica/mijesalice/mijesalice-za-bide.aspx","children":[]},{"name":"Miješalice za sudoper","source_url":"/kupaonica/mijesalice/mijesalice-za-sudoper.aspx","children":[]},{"name":"Paketi miješalica","source_url":"/kupaonica/mijesalice/paketi-mijesalica.aspx","children":[]}]},{"name":"Sanitarije","source_url":"/kupaonica/sanitarije.aspx","children":[{"name":"Umivaonici","source_url":"/kupaonica/sanitarije/umivaonici.aspx","children":[]},{"name":"Vodokotlići","source_url":"/kupaonica/sanitarije/vodokotlici.aspx","children":[]},{"name":"Toaletne školjke","source_url":"/kupaonica/sanitarije/toaletne-skoljke.aspx","children":[]},{"name":"Tipke za vodokotlić","source_url":"/kupaonica/sanitarije/tipke-za-vodokotlic.aspx","children":[]},{"name":"Rezervni pribor za sanitarije","source_url":"/kupaonica/sanitarije/rezervni-pribor-za-sanitarije.aspx","children":[]},{"name":"Bidei","source_url":"/kupaonica/sanitarije/bidei.aspx","children":[]},{"name":"Pisoari","source_url":"/kupaonica/sanitarije/pisoari.aspx","children":[]},{"name":"Toaletne daske","source_url":"/kupaonica/sanitarije/toaletne-daske.aspx","children":[]}]},{"name":"Tuš program","source_url":"/kupaonica/tus-program.aspx","children":[{"name":"Tuš paneli i sistemi","source_url":"/kupaonica/tus-program/tus-paneli-i-sistemi.aspx","children":[]},{"name":"Pribor za tuš program","source_url":"/kupaonica/tus-program/pribor-za-tus-program.aspx","children":[]},{"name":"Klizne šipke","source_url":"/kupaonica/tus-program/klizne-sipke.aspx","children":[]},{"name":"Tuš slušalice i crijeva","source_url":"/kupaonica/tus-program/tus-slusalice-i-crijeva.aspx","children":[]},{"name":"Tuš setovi","source_url":"/kupaonica/tus-program/tus-setovi.aspx","children":[]}]},{"name":"Kade i tuš kade","source_url":"/kupaonica/kade-i-tus-kade.aspx","children":[{"name":"Tuš kade","source_url":"/kupaonica/kade-i-tus-kade/tus-kade.aspx","children":[]},{"name":"Kade","source_url":"/kupaonica/kade-i-tus-kade/kade.aspx","children":[]}]},{"name":"Kabine, vrata i stranice","source_url":"/kupaonica/kabine-vrata-i-stranice.aspx","children":[{"name":"Tuš kabine","source_url":"/kupaonica/kabine-vrata-i-stranice/tus-kabine.aspx","children":[]},{"name":"Pribor za tuš program","source_url":"/kupaonica/kabine-vrata-i-stranice/pribor-za-tus-program-1.aspx","children":[]},{"name":"Tuš stranice","source_url":"/kupaonica/kabine-vrata-i-stranice/tus-stranice.aspx","children":[]},{"name":"Tuš vrata","source_url":"/kupaonica/kabine-vrata-i-stranice/tus-vrata.aspx","children":[]}]},{"name":"Kupaonski namještaj","source_url":"/kupaonica/kupaonski-namjestaj.aspx","children":[{"name":"Rasvjeta","source_url":"/kupaonica/kupaonski-namjestaj/rasvjeta.aspx","children":[]},{"name":"Kupaonske baze","source_url":"/kupaonica/kupaonski-namjestaj/kupaonske-baze.aspx","children":[]},{"name":"Kupaonski ormarići i ogledala","source_url":"/kupaonica/kupaonski-namjestaj/kupaonski-ormarici-i-ogledala.aspx","children":[]},{"name":"Dodatni i rezervni pribor","source_url":"/kupaonica/kupaonski-namjestaj/dodatni-i-rezervni-pribor.aspx","children":[]}]},{"name":"Galanterija","source_url":"/kupaonica/galanterija.aspx","children":[{"name":"Košare za sušila i rublje","source_url":"/kupaonica/galanterija/kosare-za-susila-i-rublje.aspx","children":[]},{"name":"Police","source_url":"/kupaonica/galanterija/police.aspx","children":[]},{"name":"Dozatori i držači sapuna","source_url":"/kupaonica/galanterija/dozatori-i-drzaci-sapuna.aspx","children":[]},{"name":"Ostala galanterija","source_url":"/kupaonica/galanterija/ostala-galanterija.aspx","children":[]},{"name":"Držači ručnika i vješalica","source_url":"/kupaonica/galanterija/drzaci-rucnika-i-vjesalica.aspx","children":[]},{"name":"Čaše i držači četkica za zube","source_url":"/kupaonica/galanterija/case-i-drzaci-cetkica-za-zube.aspx","children":[]},{"name":"Toaletne četke","source_url":"/kupaonica/galanterija/toaletne-cetke.aspx","children":[]},{"name":"Setovi galanterije","source_url":"/kupaonica/galanterija/setovi-galanterije.aspx","children":[]},{"name":"Kante za smeće","source_url":"/kupaonica/galanterija/kante-za-smece.aspx","children":[]},{"name":"Držači toaletnog papira","source_url":"/kupaonica/galanterija/drzaci-toaletnog-papira.aspx","children":[]}]},{"name":"Sudoperi","source_url":"/kupaonica/sudoperi.aspx","children":[{"name":"Inox sudoperi","source_url":"/kupaonica/sudoperi/inox-sudoperi.aspx","children":[]},{"name":"Sifoni za sudopere","source_url":"/kupaonica/sudoperi/sifoni-za-sudopere.aspx","children":[]}]},{"name":"Sifoni, brtve i tuš kanalice","source_url":"/kupaonica/sifoni-i-tus-kanalice.aspx","children":[{"name":"Sifoni","source_url":"/kupaonica/sifoni-i-tus-kanalice/sifoni.aspx","children":[]},{"name":"Tuš kanalice","source_url":"/kupaonica/sifoni-i-tus-kanalice/tus-kanalice.aspx","children":[]},{"name":"Brtve","source_url":"/kupaonica/sifoni-i-tus-kanalice/brtve.aspx","children":[]}]}]},{"name":"Pločice i materijali","source_url":"/plocice-i-materijali.aspx","children":[{"name":"Pločice","source_url":"/plocice-i-materijali/plocice.aspx","children":[{"name":"Ljepilo","source_url":"/plocice-i-materijali/plocice/ljepilo.aspx","children":[]}]},{"name":"Ceresit","source_url":"/plocice-i-materijali/ceresit.aspx","children":[{"name":"Silikoni","source_url":"/plocice-i-materijali/ceresit/silikoni-1.aspx","children":[]},{"name":"Fug mase","source_url":"/plocice-i-materijali/ceresit/fug-mase.aspx","children":[]}]},{"name":"Mapei","source_url":"/plocice-i-materijali/mapei.aspx","children":[{"name":"Fug mase","source_url":"/plocice-i-materijali/mapei/fug-mase-1.aspx","children":[]},{"name":"Hidroizolacija","source_url":"/plocice-i-materijali/mapei/hidroizolacija.aspx","children":[]},{"name":"Silikoni","source_url":"/plocice-i-materijali/mapei/silikoni.aspx","children":[]}]}]},{"name":"Radijatori i podno grijanje","source_url":"/radijatori-i-podno-grijanje.aspx","children":[{"name":"Pločasti radijatori","source_url":"/radijatori-i-podno-grijanje/plocasti-radijatori.aspx","children":[{"name":"Kompaktni radijatori Vaillant","source_url":"/radijatori-i-podno-grijanje/plocasti-radijatori/kompaktni-radijatori-vaillant.aspx","children":[]},{"name":"Ventilski radijatori Vaillant","source_url":"/radijatori-i-podno-grijanje/plocasti-radijatori/ventilski-radijatori-vaillant.aspx","children":[]}]},{"name":"Kupaonski radijatori","source_url":"/radijatori-i-podno-grijanje/kupaonski-radijatori.aspx","children":[{"name":"Terma","source_url":"/radijatori-i-podno-grijanje/kupaonski-radijatori/terma-1.aspx","children":[]},{"name":"As","source_url":"/radijatori-i-podno-grijanje/kupaonski-radijatori/as.aspx","children":[]},{"name":"Trend","source_url":"/radijatori-i-podno-grijanje/kupaonski-radijatori/trend.aspx","children":[]},{"name":"Pe-Line","source_url":"/radijatori-i-podno-grijanje/kupaonski-radijatori/pe-line.aspx","children":[]}]},{"name":"Električni radijator/grijalice","source_url":"/radijatori-i-podno-grijanje/elektricni-radijator-grijalice.aspx","children":[{"name":"Vaillant","source_url":"/radijatori-i-podno-grijanje/elektricni-radijator-grijalice/vaillant-1.aspx","children":[]},{"name":"Terma","source_url":"/radijatori-i-podno-grijanje/elektricni-radijator-grijalice/terma-5.aspx","children":[]},{"name":"Technotherm","source_url":"/radijatori-i-podno-grijanje/elektricni-radijator-grijalice/technotherm.aspx","children":[]},{"name":"Atlantic","source_url":"/radijatori-i-podno-grijanje/elektricni-radijator-grijalice/atlantic-1.aspx","children":[]}]},{"name":"Radijatorski ventili","source_url":"/radijatori-i-podno-grijanje/radijatorski-ventili.aspx","children":[{"name":"Heimeier","source_url":"/radijatori-i-podno-grijanje/radijatorski-ventili/heimeier.aspx","children":[]},{"name":"Herz","source_url":"/radijatori-i-podno-grijanje/radijatorski-ventili/herz-1.aspx","children":[]}]},{"name":"Podno grijanje","source_url":"/radijatori-i-podno-grijanje/podno-grijanje.aspx","children":[{"name":"Engo","source_url":"/radijatori-i-podno-grijanje/podno-grijanje/engo.aspx","children":[]},{"name":"Herz","source_url":"/radijatori-i-podno-grijanje/podno-grijanje/herz.aspx","children":[]},{"name":"Terma","source_url":"/radijatori-i-podno-grijanje/podno-grijanje/terma-4.aspx","children":[]},{"name":"Seltron","source_url":"/radijatori-i-podno-grijanje/podno-grijanje/seltron.aspx","children":[]},{"name":"QTherm","source_url":"/radijatori-i-podno-grijanje/podno-grijanje/qtherm-1.aspx","children":[]},{"name":"Rehau","source_url":"/radijatori-i-podno-grijanje/podno-grijanje/rehau.aspx","children":[]},{"name":"Letina","source_url":"/radijatori-i-podno-grijanje/podno-grijanje/letina.aspx","children":[]}]}]},{"name":"Kotlovi i kamini","source_url":"/kotlovi-i-kamini.aspx","children":[{"name":"Kotlovi/kamini na kruta goriva","source_url":"/kotlovi-i-kamini/kotlovi-kamini-na-kruta-goriva.aspx","children":[{"name":"Kotlovi","source_url":"/kotlovi-i-kamini/kotlovi-kamini-na-kruta-goriva/kotlovi-1.aspx","children":[]},{"name":"Kamini","source_url":"/kotlovi-i-kamini/kotlovi-kamini-na-kruta-goriva/kamini.aspx","children":[]}]},{"name":"Kotlovi i kamini na pelete","source_url":"/kotlovi-i-kamini/kotlovi-i-kamini-na-pelete.aspx","children":[{"name":"Kotlovi","source_url":"/kotlovi-i-kamini/kotlovi-i-kamini-na-pelete/kotlovi.aspx","children":[]},{"name":"Peći","source_url":"/kotlovi-i-kamini/kotlovi-i-kamini-na-pelete/peci.aspx","children":[]},{"name":"Pelet spremnici","source_url":"/kotlovi-i-kamini/kotlovi-i-kamini-na-pelete/pelet-spremnici.aspx","children":[]},{"name":"Dodatna oprema","source_url":"/kotlovi-i-kamini/kotlovi-i-kamini-na-pelete/dodatna-oprema-2.aspx","children":[]}]},{"name":"Spremnici","source_url":"/kotlovi-i-kamini/akumulacijski-spremnici.aspx","children":[{"name":"Centrometal","source_url":"/kotlovi-i-kamini/akumulacijski-spremnici/centrometal-4.aspx","children":[]}]},{"name":"Uljni kotlovi","source_url":"/kotlovi-i-kamini/uljni-kotlovi.aspx","children":[{"name":"Centrometal","source_url":"/kotlovi-i-kamini/uljni-kotlovi/centrometal.aspx","children":[]}]},{"name":"Plinski kotlovi","source_url":"/kotlovi-i-kamini/plinski-kotlovi.aspx","children":[{"name":"Vaillant","source_url":"/kotlovi-i-kamini/plinski-kotlovi/vaillant-3.aspx","children":[]}]}]},{"name":"Bojleri i spremnici","source_url":"/bojleri-i-spremnici.aspx","children":[{"name":"Plinski bojleri","source_url":"/bojleri-i-spremnici/plinski-bojleri.aspx","children":[{"name":"Kombinirani uređaji","source_url":"/bojleri-i-spremnici/plinski-bojleri/kombinirani-uredjaji.aspx","children":[]},{"name":"Cirko uređaji za grijanje","source_url":"/bojleri-i-spremnici/plinski-bojleri/cirko-uredjaji-za-grijanje.aspx","children":[]},{"name":"Protočni grijači vode","source_url":"/bojleri-i-spremnici/plinski-bojleri/protocni-grijaci-vode.aspx","children":[]},{"name":"Spremnici","source_url":"/bojleri-i-spremnici/plinski-bojleri/spremnici-1.aspx","children":[]}]},{"name":"Električni bojleri","source_url":"/bojleri-i-spremnici/elektricni-bojleri.aspx","children":[{"name":"Atlantic","source_url":"/bojleri-i-spremnici/elektricni-bojleri/atlantic.aspx","children":[]},{"name":"Vaillant","source_url":"/bojleri-i-spremnici/elektricni-bojleri/vaillant-2.aspx","children":[]},{"name":"Centrometal","source_url":"/bojleri-i-spremnici/elektricni-bojleri/centrometal-3.aspx","children":[]},{"name":"Ariston","source_url":"/bojleri-i-spremnici/elektricni-bojleri/ariston.aspx","children":[]},{"name":"Gorenje","source_url":"/bojleri-i-spremnici/elektricni-bojleri/gorenje.aspx","children":[]},{"name":"Terma","source_url":"/bojleri-i-spremnici/elektricni-bojleri/terma-2.aspx","children":[]},{"name":"Tiki","source_url":"/bojleri-i-spremnici/elektricni-bojleri/tiki.aspx","children":[]}]},{"name":"Oprema za bojlere","source_url":"/bojleri-i-spremnici/oprema-za-bojlere.aspx","children":[{"name":"Regulacija Vaillant","source_url":"/bojleri-i-spremnici/oprema-za-bojlere/regulacija-vaillant.aspx","children":[]},{"name":"Pribor Remeha","source_url":"/bojleri-i-spremnici/oprema-za-bojlere/regulacija-remeha.aspx","children":[]},{"name":"Regulacija Poer","source_url":"/bojleri-i-spremnici/oprema-za-bojlere/regulacija-poer.aspx","children":[]},{"name":"Pribor Wolf","source_url":"/bojleri-i-spremnici/oprema-za-bojlere/pribor-wolf.aspx","children":[]}]},{"name":"Električni kombinirani bojleri","source_url":"/bojleri-i-spremnici/elektricni-kombinirani-bojleri.aspx","children":[{"name":"Bosch","source_url":"/bojleri-i-spremnici/elektricni-kombinirani-bojleri/bosch-1.aspx","children":[]},{"name":"Tiki","source_url":"/bojleri-i-spremnici/elektricni-kombinirani-bojleri/tiki-1.aspx","children":[]}]}]},{"name":"Obnovljivi izvori","source_url":"/obnovljivi-izvori.aspx","children":[{"name":"Dizalice topline","source_url":"/obnovljivi-izvori/dizalice-topline.aspx","children":[{"name":"Vaillant","source_url":"/obnovljivi-izvori/dizalice-topline/vaillant-6.aspx","children":[]},{"name":"Vivax","source_url":"/obnovljivi-izvori/dizalice-topline/vivax-2.aspx","children":[]},{"name":"Haier","source_url":"/obnovljivi-izvori/dizalice-topline/haier-1.aspx","children":[]},{"name":"Sinclair","source_url":"/obnovljivi-izvori/dizalice-topline/sinclair-3.aspx","children":[]},{"name":"LG","source_url":"/obnovljivi-izvori/dizalice-topline/lg-4.aspx","children":[]},{"name":"Tiki","source_url":"/obnovljivi-izvori/dizalice-topline/tiki-3.aspx","children":[]}]},{"name":"Solarni sustavi i kolektori","source_url":"/obnovljivi-izvori/solarni-sustavi-i-kolektori.aspx","children":[{"name":"Vaillant","source_url":"/obnovljivi-izvori/solarni-sustavi-i-kolektori/vaillant-5.aspx","children":[]},{"name":"Bosch","source_url":"/obnovljivi-izvori/solarni-sustavi-i-kolektori/bosch-2.aspx","children":[]},{"name":"Terma","source_url":"/obnovljivi-izvori/solarni-sustavi-i-kolektori/terma-7.aspx","children":[]}]},{"name":"Spremnici","source_url":"/obnovljivi-izvori/spremnici.aspx","children":[{"name":"Centrometal","source_url":"/obnovljivi-izvori/spremnici/centrometal-1.aspx","children":[]},{"name":"Vaillant","source_url":"/obnovljivi-izvori/spremnici/vaillant.aspx","children":[]},{"name":"Tesy","source_url":"/obnovljivi-izvori/spremnici/tesy-1.aspx","children":[]},{"name":"Sinclair","source_url":"/obnovljivi-izvori/spremnici/sinclair-4.aspx","children":[]},{"name":"Tiki","source_url":"/obnovljivi-izvori/spremnici/tiki-2.aspx","children":[]}]},{"name":"Regulacija","source_url":"/obnovljivi-izvori/regulacija.aspx","children":[{"name":"Vaillant","source_url":"/obnovljivi-izvori/regulacija/vaillant-7.aspx","children":[]}]},{"name":"Fotonapon i pohrana energije","source_url":"/obnovljivi-izvori/fotonapon-i-pohrana-energije.aspx","children":[{"name":"Schrack","source_url":"/obnovljivi-izvori/fotonapon-i-pohrana-energije/schrack.aspx","children":[]}]},{"name":"ESS (Energy Storage System)","source_url":"/obnovljivi-izvori/ess-energy-storage-system.aspx","children":[{"name":"Vivax","source_url":"/obnovljivi-izvori/ess-energy-storage-system/vivax-5.aspx","children":[]}]}]},{"name":"Ventilacija i priprema zraka","source_url":"/ventilacija-i-priprema-zraka.aspx","children":[{"name":"Odvlaživači zraka","source_url":"/ventilacija-i-priprema-zraka/odvlazivaci-zraka.aspx","children":[{"name":"Trotec","source_url":"/ventilacija-i-priprema-zraka/odvlazivaci-zraka/trotec.aspx","children":[]},{"name":"Sinclair","source_url":"/ventilacija-i-priprema-zraka/odvlazivaci-zraka/sinclair-2.aspx","children":[]},{"name":"Gree","source_url":"/ventilacija-i-priprema-zraka/odvlazivaci-zraka/gree-1.aspx","children":[]},{"name":"Hyundai","source_url":"/ventilacija-i-priprema-zraka/odvlazivaci-zraka/hyundai-1.aspx","children":[]},{"name":"QTherm","source_url":"/ventilacija-i-priprema-zraka/odvlazivaci-zraka/qtherm-2.aspx","children":[]}]},{"name":"Pročišćivači zraka","source_url":"/ventilacija-i-priprema-zraka/prociscivaci-zraka.aspx","children":[{"name":"Sinclair","source_url":"/ventilacija-i-priprema-zraka/prociscivaci-zraka/sinclair.aspx","children":[]},{"name":"Mitsubishi","source_url":"/ventilacija-i-priprema-zraka/prociscivaci-zraka/mitsubishi-2.aspx","children":[]}]}]},{"name":"Instalacije","source_url":"/instalacije.aspx","children":[{"name":"Grijanje i voda","source_url":"/instalacije/grijanje.aspx","children":[{"name":"Aluplast cijevi","source_url":"/instalacije/grijanje/aluplast-cijevi.aspx","children":[]},{"name":"Alati i pribor","source_url":"/instalacije/grijanje/alati-i-pribor.aspx","children":[]},{"name":"Dimovodi","source_url":"/instalacije/grijanje/dimovodi.aspx","children":[]},{"name":"Ventili za vodu","source_url":"/instalacije/grijanje/ventili-za-vodu.aspx","children":[]}]},{"name":"Pumpe za centralno grijanje","source_url":"/instalacije/pumpe-za-centralno-grijanje.aspx","children":[{"name":"Grundfos","source_url":"/instalacije/pumpe-za-centralno-grijanje/grundfos.aspx","children":[]},{"name":"Terma","source_url":"/instalacije/pumpe-za-centralno-grijanje/terma-3.aspx","children":[]}]},{"name":"Pumpe za navodnjavanje","source_url":"/instalacije/pumpe-za-navodnjavanje.aspx","children":[{"name":"Pedrollo","source_url":"/instalacije/pumpe-za-navodnjavanje/pedrollo.aspx","children":[]}]},{"name":"Filteri","source_url":"/instalacije/filteri.aspx","children":[{"name":"Filteri za vodu","source_url":"/instalacije/filteri/filteri-za-vodu.aspx","children":[]}]},{"name":"Plinska instalacija","source_url":"/instalacije/plinski-ormarici.aspx","children":[{"name":"Plinski ormarići","source_url":"/instalacije/plinski-ormarici/otvoreni.aspx","children":[]},{"name":"Plinski ventili","source_url":"/instalacije/plinski-ormarici/zatvoreni.aspx","children":[]},{"name":"Boagaz","source_url":"/instalacije/plinski-ormarici/boagaz.aspx","children":[]}]},{"name":"Cijevi","source_url":"/instalacije/cijevi.aspx","children":[{"name":"CU Cijevi","source_url":"/instalacije/cijevi/cu-cijevi.aspx","children":[]}]},{"name":"Vrtni pribor","source_url":"/instalacije/vrtni-pribor.aspx","children":[{"name":"Vrtne slavine","source_url":"/instalacije/vrtni-pribor/vrtne-slavine.aspx","children":[]}]},{"name":"Obujmice","source_url":"/instalacije/obujmice.aspx","children":[{"name":"Obujmice","source_url":"/instalacije/obujmice/obujmice-1.aspx","children":[]}]},{"name":"Fitinzi","source_url":"/instalacije/fitinzi-i-cijevi.aspx","children":[{"name":"Kan press čelik","source_url":"/instalacije/fitinzi-i-cijevi/kan-press-celik.aspx","children":[]},{"name":"Krom","source_url":"/instalacije/fitinzi-i-cijevi/krom.aspx","children":[]},{"name":"PVC Alkaten","source_url":"/instalacije/fitinzi-i-cijevi/pvc-alkaten.aspx","children":[]},{"name":"RBM","source_url":"/instalacije/fitinzi-i-cijevi/rbm-1.aspx","children":[]},{"name":"Bakar","source_url":"/instalacije/fitinzi-i-cijevi/bakar.aspx","children":[]},{"name":"Mesing","source_url":"/instalacije/fitinzi-i-cijevi/mesing.aspx","children":[]}]}]},{"name":"Kućanski uređaji","source_url":"/potrosacka-elektronika-1.aspx","children":[{"name":"Hladnjaci","source_url":"/potrosacka-elektronika-1/televizori-1.aspx","children":[{"name":"Vivax","source_url":"/potrosacka-elektronika-1/televizori-1/vivax-4.aspx","children":[]}]},{"name":"Mikrovalne pećnice","source_url":"/potrosacka-elektronika-1/mikrovalne-pecnice.aspx","children":[{"name":"Vivax","source_url":"/potrosacka-elektronika-1/mikrovalne-pecnice/vivax-15.aspx","children":[]}]},{"name":"Perilice rublja","source_url":"/potrosacka-elektronika-1/perilice-rublja-1.aspx","children":[{"name":"Vivax","source_url":"/potrosacka-elektronika-1/perilice-rublja-1/vivax-7.aspx","children":[]}]},{"name":"Perilice posuđa","source_url":"/potrosacka-elektronika-1/perilice-posudja-1.aspx","children":[{"name":"Vivax","source_url":"/potrosacka-elektronika-1/perilice-posudja-1/vivax-6.aspx","children":[]}]},{"name":"Ugradbene ploče","source_url":"/potrosacka-elektronika-1/ugradbene-ploce.aspx","children":[{"name":"Vivax","source_url":"/potrosacka-elektronika-1/ugradbene-ploce/vivax-12.aspx","children":[]}]},{"name":"Ugradbeni hladnjaci","source_url":"/potrosacka-elektronika-1/ugradbeni-hladnjaci.aspx","children":[{"name":"Vivax","source_url":"/potrosacka-elektronika-1/ugradbeni-hladnjaci/vivax-14.aspx","children":[]}]},{"name":"Kuhinjske nape","source_url":"/potrosacka-elektronika-1/kuhinjske-nape.aspx","children":[{"name":"Vivax","source_url":"/potrosacka-elektronika-1/kuhinjske-nape/vivax-11.aspx","children":[]}]},{"name":"Ugradbene perilice posuđa","source_url":"/potrosacka-elektronika-1/ugradbene-perilice-posudja.aspx","children":[{"name":"Vivax","source_url":"/potrosacka-elektronika-1/ugradbene-perilice-posudja/vivax-13.aspx","children":[]}]},{"name":"Zamrzivači","source_url":"/potrosacka-elektronika-1/zamrzivaci.aspx","children":[{"name":"Vivax","source_url":"/potrosacka-elektronika-1/zamrzivaci/vivax-8.aspx","children":[]}]},{"name":"Ugradbene pećnice","source_url":"/potrosacka-elektronika-1/ugradbene-pecnice.aspx","children":[{"name":"Vivax","source_url":"/potrosacka-elektronika-1/ugradbene-pecnice/vivax-10.aspx","children":[]}]},{"name":"Štednjaci","source_url":"/potrosacka-elektronika-1/stednjaci-1.aspx","children":[{"name":"Vivax","source_url":"/potrosacka-elektronika-1/stednjaci-1/vivax-9.aspx","children":[]}]}]},{"name":"Sredstva za čišćenje i njegu","source_url":"/sredstva-za-ciscenje-i-njegu.aspx","children":[{"name":"Čišćenje","source_url":"/sredstva-za-ciscenje-i-njegu/ciscenje-1.aspx","children":[{"name":"Čišćenje klima uređaja","source_url":"/sredstva-za-ciscenje-i-njegu/ciscenje-1/ciscenje-klima-uredjaja.aspx","children":[]},{"name":"Čišćenje sanitarija","source_url":"/sredstva-za-ciscenje-i-njegu/ciscenje-1/ciscenje-sanitarija.aspx","children":[]},{"name":"Ostala sredstva za čišćenje","source_url":"/sredstva-za-ciscenje-i-njegu/ciscenje-1/ostala-sredstva-za-ciscenje.aspx","children":[]},{"name":"Čišćenje i dezinfekcija","source_url":"/sredstva-za-ciscenje-i-njegu/ciscenje-1/ciscenje-i-dezinfekcija.aspx","children":[]}]},{"name":"Njega","source_url":"/sredstva-za-ciscenje-i-njegu/njega.aspx","children":[{"name":"Mirisi","source_url":"/sredstva-za-ciscenje-i-njegu/njega/mirisi.aspx","children":[]}]}]},{"name":"Rezervni dijelovi","source_url":"/rezervni-dijelovi.aspx","children":[{"name":"Bijela tehnika","source_url":"/rezervni-dijelovi/bijela-tehnika.aspx","children":[{"name":"Štednjaci","source_url":"/rezervni-dijelovi/bijela-tehnika/stednjaci.aspx","children":[]},{"name":"Perilice posuđa","source_url":"/rezervni-dijelovi/bijela-tehnika/perilice-posudja.aspx","children":[]},{"name":"Sušilice rublja","source_url":"/rezervni-dijelovi/bijela-tehnika/susilice-rublja.aspx","children":[]},{"name":"Perilice rublja","source_url":"/rezervni-dijelovi/bijela-tehnika/perilice-rublja.aspx","children":[]},{"name":"Električni bojleri","source_url":"/rezervni-dijelovi/bijela-tehnika/elektricni-bojleri-1.aspx","children":[]}]}]},{"name":"OUTLET","source_url":"/posebna-ponuda.aspx","children":[{"name":"Rasprodaja","source_url":"/posebna-ponuda/rasprodaja.aspx","children":[{"name":"Rasprodaja","source_url":"/posebna-ponuda/rasprodaja/rasprodaja-1.aspx","children":[]}]}]},{"name":"Sigurnosna oprema","source_url":"/sigurnosna-oprema.aspx","children":[{"name":"Zaštita od požara","source_url":"/sigurnosna-oprema/zastita-od-pozara.aspx","children":[{"name":"Detektori i zaštita","source_url":"/sigurnosna-oprema/zastita-od-pozara/detektori-i-zastita.aspx","children":[]}]}]},{"name":"Mali kućanski aparati","source_url":"/mali-kucanski-aparati.aspx","children":[{"name":"Blenderi","source_url":"/mali-kucanski-aparati/blenderi.aspx","children":[{"name":"SMEG","source_url":"/mali-kucanski-aparati/blenderi/smeg-9.aspx","children":[]}]},{"name":"Aparati za kavu","source_url":"/mali-kucanski-aparati/aparati-za-kavu.aspx","children":[{"name":"SMEG","source_url":"/mali-kucanski-aparati/aparati-za-kavu/smeg-6.aspx","children":[]}]},{"name":"Kuhinjske vage","source_url":"/mali-kucanski-aparati/kuhinjske-vage.aspx","children":[{"name":"SMEG","source_url":"/mali-kucanski-aparati/kuhinjske-vage/smeg.aspx","children":[]}]},{"name":"Tosteri","source_url":"/mali-kucanski-aparati/tosteri.aspx","children":[{"name":"SMEG","source_url":"/mali-kucanski-aparati/tosteri/smeg-7.aspx","children":[]}]},{"name":"Kuhinjski robot","source_url":"/mali-kucanski-aparati/kuhinjski-robot.aspx","children":[{"name":"SMEG","source_url":"/mali-kucanski-aparati/kuhinjski-robot/smeg-2.aspx","children":[]}]},{"name":"Set noževa u bloku","source_url":"/mali-kucanski-aparati/set-nozeva-u-bloku.aspx","children":[{"name":"SMEG","source_url":"/mali-kucanski-aparati/set-nozeva-u-bloku/smeg-4.aspx","children":[]}]},{"name":"Pjenilica za mlijeko","source_url":"/mali-kucanski-aparati/pjenilica-za-mlijeko.aspx","children":[{"name":"SMEG","source_url":"/mali-kucanski-aparati/pjenilica-za-mlijeko/smeg-5.aspx","children":[]}]},{"name":"Kuhala za vodu","source_url":"/mali-kucanski-aparati/kuhala-za-vodu.aspx","children":[{"name":"SMEG","source_url":"/mali-kucanski-aparati/kuhala-za-vodu/smeg-8.aspx","children":[]}]},{"name":"Citruseta","source_url":"/mali-kucanski-aparati/citruseta.aspx","children":[{"name":"SMEG","source_url":"/mali-kucanski-aparati/citruseta/smeg-1.aspx","children":[]}]},{"name":"Štapni mikser","source_url":"/mali-kucanski-aparati/stapni-mikser.aspx","children":[{"name":"SMEG","source_url":"/mali-kucanski-aparati/stapni-mikser/smeg-3.aspx","children":[]}]}]},{"name":"Rasvjeta","source_url":"/rasvjeta-1.aspx","children":[{"name":"Unutarnja rasvjeta","source_url":"/rasvjeta-1/unutarnja-rasvjeta.aspx","children":[{"name":"Stropna rasvjeta","source_url":"/rasvjeta-1/unutarnja-rasvjeta/stropna-rasvjeta.aspx","children":[]}]}]}]
JSON, true, 512, JSON_THROW_ON_ERROR);

        $userId = User::query()->value('id');

        DB::transaction(function () use ($tree, $userId): void {
            Category::query()
                ->where('scope', Category::SCOPE_CATALOG)
                ->where('code', 'like', 'termol-%')
                ->update([
                    'is_active' => false,
                    'show_in_menu' => false,
                ]);

            foreach ($tree as $index => $item) {
                $this->upsertBranch($item, null, ($index + 1) * 10, $userId);
            }

            Category::query()->fixTree();

            $settings = app(SystemSettingsService::class);
            $navigationSettings = [];

            if ($settings->get(NavigationMenuService::SETTINGS_KEY, []) === []) {
                $navigationSettings[NavigationMenuService::SETTINGS_KEY] = $this->navigationItems();
            }

            if (! is_array($settings->get(NavigationMenuService::APPEARANCE_SETTINGS_KEY))) {
                $navigationSettings[NavigationMenuService::APPEARANCE_SETTINGS_KEY] = [
                    'container_width' => 1860,
                    'header_content_width' => 1400,
                    'item_height' => 62,
                    'font_size' => 17,
                    'logo_height' => 70,
                    'background_color' => '#e65100',
                    'text_color' => '#ffffff',
                    'highlight_color' => '#ffffff',
                ];
            }

            if (! is_array($settings->get(NavigationMenuService::TOP_BAR_SETTINGS_KEY))) {
                $navigationSettings[NavigationMenuService::TOP_BAR_SETTINGS_KEY] = $this->topBarSettings();
            }

            if ($navigationSettings !== []) {
                $settings->putMany($navigationSettings);
            }
        });
    }

    /**
     * @param array{name:string,source_url:string,children:array<int, array>} $item
     */
    private function upsertBranch(array $item, ?Category $parent, int $sortOrder, ?int $userId): Category
    {
        $sourcePath = '/'.ltrim(trim((string) $item['source_url']), '/');
        $code = 'termol-'.substr(hash('sha256', $sourcePath), 0, 24);
        $now = now();

        $categoryId = Category::query()
            ->where('scope', Category::SCOPE_CATALOG)
            ->where('code', $code)
            ->value('id');

        $payload = [
            'source_url' => 'https://www.termol.hr'.$sourcePath,
            'source' => 'termol.hr',
        ];

        $attributes = [
            'scope' => Category::SCOPE_CATALOG,
            'code' => $code,
            'is_active' => true,
            'show_in_menu' => true,
            'sort_order' => $sortOrder,
            'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'parent_id' => $parent?->id,
            'updated_by' => $userId,
            'updated_at' => $now,
        ];

        if ($categoryId) {
            DB::table('categories')->where('id', $categoryId)->update($attributes);
        } else {
            $categoryId = (int) DB::table('categories')->insertGetId($attributes + [
                'created_by' => $userId,
                'created_at' => $now,
                '_lft' => 0,
                '_rgt' => 0,
            ]);
        }

        $category = Category::query()->findOrFail($categoryId);
        $name = trim((string) $item['name']);
        $slug = $this->localSlug($sourcePath);

        $category->translations()->updateOrCreate(
            [
                'scope' => Category::SCOPE_CATALOG,
                'locale' => 'hr',
            ],
            [
                'name' => $name,
                'slug' => $slug,
                'description' => null,
                'meta_title' => $name,
                'meta_description' => null,
                'payload' => [
                    'source_url' => 'https://www.termol.hr'.$sourcePath,
                ],
            ]
        );

        foreach (($item['children'] ?? []) as $index => $child) {
            $this->upsertBranch($child, $category, ($index + 1) * 10, $userId);
        }

        return $category->refresh();
    }

    private function localSlug(string $sourcePath): string
    {
        $path = preg_replace('/\.aspx$/i', '', parse_url($sourcePath, PHP_URL_PATH) ?: $sourcePath);
        $slug = Str::slug(str_replace('/', ' ', trim((string) $path, '/')));

        return 'termol-'.($slug !== '' ? $slug : substr(hash('sha256', $sourcePath), 0, 24));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function navigationItems(): array
    {
        return [
            [
                'type' => 'catalog',
                'label' => 'Proizvodi',
                'label_translations' => [
                    'hr' => 'Proizvodi',
                    'en' => 'Products',
                    'de' => 'Produkte',
                ],
                'category_id' => 0,
                'page_id' => 0,
                'url' => '/categories',
                'url_translations' => [
                    'hr' => '/categories',
                    'en' => '/categories',
                    'de' => '/categories',
                ],
                'open_in_new_tab' => false,
                'show_dropdown' => true,
                'is_highlighted' => false,
                'is_active' => true,
                'sort_order' => 0,
            ],
            [
                'type' => 'custom',
                'label' => 'Akcija',
                'label_translations' => [
                    'hr' => 'Akcija',
                    'en' => 'Sale',
                    'de' => 'Aktion',
                ],
                'category_id' => 0,
                'page_id' => 0,
                'url' => '/shop?promo_only=1',
                'url_translations' => [
                    'hr' => '/shop?promo_only=1',
                    'en' => '/shop?promo_only=1',
                    'de' => '/shop?promo_only=1',
                ],
                'open_in_new_tab' => false,
                'show_dropdown' => false,
                'is_highlighted' => false,
                'is_active' => true,
                'sort_order' => 10,
            ],
            [
                'type' => 'custom',
                'label' => 'Novo u ponudi',
                'label_translations' => [
                    'hr' => 'Novo u ponudi',
                    'en' => 'New arrivals',
                    'de' => 'Neu im Angebot',
                ],
                'category_id' => 0,
                'page_id' => 0,
                'url' => '/shop?sort=newest',
                'url_translations' => [
                    'hr' => '/shop?sort=newest',
                    'en' => '/shop?sort=newest',
                    'de' => '/shop?sort=newest',
                ],
                'open_in_new_tab' => false,
                'show_dropdown' => false,
                'is_highlighted' => false,
                'is_active' => true,
                'sort_order' => 20,
            ],
            [
                'type' => 'custom',
                'label' => 'Posebna ponuda',
                'label_translations' => [
                    'hr' => 'Posebna ponuda',
                    'en' => 'Special offer',
                    'de' => 'Sonderangebot',
                ],
                'category_id' => 0,
                'page_id' => 0,
                'url' => '/shop?promo_only=1&sort=price_low',
                'url_translations' => [
                    'hr' => '/shop?promo_only=1&sort=price_low',
                    'en' => '/shop?promo_only=1&sort=price_low',
                    'de' => '/shop?promo_only=1&sort=price_low',
                ],
                'open_in_new_tab' => false,
                'show_dropdown' => false,
                'is_highlighted' => true,
                'is_active' => true,
                'sort_order' => 30,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function topBarSettings(): array
    {
        $links = [
            ['Početna', 'https://www.termol.hr/'],
            ['Novosti', 'https://www.termol.hr/Novosti.aspx?newsmodule=3C0EB913-815B-433D-B37C-4056BD338D8D'],
            ['Newsletter', 'https://www.termol.hr/Newsletter.aspx'],
            ['O nama', 'https://www.termol.hr/o-nama.aspx'],
            ['Kontakt', 'https://www.termol.hr/kontakt.aspx'],
            ['Načini plaćanja', 'https://www.termol.hr/nacini-placanja.aspx'],
            ['Načini dostave', 'https://www.termol.hr/nacini-dostave.aspx'],
            ['Uvjeti korištenja', 'https://www.termol.hr/uvjeti-koristenja.aspx'],
            ['Privatnost podataka', 'https://www.termol.hr/privatnost-podataka.aspx'],
        ];

        return [
            'is_enabled' => true,
            'height' => 34,
            'font_size' => 13,
            'background_color' => '#eeeeee',
            'text_color' => '#303030',
            'border_color' => '#0057c8',
            'links' => collect($links)
                ->map(fn (array $link, int $index): array => [
                    'label' => $link[0],
                    'url' => $link[1],
                    'open_in_new_tab' => true,
                    'is_active' => true,
                    'sort_order' => $index * 10,
                ])
                ->all(),
            'socials' => [
                [
                    'network' => 'facebook',
                    'url' => 'https://www.facebook.com/termoldoo/',
                    'is_active' => true,
                    'sort_order' => 0,
                ],
                [
                    'network' => 'youtube',
                    'url' => 'https://www.youtube.com/channel/UCXZ13uQmTVvnVZvhhmPjMvQ',
                    'is_active' => true,
                    'sort_order' => 10,
                ],
                [
                    'network' => 'instagram',
                    'url' => 'https://www.instagram.com/termol_vinkovci/?hl=hr',
                    'is_active' => true,
                    'sort_order' => 20,
                ],
            ],
        ];
    }
}
