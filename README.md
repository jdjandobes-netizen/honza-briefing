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
- `data/push-config.json` — bezpečný přepínač a veřejná konfigurace Web Push; zůstává vypnutý, dokud není připojen odesílací backend
- `AUTOMATION.md` — závazný datový kontrakt a publikační postup

## Web Push

PWA už obsahuje příjem `push` událostí, systémovou notifikaci a otevření aktuálního vydání po klepnutí. Po připojení backendu stačí v `data/push-config.json` nastavit `enabled`, HTTPS `subscribeEndpoint` a veřejný VAPID klíč. Teprve potom se v aplikaci objeví tlačítko **Zapnout push**; souhlas prohlížeče se vyžádá výhradně po klepnutí uživatele.

GitHub Pages je statický hosting, proto sám nemůže bezpečně ukládat odběry ani držet privátní VAPID klíč. Backend musí mít dva úkoly: přijmout a uložit `PushSubscription` a po úspěšném vydání rozeslat payload s titulkem „Briefing je ready“ a odkazem na PWA.

## Lokální kontrola

Spusťte libovolný statický HTTP server v kořeni repozitáře. Přímé otevření přes `file://` neověří service worker ani chování PWA.
