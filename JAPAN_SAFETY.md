# Japonsko — itinerářový safety modul

Tento soubor rozšiřuje `AUTOMATION.md`. Je závazný pro sekci `japonsko` v ranním i odpoledním briefingu.

## Zdroj itineráře

Před každou rešerší sekce `japonsko` načti `data/japan-itinerary.json`. Je to strojově čitelná kopie cestovního plánu z `https://cesty.nacestach.online/japonsko-tohoku/index.html` pro cestu 12. 9.–3. 10. 2026. Itinerář kontroluj celý, ne pouze aktuální nebo nejbližší zastávku. Vyhodnocuj také plánované přejezdy, horské silnice, pobřežní úseky a výlety uvedené v `routeHighlights`.

## Povinné posouzení každé japonské zprávy

Každá položka `japonsko.items[]` i `japonsko.minor[]` musí mít vedle běžného obsahu:

- `location`: `{ "name", "lat"?, "lng"?, "mapUrl" }` — konkrétní geolokovatelná lokalita nebo reprezentativní bod zasažené oblasti. `mapUrl` musí být absolutní HTTPS odkaz na mapu. U události s více oblastmi lze použít `locations[]` se stejnou strukturou.
- `itineraryImpact`: `{ "status": "affected"|"watch"|"not_affected", "text": string, "affectedStopIds": string[] }`.
- `itineraryImpact.status = "affected"`, pokud událost přímo zasahuje zastávku, plánovaný výlet, silnici, pobřeží, letiště nebo přejezd v relevantním termínu.
- `watch`, pokud je událost mimo okamžitou trasu, ale vývoj může realisticky zasáhnout trasu v dalších dnech (např. tajfun, fronta, povodňová vlna, železniční výluka, sopečný popel).
- `not_affected`, pokud je událost geograficky a časově mimo itinerář. V textu stručně vysvětli proč.

Uživatel musí přímo z každé zprávy poznat: **kde to je, kde je to na mapě a zda to zasahuje jeho cestu**.

## Počasí pro CELÝ itinerář v každém vydání

Sekce `japonsko` musí mít objekt `travel` a v něm `weatherStops[]`. Pole obsahuje právě všechny zastávky z `data/japan-itinerary.json` v pořadí cesty. Žádnou lokalitu nevynechávej ani tehdy, když je vzdálená několik týdnů.

Každá položka `weatherStops[]`:

`{ "stopId", "name", "prefecture", "stay", "lat", "lng", "mapUrl", "forecastType", "validFor", "summary", "temperature"?, "precipitation"?, "wind"?, "hazards"[], "confidence", "source": {"name","url"} }`

Povolené `forecastType`:
- `short-range`: konkrétní denní/několikadenní předpověď v rozumném horizontu JMA;
- `weekly-outlook`: týdenní/regionální výhled, nižší detail;
- `seasonal-trend`: pouze klimatický/sezonní trend pro vzdálenější část cesty.

**Nikdy nevyráběj přesnou denní předpověď za horizontem zdroje.** Vzdálenější zastávka přesto musí zůstat v tabulce a být poctivě označena jako `weekly-outlook` nebo `seasonal-trend`. S přibližováním termínu se automaticky přepne na detailnější zdroj.

Pro první 72 h sleduj i hodinové/časové okno silného deště a bouřek, pokud je dostupné. Pro 4–7 dní uveď denní trend; pro 8–14 dní jen oficiální týdenní výhled/pravděpodobnost; nad 14 dní pouze sezónní trend a hlavní rizika září/října.

## Trasa, ne jen hotely

Pro každý přejezd zvaž minimálně den před přesunem, den přesunu a den po něm. Zvlášť hlídej:

- pobřeží: Matsushima, Kamaishi/Sanriku, Narita/Chōshi — tsunami, vysoké vlny, silný vítr, trajekty/pobřežní komunikace;
- hory a soutěsky: Bandai-Azuma, Zaō, Dewa Sanzan, Oirase/Hakkōda, Iwaki Skyline, Nyutō/Tazawako, Nikkō/Iroha-zaka — sesuvy, uzávěry, vítr, mlha, přívalové srážky, případně sopečné informace;
- JR/Shinkansen a letiště: sleduj oficiální provozní stav při relevantních výstrahách.

## Mapová vrstva

`travel.routeStops[]` může kopírovat základní body itineráře (id, name, lat, lng, mapUrl). Frontend z nich vykreslí přehled trasy nebo odkazy na piny. Každá hlavní/vedlejší zpráva ale musí mít vlastní `location`/`locations` nezávisle na přehledové mapě.

## Nouzová karta — povinně na konci sekce

`travel.emergencyGuide` je povinný a obsahuje minimálně:

- `numbers`: Police 110, Fire/Ambulance 119, Japan Coast Guard 118, JNTO Japan Visitor Hotline 050-3816-2787 a +81-50-3816-2787;
- `tsunami`: vysvětlení, že při Major Tsunami Warning / Tsunami Warning / Tsunami Advisory, siréně nebo červeno-bílé kostkované tsunami vlajce na pobřeží se okamžitě odchází do vyšší polohy nebo do určené tsunami evacuation building; nečekat na pozorování vlny;
- `alertLevels`: Level 2 = zkontrolovat evakuační plán; Level 3 = lidé potřebující více času zahajují evakuaci; Level 4 = všichni z nebezpečné oblasti evakuují; Level 5 = bezprostřední ohrožení, zajistit život okamžitě;
- `evacuation`: odkaz na GSI mapu oficiálních `指定緊急避難場所` (designated emergency evacuation sites) a vysvětlení, že místo musí být určeno pro konkrétní typ nebezpečí (tsunami/povodeň/sesuv atd.); při skutečné události má přednost aktuální pokyn obce/JMA;
- `apps`: JNTO Safety Tips a JMA multilingual;
- `sources[]`: jen oficiální HTTPS zdroje.

## Nouzová data mezi briefingy

Historické briefingy se kvůli live alertům nepřepisují. Produkční Webglobe má samostatný live soubor `data/emergency-current.json`, který vytváří serverový cron z oficiálních feedů. Frontend jej polluje a může zobrazit mimořádný banner nad archivním vydáním.

Priorita live zdrojů:
1. JMA high-frequency Atom `extra.xml` (warnings/advisories), `eqvol.xml` (earthquake/volcano), `other.xml`; `regular.xml` pro relevantní pravidelné informace.
2. Fire and Disaster Management Agency (FDMA) disaster RSS.
3. Japan Coast Guard MICS emergency/coastal RSS pro pobřežní části trasy.
4. Oficiální dopravci (JR East apod.) lze přidat pro cílené kontroly při aktivním riziku.

Nouzový backend musí být konzervativní: raději označit `watch` než tvrdit přímý zásah bez geografického podkladu. Žádný live alert nesmí být vydáván jako jediný životně důležitý kanál; aplikace má vždy doporučit Safety Tips/JMA/local authorities.
