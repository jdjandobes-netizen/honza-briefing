# Honzův briefing

Produkční PWA běží výhradně na **Webglobe**:

`https://briefing.nacestach.online/`

GitHub Pages je pouze historická/legacy metoda a není produkční cíl.

## Architektura

Repozitář `jdjandobes-netizen/honza-briefing` slouží jako datový backend a archiv. Produkční frontend na Webglobe načítá publikovaná vydání z GitHub repozitáře. Automatizace proto zapisují data přes GitHub connector, ale uživatelským výsledkem je vždy Webglobe web.

Každé ranní a odpolední vydání je samostatný plný JSON:

- `data/archive/YYYY-MM-DD-morning.json`
- `data/archive/YYYY-MM-DD-afternoon.json`

`data/archive/index.json` uchovává úplný manifest historie a `data/current.json` je pouze pointer na nejnovější vydání. Starší vydání se nemažou.

Publikační trojice archiv + manifest + pointer se zapisuje jedním atomickým GitHub commitem. GitHub je backend dat, nikoli cílový web.

## Obsah

Každé nové vydání má pět sekcí: Česko, Evropa, Svět, Tech a Investice. Součástí zůstávají VWCE a podcasty. Přepínač vydání zpřístupňuje celý zachovaný archiv.

## Hlavní soubory

- `index.html`, `styles.css`, `app.js` — prezentační vrstva používaná na produkci Webglobe
- `data/archive/*.json` — archiv vydání
- `data/archive/index.json` — úplný manifest
- `data/current.json` — pointer
- `AUTOMATION.md` — závazný publikační a obsahový kontrakt
- `manifest.webmanifest`, `service-worker.js`, `assets/brand/` — PWA
- `api/podcast.php`, `podcast.js`, `podcast.css` — podcastová vrstva

## Provoz

Web obsahuje běžné ranní a odpolední briefingy. Cestovní safety modul a jeho nouzové crony jsou ukončené. Oznámení o dokončení podcastu zůstává samostatnou volitelnou funkcí.

## Důležité pro nasazení

Produkční kontrola se provádí na `https://briefing.nacestach.online/`, nikoli na GitHub Pages. U ikon používat `assets/brand/`; veřejná cesta `/icons/` na produkčním Webglobe vrací 404. Při změně PWA assetů ověřit přes HTTPS na cílové Webglobe doméně.

