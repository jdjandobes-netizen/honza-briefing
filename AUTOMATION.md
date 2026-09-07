# Pokyny pro ranní a odpolední automatizaci

Repozitář: `jdjandobes-netizen/honza-briefing`  
Větev: `main`  
Časové pásmo: `Europe/Prague`  
Zápis: pouze připojený GitHub connector; nikdy SSH, token, lokální git ani Claude Artifact.

## Neměnné pravidlo archivu

Každé vydání je samostatný, plný a neměnný JSON:

- `data/archive/YYYY-MM-DD-morning.json`
- `data/archive/YYYY-MM-DD-afternoon.json`

`data/archive/index.json` je manifest všech vydání a `data/current.json` je pouze malý ukazatel na nejnovější vydání. Starší archivní soubory ani položky manifestu se nikdy nemažou a manifest se nikdy neomezuje jen na sedm dní. Frontend z úplného manifestu zobrazuje nejméně posledních sedm kalendářních dní.

Automatický opakovaný běh nesmí přepsat již existující archivní soubor stejného dne a typu. Pokud soubor existuje, znovu ho načti, ověř a skonči jako idempotentní úspěch. Oprava vydání vyžaduje výslovné ruční zadání.

## Soubory a kontrakty

### Plné vydání

Archivní soubor zachovává `schemaVersion: 1` a dosavadní obsahový kontrakt:

- `publication.edition`: `morning` nebo `afternoon`
- `publication.title`: `Ranní přehled` nebo `Odpolední přehled`
- `publication.kicker`: `Denní briefing pro Honzu`
- `publication.date`: české datum
- `publication.generatedAt`: čas sestavení v Europe/Prague
- `publication.readingMinutes`: celé číslo
- `publication.nextEdition`: text s časem dalšího vydání
- `publication.intro`: jeden úsporný souhrnný odstavec
- `sourceStatus[]`: `{ "name": string, "status": "ok"|"warning"|"error" }`
- `topStories[]`: nejvýše tři položky `{ "title", "summary", "source": { "name", "url" } }`
- `sections[]`: přesně sekce `cesko`, `evropa`, `svet`, `japonsko`, `tech`, `investice` v tomto pořadí
- `sections[].items[]`: `{ "title", "summary", "source": { "name", "url" }, "time"?, "tag"? }`
- `sections[].minor[]`: `{ "text", "source": { "name", "url" } }`
- `vwce.metrics[].tone`: `good`, `bad` nebo `muted`
- `podcasts.shows[]`: `{ "show", "title", "url", "date", "status": "NOVÉ"|"BEZE ZMĚNY" }`
- `podcasts.recommendations[]`: `{ "show", "title", "url", "date" }`
- `footer.sources[]`: názvy skutečně použitých zdrojů

Texty jsou prostý text. Každá zpráva a minor položka musí mít `source.name` a absolutní `https://` URL. Do JSON nevkládej HTML ani Markdown.

## Povinná sekce `japonsko`

Sekce `japonsko` je povinná v KAŽDÉM ranním i odpoledním vydání a má title `Japonsko`. Je prioritní kvůli Honzově cestě do Japonska. Zaměř se hlavně na Tokio/Kantó a Tóhoku včetně Aomori, ale vždy zkontroluj i celostátní rizika, která mohou ovlivnit dopravu nebo bezpečnost cestování.

Priorita obsahu, v tomto pořadí:

1. **Počasí na 24–72 hodin a výhled na několik dní**: Tokio/Kantó, Tóhoku/Aomori a další regiony s významnou odchylkou nebo rizikem. Uváděj déšť, přívalové srážky, vítr, vedro/chlad, sníh podle sezóny, pravděpodobné časové okno a praktický dopad na přesuny.
2. **JMA warnings/advisories**: silný déšť, povodně, sesuvy půdy, bouřky, vítr, vysoké vlny, sníh, extrémní teploty. Vždy rozlišuj mezi skutečně platným warning/advisory a pouhým meteorologickým rizikem nebo výhledem.
3. **Tajfuny a tropické systémy**: pokud existuje aktivní tropická bouře/tajfun nebo relevantní disturbance, uveď oficiální JMA označení/číslo, polohu, směr, očekávaný vývoj, pravděpodobné oblasti dopadu a nejistotu. Pokud není žádný systém relevantní pro Japonsko, napiš to otevřeně.
4. **Zemětřesení, tsunami, sopky**: zkontroluj JMA earthquake/tsunami/volcanic informace. Drobné běžné otřesy uváděj jen pokud mají cestovní nebo bezpečnostní význam; významné zemětřesení, tsunami advisory/warning nebo zvýšení sopečného stupně vždy patří mezi hlavní položky.
5. **Evakuační a krizová připravenost**: při relevantním riziku vysvětli, zda místní úřady vydaly evacuation information/orders, jaká úroveň rizika platí a co to prakticky znamená. Pokud se teprve připravují přístřešky, preventivně ruší doprava, zavírají školy nebo probíhají jiné přípravy, uveď to ještě před samotnou katastrofou.
6. **Doprava a cestování**: pokud počasí nebo katastrofa ovlivňuje Shinkansen/JR, letiště, trajekty, hlavní silnice nebo turistická místa, uveď konkrétní dopad a oficiální dopravní zdroj, pokud je dostupný.
7. **Kultura, zajímavosti a folklor**: jako druhou vrstvu přidej aktuální festivaly, sezonní tradice, kulturní události, folklor, přírodní fenomény nebo zajímavosti vhodné pro cestovatele. Patří hlavně do `minor`, pokud nejde o významnou událost.

Preferované zdroje pro Japonsko:

- Japan Meteorological Agency (JMA) `jma.go.jp` — absolutní priorita pro počasí, warningy, tajfuny, zemětřesení, tsunami a sopky.
- NHK WORLD-JAPAN a NHK pro dopady, evakuace, dopravu a místní situaci.
- Cabinet Office / Disaster Management Japan a příslušné prefekturní či městské úřady pro evakuační informace a krizovou připravenost.
- MLIT, JR East/JR další společnosti, letiště a oficiální dopravci pro omezení dopravy.
- Reuters / Kyodo / The Japan Times / další důvěryhodné zdroje pro širší kontext, nikdy však místo JMA u meteorologického nebo seismického tvrzení, pokud je JMA dostupná.

Ranní `japonsko`: standardně 5–8 hlavních položek, pokud je dost relevantního materiálu, a 3–8 `minor`; první položka má být stručný praktický bezpečnostní přehled pro cestovatele. Pokud nejsou aktivní warnings, nevyplňuj katastrofickou vatou — výslovně uveď, že pro sledované oblasti nejsou zjištěna zásadní aktivní varování, a pokračuj skutečným výhledem počasí a cestovními informacemi.

Odpolední `japonsko`: porovnej s ranní sekcí a publikuj NEW/UPDATE pro nové warningy, změny trajektorie tajfunu, posuny srážek/větru, nová zemětřesení s významem, změny evakuační úrovně a nové dopravní dopady. I pokud se nic zásadního nezměnilo, kvůli bezpečnosti cestování může sekce obsahovat jednu stručnou statusovou položku typu „bez nového zásadního varování“ s aktuálním JMA zdrojem; jinak neopakuj celé ranní znění.

## `data/archive/index.json`

Manifest má `schemaVersion: 1`, `kind: "briefing-archive-index"`, `timezone: "Europe/Prague"`, `retention.visibleCalendarDays: 7`, `retention.deleteOlder: false`, objekt `latest` a pole `editions`.

Každá položka `editions[]` má právě:

`id`, `date` ve formátu `YYYY-MM-DD`, `edition`, `title`, `generatedAt` a `path` ve tvaru `data/archive/YYYY-MM-DD-{edition}.json`.

ID je `${date}-${edition}`. Položky jsou unikátní a seřazené od nejnovějšího data; v témže dni je odpoledne před ránem. Při publikaci přidej jednu novou položku, zachovej všechny starší beze změny a nastav `latest` na nové vydání.

### `data/current.json`

Ukazatel má pouze:

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

Do `current.json` už nikdy nevkládej celé vydání.

## Atomický zápis přes GitHub connector

Publikace musí vzniknout jedním commitem, aby frontend nikdy neviděl napůl aktualizovaný stav.

1. Načti aktuální `main`, jeho commit a base tree. Načti `data/archive/index.json` a `data/current.json`.
2. Ověř, že cílový archivní soubor ještě neexistuje. Při existenci použij idempotentní postup popsaný výše.
3. Lokálně v paměti sestav a validuj tři kompletní obsahy: nový archivní JSON, nový úplný manifest se všemi staršími položkami a nový pointer.
4. GitHub `create_blob` použij pro všechny tři obsahy.
5. GitHub `create_tree` zavolej s base tree a třemi položkami `mode: "100644"`, `type: "blob"`, `path` a příslušným blob SHA.
6. GitHub `create_commit` vytvoř s aktuálním commitem `main` jako jediným rodičem. Potom `update_ref` posuň `main` bez `force`.
7. Při konfliktu kvůli souběžné změně znovu načti `main` a manifest, změny bezpečně slouč a vytvoř nový fast-forward commit. Nikdy nepoužívej force push.
8. Po zápisu znovu načti všechny tři soubory z `main`. Ověř shodné `id`, `date`, `edition` a `path`, přítomnost všech starších položek manifestu a to, že archivní JSON má platný obsahový kontrakt.

Pokud kterýkoli krok selže, nepředstírej publikaci. Protože se ref posouvá až po přípravě celého commitu, poslední funkční stav musí zůstat dostupný.

## Ranní běh v 7:00

1. Urči dnešní datum v `Europe/Prague` a cílové ID `${date}-morning`.
2. Proveď úplnou ranní rešerši za posledních přibližně 24 hodin podle promptu automatizace. Nejdřív vytvoř široký zásobník kandidátů, potom deduplikuj, určuj důležitost a rozděl hlavní a méně důležité zprávy.
3. Povinně zpracuj detailní sekci `japonsko` podle pravidel výše, s JMA jako primárním zdrojem a důrazem na Tokio/Kantó a Tóhoku/Aomori.
4. Sestav plné vydání s `publication.edition: "morning"` a cestou `data/archive/${date}-morning.json`.
5. Atomicky publikuj archivní soubor, úplný manifest a pointer podle postupu výše.
6. Ověř, že frontendový pointer i `latest` míří na ranní archiv a že dnešní ranní soubor zůstal samostatně dostupný.

## Odpolední běh v 16:30

1. Urči dnešní datum v `Europe/Prague` a nejdřív načti výhradně `data/archive/${date}-morning.json` jako porovnávací základ. `data/current.json` k porovnání nepoužívej, protože může ukazovat na jiné vydání.
2. Pokud dnešní ranní archiv chybí nebo je neplatný, zastav publikaci a přiznej chybu; nevyráběj neověřenou deltu.
3. Proveď široký sběr událostí od ranního času. Publikuj jen `NEW` a skutečné `UPDATE`; nezměněná témata neopakuj. Kde se nic zásadního nezměnilo, použij `emptyMessage: "Od rána bez zásadní změny."`.
4. Povinně aktualizuj `japonsko` podle pravidel výše; v bezpečnostních tématech kontroluj JMA znovu, i když se globální zpravodajství nezměnilo.
5. Sestav plné vydání s `publication.edition: "afternoon"` a cestou `data/archive/${date}-afternoon.json`. Ranní archiv nijak neměň.
6. Atomicky publikuj odpolední archiv, úplný manifest a pointer. Manifest musí i po zápisu obsahovat ranní vydání stejného dne a všechna starší vydání.
7. Ověř obě dnešní archivní cesty, přepnutí `latest/current` na odpoledne a shodu odpoledního vydání s ranním porovnávacím základem.

## Kontrola obsahu před commitem

- Česko, Evropa a Svět mají při běžně živém dni přibližně 4–6 hlavních a 3–8 méně důležitých zpráv; relevantní témata nevyřazuj jen kvůli stručnosti.
- Japonsko má samostatně detailní bezpečnostní a cestovní pokrytí; aktivní JMA warning, tajfun, významné zemětřesení/tsunami/sopečná změna nebo evakuační příprava nesmí být vynechána kvůli stručnosti.
- Tech a Investice mají obvykle 2–5 skutečně relevantních položek; objem nevyráběj vatou.
- Každá zpráva patří právě do jedné sekce a každý odkaz musí být ověřený.
- Ranní a odpolední soubor jsou samostatné historické artefakty. `current.json` je jen pointer a neslouží jako historie ani jako odpolední porovnávací základ.

## Příprava pro podcast (ranní i odpolední běh)

Ke každé nové položce `topStories`, `sections[].items` a `sections[].minor` přidej
volitelné pole `"narration": { "audioTag": "serious" }`. Vol podle obsahu jeden z
`serious`, `calm`, `curious`, `warm`, `excited`. Tragédie, válka, oběti a citlivé
zprávy vždy `serious`; žádný smích, jásot nebo dramatizace. Pozitivní úspěch může
mít střídmé `excited`, technologie `curious`. Značky nevkládej do titulku či shrnutí:
samostatný scénář služby je vykreslí jako `[serious]` před čteným textem.

Nepřepisuj kvůli značkám žádný již existující archiv. U starších vydání služba sama
doplní bezpečný přednes. Ranní a odpolední běh **nevolají placené TTS**. To začne
výhradně po tlačítku uživatele. Nezapisuj API klíče ani audio do tohoto repozitáře.
Služba na `briefing.nacestach.online` si načítá stejný GitHub archiv; publikace se nemění.

## Web Push – upozornění na nové psané vydání

Push neposílej, dokud `data/push-config.json` nemá `enabled: true` a není samostatně ověřený odesílací backend. Selhání push nesmí vrátit ani přepsat již publikovaný commit; uvede se pouze jako samostatná chyba doručení.
