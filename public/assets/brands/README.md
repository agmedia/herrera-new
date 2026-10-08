# Herrera brand-strip source assets

Acquired on 2026-10-07. The original image files are kept unchanged. Logos and
trademarks belong to their respective manufacturers; these assets identify
products already present in Herrera's catalog. The storefront serves the files
locally, without third-party logo hotlinks.

| Local original | Official page | Original asset source | Display |
| --- | --- | --- | --- |
| `braytron.svg` | https://braytron.com/ | https://braytron.com/static/core/images/braytron-logo-final-02.svg | Complete official header logo. |
| `struhm.svg` | https://www.struhm.com/ | https://www.struhm.com/Inc/Layout/Images/SVG/Header/Logo_Struhm_Amuled.svg | CSS viewport shows the unchanged STRUHM mark in the upper part of the combined STRUHM/AMULED header file. The original file retains both brands. |
| `wago.svg` | https://www.wago.com/global/ | First inline `svg` inside the official header's `.w-logo` element | Original paths and view box. A bare Vue `data-v-*` attribute is given an empty value so the inline HTML subtree is valid standalone XML. The exact source subtree is preserved in `originals/wago-header.svg`. The site's structured-data PNG URL returns HTML and was not used. |
| `eti.svg` | https://www.etigroup.eu/ | https://www.etigroup.eu/images/logos/eti-logo-safe-future.svg | Complete official header logo. |
| `gewiss.png` | https://www.gewiss.com/ww/en | https://www.gewiss.com/content/dam/gewiss/logo/gewiss-logo.png | Official organization/brand logo referenced in page structured data. Empty margins clipped only by CSS. |
| `midea-share.png` | https://www.midea.com/global | https://www.midea.com/etc.clientlibs/midea-aem/clientlibs/clientlib-site/resources/img/common/midea-logo-share.png | Official logo referenced by page social/organization metadata. Empty margins clipped only by CSS. |
| `portwest.png` | https://www.portwest.com/market/ | https://d11ak7fd9ypfb7.cloudfront.net/com_images/sitewide/portwest-logo-oj.png?2 | Complete official header asset, served by the CDN linked from Portwest's own site. |

## Preserved alternatives

- `midea.webp`: the original current global header logo, acquired from
  https://web-res.midea.com/content/dam/midea-aem/global/official/midea-logo.png/jcr:content/renditions/midea-logo.webp
  (linked by https://www.midea.com/global). It includes a blue background and is
  not displayed in the neutral strip.
- `struhm-legacy.jpeg`: Herrera's pre-existing imported manufacturer-logo source,
  https://herrera.hr/image/catalog/brandovi-logotipi/logo_STRUHM.jpeg. It includes
  the `ideus.pl` tagline and is preserved as a source alternative.
- `uni-trend.png`: original official header logo at
  https://www.uni-trend.com/wp-content/uploads/2021/10/UNIT.png, linked by
  https://www.uni-trend.com/. It is white artwork for the manufacturer's red
  header. It is displayed unchanged on a red inset within its white logo tile.

## Catalog behavior

`config/herrera-brand-logos.php` prioritizes the first seven existing brands by
translated name or slug instead of an environment-specific database ID. The
carousel also includes other active manufacturers with active products and a
verified asset from `config/manufacturer-logo-assets.php` or a usable uploaded
logo. It uses real current/fallback locale manufacturer routes and prefers
uploaded artwork. No manufacturer records or media-library rows were changed.

## Manufacturer directory assets

The directory and manufacturer blocks resolve these additional originals by
translated name or slug, including verified catalog supplier aliases. Usable
uploaded logos take precedence, then local originals, then existing fallbacks.
The homepage carousel uses these local originals after its seven priority brands.

| Catalog label / key | Local original | Source page | Original asset | Notes |
| --- | --- | --- | --- | --- |
| a-plastic | `a-plastic.png` | https://aplusplastic.com/ | https://aplusplastic.com/wp-content/uploads/2023/01/aplus-plastic-logo.png | Official A Plus Plastic & Electric header logo; PVC cable trunking matches Herrera supplier brand. |
| apecs | `apecs.jpg` | https://apecs.com/partners/promo-materialy/ | https://apecs.com/bitrix/templates/apecs/img/logo.jpg | Official APECS desktop header logo, blue background. |
| as-schwabe | `as-schwabe.svg` | https://as-schwabe.de/ | https://as-schwabe.de/wp-content/uploads/2024/12/as-schwabe_logo.svg | Official full header logo. |
| brock | `brock.svg` | https://brockgroup.eu/ | https://brockgroup.eu/images/brock_r_black.svg?crc=4036278528 | Official black header logo. Identity confirmed by official catalog AFD 3502 BK matching Herrera SKU: https://www.brockgroup.eu/download/Catalog_BROCK_2020-21.pdf |
| camelion | `camelion.png` | https://camelion.com/ | https://camelion.de/wp-content/uploads/2024/12/Camelion-Logo.png | Official red transparent Camelion header logo; asset hosted on Camelion Germany and linked from global site. |
| daze | `daze.png` | https://store.daze.eu/ | https://store.daze.eu/cdn/shop/t/2/assets/logo-blue.png?v=125708968866531437361777560947 | Official blue Daze store header logo. |
| dpm | `dpm.png` | https://dpm.eu/ | https://dpm.eu/wp-content/uploads/2026/05/logo-DPM-poziom-e1779805909896.png | Official black transparent DPM logo. |
| emo | `emo.png` | https://www.emos.eu/ | https://www.emos.eu/wp-content/uploads/2026/04/emos-logo-transparent.png | Herrera alias EMO is EMOS: exact SKU K1851 A224F, R5851 and other parts match official EMOS 2025/26 catalogue at https://www.emos.eu/wp-content/uploads/2025/08/emos-catalogue-enu-2025-26-web-prog.pdf. Original includes Legrand ownership tagline. |
| enovalite | `enovalite.jpg` | https://www.profishop.de/enovalite | https://www.profishop.de/media/image/37/df/c3/EN2_ENOVALITE_Logo.jpg | Established supplier brand-logo asset, identity confirmed by ELED codes and manufacturer energy sheet https://www.profishop.de/media/pdf/77/5b/41/Fiche_1553022_EN.pdf. Official enovatek.de responds 500. |
| pawbol | `pawbol.png` | https://www.pawbol.pl/ | https://www.pawbol.pl/wp-content/uploads/2023/05/pawbol-logo_03.png |  |
| mandeks | `mandeks.png` | https://mandeks.ba/ | https://mandeks.ba/wp-content/uploads/2025/04/mandeks-logo-dark.png |  |
| maxpuls | `maxpuls.jpg` | https://www.tehnonis.com/maxpuls-elektricni-motorni-akumulatorski-alat-i-pribor/ | https://www.tehnonis.com/wp-content/uploads/2023/02/maxpuls-alat-logo1.jpg |  |
| esper | `esper.png` | https://esperanza.pl/ | https://esperanza.pl/gfx/1360065809.9399.png | Catalog EBC004 Apollo, EBC005S Gallant, EBC006 Adonis correspond to Esperanza, supported by Electro.pl EBC004 manufacturer listing |
| knauf | `knauf-official.png` | https://tools.knauf.it/richiestaLogo.aspx | https://tools.knauf.it/img_restyling2024/logo-Knauf.png |  |
| master | `master.jpg` | https://masterled.pl/ | https://masterled.pl/images/frontend/theme/argentorwd/_editor/prod/3e70b329b4f3fc55fe11807f0ee50c5b.jpg | Catalog EAN 5904703001324 / sku 0315 matches MasterLED solar light on Allegro and Media Expert. MasterLED contact verified biuro@masterled.pl. |
| tehnoplast | `tehnoplast.svg` | https://tehno-plast.com/ | https://tehno-plast.com/logo.svg |  |
| vayox | `vayox.svg` | https://vayox.pl/ | https://vayox.pl/wp-content/uploads/2026/01/logo.svg |  |
| weicon | `weicon.svg` | https://www.weicon.de/en | https://www.weicon.de/media/2e/97/85/1705399726/logo_weicon_rgb_%286%29.svg?ts=1705399727 |  |
| primo | `primo.png` | https://www.primo-gmbh.com/ | https://www.primo-gmbh.com/wp-content/uploads/2025/05/Primo-Logo_ohne-Text-scaled-scaled.png |  |
| tem | `tem.svg` | https://www.tem-si.com/ | https://www.tem-si.com/wp-content/uploads/2022/08/tem-logo.svg | Official white artwork, use dark background to preserve original color. tem-logo-1.svg same white. Display background: on-dark. |
| toshiba | `toshiba.png` | https://www.toshiba.eu/ | https://www.toshiba.eu/wp-content/uploads/2024/09/site-logo-main.png |  |
| verkatto | `verkatto.jpg` | https://www.verkatto.pl/ | https://www.verkatto.pl/wp-content/uploads/2020/04/logo_verkatto_link.jpg |  |
| videx | `videx.svg` | https://videx.com.pl/ | https://videx.com.pl/design/videx_1/images/logo.svg?v=002 |  |
| plat | `plat.svg` | https://platinet.eu/ | https://www.platinet.eu/wp-content/uploads/2021/08/CompositeLayer.svg | https://platinet.eu/downloads/CATALOG_LAMPS.pdf (PDLQ11 model in official Platinet catalog); PFS5160 also Platinet. |
| uni-trend | `uni-trend.png` | https://meters.uni-trend.com/ | https://meters.uni-trend.com/wp-content/uploads/2021/10/UNIT.png | White official artwork, use red background #da2735. Existing uni-trend.png is identical. Display background: uni-trend. |

### Unconfirmed supplier logos

- **a-sol**: No authentic logo with matching identity found. Herrera products are cable ties with internally assigned 2023113000017 barcodes; search primarily finds unrelated Slovenian solar company or Russian salt logo. Imported manufacturer image empty.
- **bgpl**: No authentic supplier logo found. Catalog mixes DPM OPH-2X120-18W-NW and MARS lighting; BGPL name cannot reliably be mapped to one maker. Imported manufacturer image empty. MARS 36061 EAN 5907612236061 is sold as Berge, another mixed supplier label.
- **candle**: Generic supplier label; no reliable logo identity found from Candle label or ANGELA part names. Imported manufacturer image empty. ANGELA2 EAN 8717847177513 is Bolsius according to matching catalog retail listings, but Candle supplier label is not itself an authenticated Bolsius brand alias; no logo substituted.
- **e-m**: Electrical cabinet supplier label; 1011/1041 KPMO products match generic regional cabinet range but E-M identity and authentic logo unconfirmed. Imported manufacturer image empty.
- **gatlin**: No identifiable official household/toilet-seat brand logo found; no asset rather than unrelated US Gatlin logos.
- **k-m**: Supplier abbreviation in catalog with buzir products, exact supplier identity unclear. No matching authentic brand logo found.
- **k-trade**: Supplier label with generic installation boxes. Original image catalog/00033.png is a product photo; do not use. Exact company identity unresolved.
- **lowenthal**: Catalog household and pet accessory supplier label. Search finds household cookware brand and unrelated people/winery, but no verified matching standalone logo.
- **n-lux**: Supplier label for Serbian switchgear products; no authentic N-Lux logo source confirmed.
- **novalite**: Supplier label for Romanian EL-prefix PVC trunking (EAN 5949027032463); unrelated lighting, IT and Indian polymers brands rejected.
- **omu**: Product identity points to OMU System / XBS (CP805). Official omu.hu blocked by verification and no confirmed standalone logo obtained.
- **tons-form**: Confirmed EURO TOOLS DOOEL Skopje supplier via GS1 GLN 5310216000004 and exact product EAN prefix, but no authentic standalone logo located. Do not substitute Eurotools EU industrial knives or invent typography.

These labels keep their fallback initials until an authentic matching logo is available.
