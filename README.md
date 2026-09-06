# Honzův briefing

Mobilní PWA pro ranní vydání v 7:00 a odpolední aktualizaci v 16:30 (Europe/Prague). GitHub Pages publikuje stabilní aplikaci a plánované úlohy zapisují vydání přes GitHub connector.

## Publikační model

Každé ranní a odpolední vydání zůstává jako samostatný plný JSON:

- `data/archive/YYYY-MM-DD-morning.json`
- `data/archive/YYYY-MM-DD-afternoon.json`

`data/archive/index.json` uchovává úplný seznam vydání a nic z něj automatizace nemažou. `data/current.json` obsahuje pouze pointer na nejnovější vydání. Frontend nahoře nabízí dostupná ranní a odpolední vydání z posledních sedmi kalendářních dní; starší soubory zůstávají v repozitáři a manifestu.

Ranní automatizace vytvoří nový ranní archiv, doplní manifest a posune pointer. Odpolední automatizace načte ranní archiv stejného dne jako porovnávací základ, vytvoří samostatnou deltu a teprve potom posune manifest a pointer na odpoledne.

Archivní soubor, manifest a pointer se publikují jedním atomickým GitHub commitem. Frontend proto nikdy nemá dostat napůl zapsaný stav. Žádný SSH klíč, FTP heslo, osobní přístupový token ani lokální git není potřeba.

## Soubory

- `index.html`, `styles.css`, `app.js` — stabilní prezentační vrstva a přepínač archivu
- `data/archive/*.json` — neměnná plná ranní a odpolední vydání
- `data/archive/index.json` — úplný manifest historie
- `data/current.json` — pouze ukazatel na nejnovější vydání
- `manifest.webmanifest`, `service-worker.js`, `assets/brand/` — instalace PWA a offline cache navštívených vydání
- `data/push-config.json` — vypnutá veřejná konfigurace Web Push
- `AUTOMATION.md` — závazný datový kontrakt a atomický publikační postup
- `tools/validate-data.mjs` — validace pointeru, manifestu a archivních vydání

## Web Push

PWA obsahuje příjem `push` událostí, systémovou notifikaci a otevření aplikace po klepnutí. `data/push-config.json` zůstává vypnutý, dokud není připojen a ověřen samostatný odesílací backend. GitHub Pages je statický hosting a privátní VAPID klíč ani odběry bezpečně neukládá.

## Lokální kontrola

Spusťte libovolný statický HTTP server v kořeni repozitáře a otevřete stránku přes HTTP. Přímé `file://` neověří načítání JSONů, service worker ani chování PWA.

Validace dat:

```text
node tools/validate-data.mjs
```

Skript bez argumentu ověří pointer, manifest a všechny vydání uvedené v manifestu. Cestu k jednotlivému plnému vydání lze předat jako první argument.

## Ikony prohlížeče a telefonu

Schválené novinové logo je beze změny obrázku publikované v `assets/brand/`.
Nepoužívat veřejnou cestu `/icons/`: na produkčním Webglobe vrací 404,
i když soubory existují (na vnořeném TESTu tento problém není).
Verze je součástí názvu ikon, aby prohlížeč rozpoznal změnu identity PWA.
`apple-touch-icon.png` v kořeni je záložní kopie neprůhledné varianty pro iOS;
HTML odkazuje přímo na verzovanou variantu. Původní `icons/` zůstávají zachované.
Identita manifestu (`id`, `start_url`, `scope`) se při změně ikon nemění.

Kontrola: `node tests/icons.mjs`. Při nasazení ověřit **přes HTTPS na cílové
doméně**, že každá ikona vrací 200, `image/png` a správný kontrolní součet;
samotná existence souborů na disku ani úspěch na TESTu nestačí.
Nahrát nejprve obrázky, pak manifest/HTML a nakonec service worker.
Již nainstalovaná aplikace může vyžadovat potvrzení aktualizace ikony
v prohlížeči nebo nové přidání na plochu telefonu. Tímto vydáním se nemění
přihlášení, push odběry ani uložené podcasty.
