# Nouzový režim Japonsko — Webglobe

Produkční web: `https://briefing.nacestach.online/`.

## Co je připraveno

- `api/emergency-poll.php` — serverový poller oficiálních japonských feedů.
- `data/emergency-current.json` — malý atomicky přepisovaný live stav, oddělený od historických briefingů.
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

JMA uvádí, že high-frequency feed je aktualizovaný každou minutu a obsahuje minimálně posledních 10 minut zpráv. Při potřebě smluvně rychlého/spolehlivého doručení použij JMBSC nebo licencovaného poskytovatele jako redundantní druhý kanál.

## Aktivace na Webglobe

1. Nahraj na produkční hosting nové/změněné soubory z repozitáře (`api/emergency-poll.php`, `api/.htaccess`, `data/emergency-current.json`, `data/japan-itinerary.json`, `japan-safety.js`, `japan-safety.css`, aktualizovaný `index.html` a `service-worker.js`).
2. Ve WebAdminu otevři `Hosting → Web → Cron`.
3. Přidej volání `https://briefing.nacestach.online/api/emergency-poll.php` v intervalu 5 minut (cron ekvivalent `*/5 * * * *`).
4. Při prvním týdnu zapni zasílání výstupu cron úlohy e-mailem, ať je vidět případné selhání JMA/XML parseru.
5. Skript standardně povoluje zdrojové IP Webglobe cron serverů: `62.109.128.59`, `212.57.32.9`, `62.109.150.10`, `212.57.32.162`. Alternativně lze na serveru nastavit environment `BRIEFING_EMERGENCY_TOKEN` a volat endpoint s `?token=...`; token nikdy neukládej do GitHubu.
6. Ověř přes HTTPS, že `data/emergency-current.json` dostává `generatedAt` maximálně ~5–10 minut staré a že endpoint vrací `ok:true`.

## Bezpečnostní zásady

Tento kanál je doplněk, ne náhrada JMA/J-Alert/Safety Tips. Neodvozuj z absence položky v JSONu, že nebezpečí neexistuje. Při konfliktu informací má přednost JMA, místní samospráva a pokyn personálu na místě.

JMA upozorňuje na limit 10 GB/den z jedné IP pro veřejné XML; poller proto používá jen high-frequency feedy a omezuje počet detailních XML zpráv na jeden běh.
