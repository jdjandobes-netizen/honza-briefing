# Honzův briefing

Mobilní PWA pro ranní vydání v 7:00 a odpolední aktualizaci v 16:30 (Europe/Prague). GitHub Pages publikuje stabilní aplikaci; plánované úlohy mění pouze `data/current.json` přes GitHub connector.

## Publikační model

1. Plánovaná úloha načte zpravodajské zdroje a povolené newslettery.
2. Vytvoří kompletní JSON podle `AUTOMATION.md`.
3. Přes GitHub connector načte `data/current.json`, převezme jeho aktuální blob SHA a nahradí soubor jedním commitem.
4. GitHub Pages změnu automaticky zpřístupní na stále stejné adrese.

Žádný SSH klíč, FTP heslo ani osobní přístupový token není potřeba. Historie vydání zůstává v historii commitů repozitáře.

## Soubory

- `index.html`, `styles.css`, `app.js` — stabilní prezentační vrstva
- `data/current.json` — jediné místo, které mění ranní a odpolední úloha
- `manifest.webmanifest`, `service-worker.js`, `icons/` — instalace PWA a poslední vydání offline
- `AUTOMATION.md` — závazný datový kontrakt a publikační postup

## Lokální kontrola

Spusťte libovolný statický HTTP server v kořeni repozitáře. Přímé otevření přes `file://` neověří service worker ani chování PWA.
