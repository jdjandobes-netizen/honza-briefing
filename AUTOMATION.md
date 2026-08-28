# Pokyny pro plánované úlohy

Repozitář: `jdjandobes-netizen/honza-briefing`  
Cílový soubor: `data/current.json`  
Větev: `main`

## Zápis přes GitHub connector

1. Nejdřív načti `data/current.json` z větve `main` nástrojem GitHub `fetch_file`.
2. Vytvoř kompletní nový obsah JSON. Zachovej `schemaVersion: 1`; nepřidávej HTML a nepoužívej Markdown v textových hodnotách.
3. Ověř, že každá zpravodajská položka má `source.name` a absolutní `https://` URL v `source.url`.
4. Nahraď `data/current.json` nástrojem GitHub `update_file`; vždy předej blob SHA z kroku 1. Nikdy nepoužívej SSH, token, lokální git ani Claude Artifact.
5. Po zápisu znovu načti soubor a ověř `publication.edition`, `publication.generatedAt`, počet TOP zpráv a počet položek v sekcích.
6. Pokud zápis selže, nepředstírej publikaci. Vrať přesný stav a zachovej poslední funkční vydání.

## Datový kontrakt

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

Texty musí být prostý text. Aplikace obsah vykresluje přes bezpečné DOM operace a odkazy přijímá jen s protokolem HTTP(S).

## Rozdíl ráno a odpoledne

- Ráno: úplná orientace za posledních přibližně 24 hodin; u VWCE výslovně uveď, že jde o poslední dostupnou uzávěrku před otevřením XETRY.
- Odpoledne: nejdřív načti ranní `data/current.json`, publikuj pouze nové nebo podstatně změněné události a používej tagy `NEW` a `UPDATE`. Kde se nic zásadního nezměnilo, použij `emptyMessage: "Od rána bez zásadní změny."`.
