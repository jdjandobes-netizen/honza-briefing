# Ověření 5. září 2026

Stav: implementace připravená na chráněném TESTu. Produkce není aktivovaná.
Žádný placený Gemini požadavek, skutečný API klíč ani skutečná push subscription.

- PHP 8.4 lint a JavaScript `node --check`: OK.
- Původní archivní ranní vydání: 71 kontrol včetně úplnosti všech zpráv, OK.
- Dnešní odpolední vydání: 59 kontrol, OK; 13 částí / přibližně 9 minut čtení.
- 15 HTTP kontrol: session, login, CSRF, Origin, key write-only, logout, soukromé
  audio, byte range 206/416, odmítnutí generování bez běžícího workeru, traversal: OK.
- Na Webglobe skutečně sestaven MP3 ze dvou syntetických PCM bloků: 20,5 s,
  247 148 B, dvě kapitoly. Opakovaný worker nic nekoupil a MP3 zůstal zachovaný.
- Composer install pod explicitním PHP 8.4 a audit: bez známých advisory.
  Výchozí `/usr/bin/php` hostingu je starší; cron musí použít `/usr/bin/php8.4`.
- TEST anonymous GET/HEAD: 401. S testovacím HTTP přihlášením web/session: 200;
  aplikační settings bez aplikačního přihlášení: 401; config-path: 403;
  server/core.php není ve webrootu: 404.
- UI 360 / 390 / 430 / 1280 px: tmavé schéma, zavřený archiv, bez vodorovného
  přetékání. Přepnutí dne a ranního/odpoledního vydání: OK.
- Syntetický MP3 se v nativním přehrávači načetl s délkou 0:20. Ovládání
  kapitol a rychlosti je zobrazené a volitelné i na 360 px. HTTP seeking testován
  samostatně; český hlas ani reálný mobilní background playback nebyly ověřeny.

Před produkčním přijetím: explicitní souhlas s aktivací a režimem veřejnosti,
produkční privátní konfigurace, pravidelný CLI worker + ověřený heartbeat,
uživatelské heslo a vlastní klíč přes zabezpečené nastavení; první poslech a push
na skutečném telefonu se souhlasem k placenému požadavku. Zajistit zálohování a
kapacitu úložiště. TEST runtime se do produkce nekopíruje.
