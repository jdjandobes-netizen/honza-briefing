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
- `sections[]`: přesně sekce `cesko`, `evropa`, `svet`, `tech`, `investice`
- `sections[].items[]`: `{ "title", "summary", "source": { "name", "url" }, "time"?, "tag"? }`
- `sections[].minor[]`: `{ "text", "source": { "name", "url" } }`
- `vwce.metrics[].tone`: `good`, `bad` nebo `muted`
- `podcasts.shows[]`: `{ "show", "title", "url", "date", "status": "NOVÉ"|"BEZE ZMĚNY" }`
- `podcasts.recommendations[]`: `{ "show", "title", "url", "date" }`
- `footer.sources[]`: názvy skutečně použitých zdrojů

Texty jsou prostý text. Každá zpráva a minor položka musí mít `source.name` a absolutní `https://` URL. Do JSON nevkládej HTML ani Markdown.

### `data/archive/index.json`

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
3. Sestav plné vydání s `publication.edition: "morning"` a cestou `data/archive/${date}-morning.json`.
4. Atomicky publikuj archivní soubor, úplný manifest a pointer podle postupu výše.
5. Ověř, že frontendový pointer i `latest` míří na ranní archiv a že dnešní ranní soubor zůstal samostatně dostupný.

## Odpolední běh v 16:30

1. Urči dnešní datum v `Europe/Prague` a nejdřív načti výhradně `data/archive/${date}-morning.json` jako porovnávací základ. `data/current.json` k porovnání nepoužívej, protože může ukazovat na jiné vydání.
2. Pokud dnešní ranní archiv chybí nebo je neplatný, zastav publikaci a přiznej chybu; nevyráběj neověřenou deltu.
3. Proveď široký sběr událostí od ranního času. Publikuj jen `NEW` a skutečné `UPDATE`; nezměněná témata neopakuj. Kde se nic zásadního nezměnilo, použij `emptyMessage: "Od rána bez zásadní změny."`.
4. Sestav plné vydání s `publication.edition: "afternoon"` a cestou `data/archive/${date}-afternoon.json`. Ranní archiv nijak neměň.
5. Atomicky publikuj odpolední archiv, úplný manifest a pointer. Manifest musí i po zápisu obsahovat ranní vydání stejného dne a všechna starší vydání.
6. Ověř obě dnešní archivní cesty, přepnutí `latest/current` na odpoledne a shodu odpoledního vydání s ranním porovnávacím základem.

## Kontrola obsahu před commitem

- Česko, Evropa a Svět mají při běžně živém dni přibližně 4–6 hlavních a 3–8 méně důležitých zpráv; relevantní témata nevyřazuj jen kvůli stručnosti.
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
