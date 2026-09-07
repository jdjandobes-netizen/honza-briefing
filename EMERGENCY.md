# Nouzový režim Japonsko — Webglobe

Produkční web: `https://briefing.nacestach.online/`.

## Architektura

Nouzový kanál běží odděleně od ranního/odpoledního archivu, aby se historická vydání nepřepisovala a kritické upozornění nemuselo čekat na 7:00 nebo 16:30.

- `api/emergency-poll.php` — každou minutu načte oficiální JMA/FDMA/Japan Coast Guard feedy a atomicky přepíše `data/emergency-current.json`.
- `api/emergency-dispatch.php` — každou minutu porovná fingerprinty událostí s předchozím stavem, AI volá pouze pro NEW/materially UPDATE relevantní události a odešle Web Push podle severity/itineráře.
- `api/push.php` + `push-live.js` — same-origin registrace PWA zařízení k existujícímu privátnímu VAPID/subscription backendu.
- `data/emergency-current.json` — live stav oddělený od historického briefingu.
- `japan-safety.js` — v otevřené aplikaci kontroluje live stav jednou za minutu a zobrazuje aktivní kritická/varovná/advisory upozornění.
- `data/japan-itinerary.json` — 12 zastávek trasy 12. 9.–3. 10. 2026 s GPS body a plánovanými trasami.

## Zdroje polleru

JMA high-frequency Atom feedy (PULL):
- `https://www.data.jma.go.jp/developer/xml/feed/extra.xml`
- `https://www.data.jma.go.jp/developer/xml/feed/eqvol.xml`
- `https://www.data.jma.go.jp/developer/xml/feed/other.xml`

Doplňkové oficiální zdroje:
- FDMA disaster RSS: `https://www.fdma.go.jp/disaster/info/index.xml`
- Japan Coast Guard MICS RSS: `https://www6.kaiho.mlit.go.jp/rss_en.xml`

JMA high-frequency feed je určený pro časté PULL zpracování; nouzový poller je proto nastaven koncepčně na 1 minutu. Při požadavku na smluvně garantované doručení lze přidat JMBSC/licencovaného poskytovatele jako redundantní druhý kanál.

## AI — pouze při události

AI se NESPOUŠTÍ každou minutu. `emergency-dispatch.php` ukládá fingerprint každé události a AI volá pouze tehdy, když je alert nový nebo se materiálně změnil a je kritický nebo relevantní pro itinerář. Používá stávající šifrovaně uložený Gemini API klíč z privátního podcast backendu; pokud klíč není dostupný nebo AI selže, oficiální alert i push dál fungují deterministicky.

AI smí pouze doplnit:
- stručné české vysvětlení;
- `affected/watch/not_affected` pro konkrétní itinerář;
- seznam dotčených `stopIds`;
- krátké praktické doporučení.

AI nesmí přepisovat nebo zlehčovat oficiální JMA instrukce a není single point of failure.

## Push politika

- `critical`: push vždy okamžitě, i při selhání AI;
- `warning`: push pokud `affected` nebo `watch`;
- `advisory`: push pokud `affected`;
- `info`: bez push, pouze live dashboard.

Push používá existující VAPID pár a šifrované subscription úložiště privátního backendu. Fingerprint zajišťuje, že stejný alert ani stejný AI rozbor se neposílá opakovaně. Materially UPDATE může vyvolat nový push.

## Aktivace na Webglobe

1. Na produkční hosting nahraj nové/změněné soubory z repozitáře: `api/emergency-poll.php`, `api/emergency-dispatch.php`, `api/push.php`, `api/.htaccess`, `data/emergency-current.json`, `data/japan-itinerary.json`, `japan-safety.js`, `japan-safety.css`, `push-live.js`, `index.html`, `service-worker.js`.
2. Ve WebAdminu otevři `Hosting → Web → Cron`.
3. Nastav `https://briefing.nacestach.online/api/emergency-poll.php` na každou minutu (`* * * * *` / ekvivalent Webglobe).
4. Nastav `https://briefing.nacestach.online/api/emergency-dispatch.php` také na každou minutu. Pokud oba běhy proběhnou ve stejném okamžiku, dispatch bezpečně zpracuje nový stav nejpozději v následujícím minutovém cyklu.
5. Při prvním týdnu zapni zasílání výstupu cron úloh e-mailem, aby bylo vidět případné selhání feedu/XML/API/push.
6. Oba cron endpointy standardně povolují zdrojové IP Webglobe cron serverů `62.109.128.59`, `212.57.32.9`, `62.109.150.10`, `212.57.32.162`. Alternativně lze na serveru nastavit `BRIEFING_EMERGENCY_TOKEN`; token nikdy neukládej do GitHubu.
7. Ověř, že `data/emergency-current.json` má čerstvé `generatedAt` a po dispatchi `dispatch.processedAt`; běžně mají být maximálně několik minut staré.

## Mobilní PWA / Web Push

Manifest používá `display: standalone`, aplikace registruje service worker a service worker implementuje `push` i `notificationclick`. `push-live.js` po přímém kliknutí uživatele získá VAPID public key z privátního backendu, vyžádá Notification permission, vytvoří PushSubscription a uloží ji na server.

Na iOS/iPadOS je pro Web Push nutné mít PWA přidanou na plochu a otevřít ji z její ikony; oprávnění musí vzniknout po přímé uživatelské akci. Android/Chromium používá standardní Push API/Service Worker flow.

## Bezpečnostní zásady

Tento kanál je doplněk, ne náhrada JMA/J-Alert/Safety Tips. Neodvozuj z absence položky v JSONu, že nebezpečí neexistuje. Při konfliktu informací má přednost JMA, místní samospráva a pokyn personálu na místě.
