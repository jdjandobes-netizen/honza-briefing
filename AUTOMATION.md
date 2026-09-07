# Pokyny pro ranní a odpolední automatizaci

Repozitář dat: `jdjandobes-netizen/honza-briefing`  
Větev: `main`  
Časové pásmo: `Europe/Prague`  
Produkční web: `https://briefing.nacestach.online/` (Webglobe)

## Produkční cíl — závazné

**Jediným uživatelským produkčním cílem je Webglobe na `https://briefing.nacestach.online/`.**

GitHub Pages je stará/legacy metoda. Nikdy ho nepoužívej jako cílový web, nikdy jej neuváděj v závěrečné zprávě a nikdy netvrď, že publikace na GitHub Pages je produkční publikace.

Repozitář GitHub zůstává pouze datovým backendem a archivem. Produkční frontend na Webglobe načítá archivní JSONy z tohoto repozitáře. Zápis dat proto nadále probíhá přes připojený GitHub connector, ale výsledkem pro uživatele je vždy Webglobe web. Nikdy SSH, osobní token, lokální git ani Claude Artifact.

Nevytvářej žádné boční „živé“ soubory typu `data/japan-current.json` nebo jiné sidecary s běžným briefingovým obsahem. Produkční Webglobe vykresluje normální archivní vydání; veškerý obsah briefingu včetně Japonska musí být přímo uvnitř archivního JSON daného vydání. Výjimkou je pouze samostatný nouzový live kanál `data/emergency-current.json`, který není historickým vydáním a je spravovaný serverovým cronem na Webglobe.

## Neměnné pravidlo archivu

Každé vydání je samostatný plný JSON:

- `data/archive/YYYY-MM-DD-morning.json`
- `data/archive/YYYY-MM-DD-afternoon.json`

`data/archive/index.json` je úplný manifest všech vydání. `data/current.json` je pouze pointer na nejnovější vydání. Starší archivní soubory ani položky manifestu se nikdy nemažou a manifest se nikdy neomezuje jen na sedm dní; frontend může zobrazovat kratší výřez, ale data zůstávají zachována.

Automatický opakovaný běh nesmí přepsat již existující archiv stejného dne a typu. Pokud existuje, ověř jej a skonči idempotentně. Oprava již existujícího vydání je povolena jen při výslovném ručním zadání uživatele.

## Obsahový kontrakt vydání

Archivní soubor používá `schemaVersion: 1`.

Povinné:

- `publication.edition`: `morning` nebo `afternoon`
- `publication.title`: `Ranní přehled` nebo `Odpolední přehled`
- `publication.kicker`: `Denní briefing pro Honzu`
- `publication.date`: české datum
- `publication.generatedAt`: čas sestavení v Europe/Prague
- `publication.readingMinutes`: celé číslo
- `publication.nextEdition`
- `publication.intro`
- `sourceStatus[]`: `{ "name": string, "status": "ok"|"warning"|"error" }`
- `topStories[]`: nejvýše 3 položky
- `sections[]`: **přesně 6 sekcí v pořadí** `cesko`, `evropa`, `svet`, `japonsko`, `tech`, `investice`
- `sections[].items[]`: `{ "title", "summary", "source": { "name", "url" }, "time"?, "tag"?, "narration"? }`
- `sections[].minor[]`: `{ "text", "source": { "name", "url" }, "narration"? }`
- `vwce`
- `podcasts`
- `footer.sources[]`

Všechny texty jsou prostý text bez HTML/Markdownu. Každá hlavní i minor zpráva má `source.name` a absolutní `https://` URL.

### Narration

Nové zprávy mohou mít `narration.audioTag`: `serious`, `calm`, `curious`, `warm`, `excited`. Tragédie, válka, oběti a citlivé zprávy vždy `serious`. TTS se při automatickém běhu nevolá; audio vzniká až po akci uživatele.

## Rešeršní metoda

Vždy dva průchody:

1. **ŠÍŘKA** — nejdřív vytvoř širší zásobu kandidátů; neukončuj rešerši po prvních 2–3 zprávách.
2. **VÝBĚR** — až potom řaď podle významu, odstraň duplicity a rozděl hlavní/minor.

Česko: ČT24, Seznam Zprávy, Deník N/R5M, iROZHLAS, Novinky, Reflex.  
Evropa/Svět: Reuters, Euronews, Al Jazeera, CNN, NPR a povolené NYT newslettery z Gmailu.  
Tech: Reuters a další důvěryhodné technologické zdroje.  
Nevyžaduj dvě média pro každý fakt, ale každý použitý fakt musí mít ověřitelný zdroj.

### Gmail / NYT

Na začátku obsahu vyhledej `from:(nytimes.com) is:unread newer_than:2d`. Čti pouze: The Morning, The World, Today's Headlines, On Politics, Opinion Today, Breaking News, New York Today, The Weekender. Každý přečtený se pokus označit jako přečtený. Jiné e-maily neměň. NYT nikdy nečti z webu.

### Hustota

Ráno: Česko/Evropa/Svět standardně 4–6 hlavních + 3–8 minor, pokud je dost relevantního dění. Tech/Investice obvykle 2–5 skutečně relevantních položek, bez vaty.

Odpoledne: Česko/Evropa/Svět standardně 3–5 hlavních NEW/UPDATE + 2–6 minor, pokud takové změny od rána existují. Tech/Investice 1–4 relevantní NEW/UPDATE. Pokud se nic zásadního nezměnilo, použij `items:[]`, `minor:[]`, `emptyMessage:"Od rána bez zásadní změny."`.

Každá zpráva patří právě do jedné sekce. TOP 3 je pouze nadstavbový souhrn a může odkazovat na stejné téma jako detailní sekce.

## Povinná sekce `japonsko`

Sekce `japonsko` je povinná v každém ranním i odpoledním vydání a je prioritní kvůli Honzově cestě do Japonska. Hlavní fokus: Tokio/Kantó a Tóhoku včetně Aomori; zároveň vždy zkontroluj celostátní rizika, která mohou ovlivnit bezpečnost nebo dopravu.

Priorita:

1. **Počasí 24–72 h + několikadenní trend** — déšť, přívalové srážky, bouřky, vítr, vedro/chlad, sníh podle sezóny, časové okno a praktický dopad.
2. **JMA warnings/advisories** — jasně odliš skutečně platný warning/advisory od pouhého rizika či výhledu.
3. **Tajfuny a tropické systémy** — JMA číslo/název, poloha, směr, očekávaný vývoj, oblasti dopadu a nejistota. Pokud není relevantní systém, napiš to otevřeně.
4. **Povodně a sesuvy** — včetně nasycení půdy po předchozích srážkách a lokálních rizik.
5. **Zemětřesení, tsunami, sopky** — JMA earthquake/tsunami/volcanic informace; drobné běžné otřesy jen s cestovním významem, významná varování vždy hlavní položka.
6. **Evakuační a krizová připravenost** — evacuation information/orders, úroveň rizika, otevření přístřešků, preventivní uzávěry, školy, místní přípravy.
7. **Doprava** — Shinkansen/JR, letiště, trajekty, hlavní silnice a turistická místa, pokud jsou ovlivněna.
8. **Kultura, folklor, zajímavosti** — festivaly, sezonní tradice, kulturní události, přírodní fenomény; typicky `minor`.

Zdroje pro Japonsko:

- JMA `jma.go.jp` — absolutní priorita pro počasí, warnings, tajfuny, zemětřesení, tsunami a sopky.
- NHK WORLD-JAPAN / NHK — dopady, evakuace, doprava a místní situace.
- Cabinet Office / Disaster Management Japan a prefekturní/městské úřady — evakuace a krizová připravenost.
- MLIT, JR, letiště a další oficiální dopravci — dopravní dopady.
- Reuters, Kyodo, The Japan Times a další důvěryhodné zdroje pro kontext; nikdy ne místo JMA u meteorologického/seismického tvrzení, pokud je JMA dostupná.

Ranní Japonsko: standardně 5–8 hlavních položek + 3–8 minor, pokud je materiál. První položka má být praktický safety status. Pokud nejsou aktivní zásadní warnings, napiš to výslovně a pokračuj reálným výhledem a cestovními informacemi.

Odpolední Japonsko: vždy zkontroluj znovu. Porovnej s ranní sekcí a publikuj NEW/UPDATE pro nové warningy, změny trajektorie systému, posuny srážek/větru, povodně/sesuvy, významná zemětřesení, evakuační změny a dopravu. Pokud se nic zásadního nezměnilo, je dovolena jedna stručná aktuální statusová položka s JMA zdrojem; neopakuj celé ranní znění.

### Itinerářový safety kontrakt — závazný

**Před každým ranním i odpoledním během načti také `JAPAN_SAFETY.md` a `data/japan-itinerary.json` z aktuálního `main` a řiď se jimi.** Tento doplněk je součástí obsahového kontraktu stejně jako tento soubor. Manuální prompt nemusí pravidla opakovat; samotné načtení `AUTOMATION.md` znamená povinnost načíst i tyto dva zdroje.

Itinerář 12. 9.–3. 10. 2026 se vyhodnocuje celý, nejen právě aktuální zastávka. Kontroluj i budoucí pobyty, přejezdy, pobřežní úseky, horské silnice, soutěsky a výlety uvedené v `routeHighlights`.

Každá `japonsko.items[]` i `japonsko.minor[]` má povinně:

- `location` nebo `locations` s konkrétní lokalitou, pokud možno GPS a vždy absolutním HTTPS `mapUrl`;
- `itineraryImpact` s `status` přesně `affected`, `watch` nebo `not_affected`, vysvětlením a `affectedStopIds[]`.

Uživatel musí u každé zprávy rovnou vidět pin/mapu a zda je jeho itinerář dotčen.

`japonsko.travel.weatherStops[]` je povinné v KAŽDÉM novém vydání a obsahuje všech 12 zastávek z itineráře ve stejném pořadí. Žádnou nevypouštěj kvůli vzdálenému termínu. Používej `forecastType`: `short-range`, `weekly-outlook`, `seasonal-trend`. Přesnou denní předpověď nikdy nevyráběj za horizontem zdroje; vzdálené zastávky mají místo toho poctivý regionální/týdenní/sezonní trend a nejistotu. S přibližováním termínu detail automaticky zvyšuj.

`japonsko.travel.emergencyGuide` je povinný a obsahuje nouzová čísla, tsunami postup, evakuační úrovně, oficiální evakuační mapy a odkazy na JMA/JNTO Safety Tips podle `JAPAN_SAFETY.md`.

Před commitem ověř itinerářový kontrakt podle logiky `tools/validate-japan.mjs`: přesně 6 sekcí, mapa+impact u každé Japan položky, přesně 12 weatherStops, platné forecastType a emergencyGuide.

## VWCE

IE00BK5BQT80 / VWCE.DE / XETRA. Preferuj StockInvest.us a justETF. Ověř poslední cenu EUR a, pokud lze, Den, Týden, YTD, 1 rok, 52t. maximum. Ráno výslovně uveď poslední dostupnou uzávěrku před otevřením XETRY; odpoledne rozliš live/delayed/close. Pokud metriku nelze ověřit, použij `—`; nepředstírej realtime.

## Podcasty

Ověř Vlevo dole, Ptám se já, Amerika bejby, Vrtěti psem, Padni komu padni. U každého nový díl za ~24 h, jinak poslední díl + datum. Doporučení 3–5 ověřeně živých analytických pořadů z běžné zásoby (např. Bruselský diktát, Kecy a politika, Ve vatě, Studio N, Vinohradská 12, The Daily, The Ezra Klein Show, Odd Lots, Hard Fork, Zaostřeno). Nevymýšlej názvy ani data.

## `data/archive/index.json`

Manifest: `schemaVersion:1`, `kind:"briefing-archive-index"`, `timezone:"Europe/Prague"`, `retention.visibleCalendarDays:7`, `retention.deleteOlder:false`, `latest`, `editions`.

Každá položka `editions[]`: právě `id`, `date`, `edition`, `title`, `generatedAt`, `path`. ID `${date}-${edition}`. Unikátní; řazení od nejnovějšího data, v témže dni odpoledne před ránem. Při novém vydání zachovej všechny starší položky beze změny a přidej právě jednu novou.

## `data/current.json`

Pouze pointer:

```json
{
  "schemaVersion": 1,
  "kind": "briefing-pointer",
  "updatedAt": "ISO-8601 s pražským offsetem",
  "current": {
    "id": "YYYY-MM-DD-morning|afternoon",
    "date": "YYYY-MM-DD",
    "edition": "morning|afternoon",
    "title": "Ranní přehled|Odpolední přehled",
    "generatedAt": "čas sestavení",
    "path": "data/archive/YYYY-MM-DD-morning|afternoon.json"
  }
}
```

Nikdy do něj nevkládej celé vydání.

## Atomický datový zápis přes GitHub

GitHub je pouze backend dat pro produkční Webglobe.

1. Načti aktuální `main`, commit a base tree; načti manifest a pointer.
2. Ověř existenci cílového archivu a idempotenci.
3. V paměti sestav a validuj plný archiv, úplný manifest a pointer.
4. `create_blob` pro všechny tři.
5. `create_tree` s base tree a třemi položkami `mode:"100644"`, `type:"blob"`.
6. `create_commit` s aktuálním main commitem jako jediným rodičem.
7. `update_ref` na `main` bez force.
8. Při konfliktu znovu načti main a bezpečně fast-forward slouč; nikdy force push.
9. Po zápisu znovu načti archiv/index/current a ověř shodu i zachování celé historie.

Nepoužívej `update_file` pro publikační trojici.

## Ranní běh 7:00

Urči datum Europe/Prague, proveď plnou ~24h rešerši, načti povinně `JAPAN_SAFETY.md` a itinerář, vytvoř detailní itinerářové Japonsko, `${date}-morning`, atomicky publikuj datovou trojici a ověř. Produkční URL je pouze `https://briefing.nacestach.online/`.

## Odpolední běh 16:30

Jako jediný porovnávací základ načti přesně `data/archive/${date}-morning.json`; nikdy current pointer. Pokud ranní archiv chybí/neplatný, zastav. Proveď široký sběr od 07:00, publikuj jen NEW/UPDATE, povinně znovu načti `JAPAN_SAFETY.md` a itinerář a zkontroluj Japonsko i všechny weatherStops. Ranní archiv nijak neměň. Atomicky publikuj odpolední datovou trojici a ověř obě dnešní archivní cesty. Produkční URL je pouze `https://briefing.nacestach.online/`.

## Nouzový live kanál a Web Push

`data/emergency-current.json` není historický briefing a denní automatizace jej nepřepisují. Na produkčním Webglobe jej aktualizuje serverový cron z oficiálních JMA/FDMA/Japan Coast Guard feedů podle `EMERGENCY.md`. Frontend může tento soubor pollovat častěji než vznikají briefingy.

Nouzový live kanál je pouze doplněk. Pro životně důležitá rozhodnutí má uživatel vždy následovat JMA/J-Alert, JNTO Safety Tips a aktuální pokyny místních úřadů.

Web Push neposílej, dokud `data/push-config.json` nemá `enabled:true` a není ověřen samostatný push backend. Selhání push nesmí vrátit ani přepsat publikovaný commit ani nouzový JSON.

## Závěrečná zpráva automatizace

Vždy odkazuj pouze na `https://briefing.nacestach.online/`. Nikdy GitHub Pages. Uveď 2–3 TOP věty, stručný Japan safety status včetně nejbližších `affected/watch` zastávek, počet NYT newsletterů přečtených/označených, výsledný commit datového backendu nebo jasnou chybu a nedostupné zdroje.
