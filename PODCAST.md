# Podcast na Webglobe

Zpravodajství se dál publikuje do neměnného archivu v GitHubu. Web na
`https://briefing.nacestach.online` čte tentýž archiv přímo z GitHubu; není nutné
kopírovat vydání na hosting ani měnit časy automatizací. GitHub Pages zůstává čtečkou.

## Chování

- Tlačítko **Připrav podcast** je jediný začátek placeného generování.
- Každé ID vydání má jednu uloženou nahrávku, zdrojový snapshot, scénář a kapitoly.
  Opakované kliknutí vrátí existující úlohu. Změna hlasu platí jen pro nové podcasty.
- Čtený scénář zahrnuje TOP, všechny hlavní i menší zprávy, VWCE i podcastové tipy.
  Značky jsou oddělené od psaných zpráv a viditelné pod „Scénář s přednesem“.
  U starých vydání vzniknou bez přepisování archivu; nové mohou dodat `narration.audioTag`.
- Gemini dostává krátké části, stejný hlas a režijní pokyny. Česky čte fakta, nikoliv
  URL a režijní značky. Tragické zprávy mají klidný vážný přednes.
- Hotové MP3, scénáře a zdroje se nemažou. Po dokončení se uvolní pouze pracovní PCM.
  Přehrávač má kapitoly, rychlost, navázání na místo poslechu a stažení MP3.
- Pokud odpověď při placeném požadavku zmizí, není možné zaručit přesně jedno
  účtování u poskytovatele. Služba se zastaví a další pokus vyžaduje potvrzení.
  Již uložené části se nekupují znovu.

## Bezpečnost a instalace

PHP 8.2+ (ověřit aktuální Composer lock), curl, mbstring, OpenSSL, PDO SQLite,
CLI plánovač a FFmpeg/libmp3lame. HTTPS je povinné. `proc_open` musí fungovat v CLI.
Veřejný adresář obsahuje pouze frontend, ikony, data a `api/`. `server/`, Composer
závislosti, konfigurace a runtime patří **mimo document root**, ne pouze do skrytého adresáře.

1. Do privátního adresáře umístit `server/` a spustit v něm
   `composer install --no-dev --prefer-dist --no-interaction --no-plugins --no-scripts`.
2. `php server/install.php /absolute/private /absolute/public https://briefing.nacestach.online`
   vytvoří konfiguraci bez přepsání existující. Config obsahuje náhodný šifrovací a
   VAPID klíč, žádný Gemini klíč. API standardně hledá `../private/config.php` relativně
   k public, při jiném rozložení nastavit `BRIEFING_CONFIG` bezpečně na serveru.
3. Jednorázový odkaz je v privátním `setup-url.txt`, platí 24 hodin. Uživatel v něm
   vytvoří heslo (12+ znaků), potom vloží Gemini klíč. Po nastavení je token neplatný.
4. Plánovač spouští každou minutu CLI `php server/worker.php /absolute/private/config.php`.
   Jeden běh vytvoří nanejvýš jednu část. `flock` zabraňuje souběhu. Server může
   pokračovat i po zavření prohlížeče. Doba přípravy závisí také na počtu částí.
5. Nasadit nejdříve do izolovaného testovacího umístění; ověřit testy, HTTP ochrany,
   mobilní UI a plánovač. Produkci zapínat až po konkrétním potvrzení.

Klíč je AES-256-GCM šifrovaný v SQLite; šifrovací klíč je v privátní konfiguraci.
Ochrana kryje únik samotné databáze, ne kompromitovaný hostingový účet. Klíč se
neukládá do GitHubu, URL, localStorage, service-worker cache ani klientských odpovědí.
Audio i nastavení vyžadují přihlášení (HttpOnly, Secure, SameSite Strict), změny také
CSRF token a stejný Origin. Psané zprávy zůstávají veřejné jako dosud.
Nastav také limity a omezení Gemini klíče v Google AI Studio; API cena se může měnit.

## Notifikace

Minishlink Web Push používá vlastní VAPID pár; notifikace obsahuje pouze ID vydání,
nikoliv klíč či články. Zařízení se přihlašuje tlačítkem v nastavení a může se odhlásit.
Na iOS/iPadOS 16.4+ je potřeba instalace na plochu. Push závisí na oprávnění a OS,
není zaručené okamžité doručení. Selhání push neinvaliduje MP3. Doručení se zkouší
nanejvýš pětkrát; nepoužívá se volitelný HTTP Topic (kompatibilita s Apple).

## Ověření a provoz

`php tests/podcast.php [data/archive/YYYY-MM-DD-morning.json]` používá výhradně
syntetické audio. Ověřuje šifrování, tagy, dělení, PCM, idempotenci, zámek a obnovu.
`php tests/http.php` ověřuje skutečné API proti izolovanému lokálnímu PHP serveru.
`php tests/encoding.php /usr/bin/ffmpeg --fixture` ověří skutečné MP3 a připraví
ignorovaný `tests/synthetic.mp3` pro lokální `tests/player.html` (mockované API).
První placené čtení a doručení do skutečného telefonu vyžadují samostatné ověření
uživatelem; syntetický test nepotvrzuje kvalitu české řeči.

Zálohovat **config.php + konzistentní SQLite zálohu + celý runtime/audio** mimo webroot.
Pro živou SQLite použít SQLite Backup API / `VACUUM INTO`, nikoli pouhou kopii otevřeného
DB souboru bez WAL. Doporučena denní záloha a kontrola obnovy. Hosting má omezenou
kapacitu: MP3 při 96 kb/s zabere přibližně 0,72 MB/min; data se nesmí automaticky mazat.
Při nedostatku místa zastavit nová generování a rozšíření úložiště řešit s uživatelem.
Zálohovací úloha není automaticky nainstalovaná tímto kódem.

Oficiální dokumentace:
- https://ai.google.dev/gemini-api/docs/speech-generation
- https://ai.google.dev/gemini-api/docs/generate-content/speech-generation
- https://github.com/web-push-libs/web-push-php
- https://webkit.org/blog/13878/web-push-for-web-apps-on-ios-and-ipados/
