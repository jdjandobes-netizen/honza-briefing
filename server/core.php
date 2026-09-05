<?php
declare(strict_types=1);
namespace Briefing;
require_once __DIR__ . '/script.php';

function json(array $value): string { return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); }
function atomic(string $path, string $bytes): void
{
    $tmp = $path . '.' . bin2hex(random_bytes(6)) . '.tmp';
    if (file_put_contents($tmp, $bytes, LOCK_EX) !== strlen($bytes)) throw new \RuntimeException('Zápis na disk selhal.');
    chmod($tmp, 0600);
    if (!rename($tmp, $path)) throw new \RuntimeException('Dokončení zápisu selhalo.');
}
function validId(string $id): bool
{
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})-(morning|afternoon)$/D', $id, $m)) return false;
    return checkdate((int)$m[2], (int)$m[3], (int)$m[1]);
}

final class Store
{
    public \PDO $db;
    public function __construct(public array $config)
    {
        $dir = $config['dataDir'];
        if (!is_dir($dir) && !mkdir($dir, 0700, true)) throw new \RuntimeException('Chybí úložiště.');
        $real = realpath($dir);
        $web = realpath($config['publicDir']);
        if (!$real || !$web || $real === $web || str_starts_with($real . DIRECTORY_SEPARATOR, $web . DIRECTORY_SEPARATOR)) throw new \RuntimeException('Úložiště musí být mimo veřejný adresář.');
        if (strlen(base64_decode($config['encryptionKey'], true) ?: '') !== 32) throw new \RuntimeException('Chybí šifrovací klíč.');
        $this->db = new \PDO('sqlite:' . $dir . '/podcast.sqlite', null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC]);
        $this->db->exec('PRAGMA busy_timeout=10000; PRAGMA journal_mode=WAL; PRAGMA synchronous=FULL;');
        $this->db->exec('CREATE TABLE IF NOT EXISTS settings (id INTEGER PRIMARY KEY CHECK(id=1), value TEXT NOT NULL);
          CREATE TABLE IF NOT EXISTS jobs (id TEXT PRIMARY KEY, status TEXT NOT NULL, value TEXT NOT NULL);
          CREATE TABLE IF NOT EXISTS subscriptions (id TEXT PRIMARY KEY, value TEXT NOT NULL);
          CREATE TABLE IF NOT EXISTS deliveries (event TEXT NOT NULL, subscription TEXT NOT NULL, sent INTEGER NOT NULL DEFAULT 0, attempts INTEGER NOT NULL DEFAULT 0, PRIMARY KEY(event,subscription));
          CREATE TABLE IF NOT EXISTS limits (id TEXT PRIMARY KEY, starts INTEGER NOT NULL, count INTEGER NOT NULL);
          CREATE TABLE IF NOT EXISTS meta (id TEXT PRIMARY KEY, value TEXT NOT NULL);');
        chmod($dir . '/podcast.sqlite', 0600);
    }
    public function tx(callable $fn): mixed
    {
        $this->db->exec('BEGIN IMMEDIATE');
        try { $r = $fn(); $this->db->exec('COMMIT'); return $r; }
        catch (\Throwable $e) { $this->db->exec('ROLLBACK'); throw $e; }
    }
    public function query(string $sql, array $args = []): \PDOStatement
    {
        $q = $this->db->prepare($sql); $q->execute($args); return $q;
    }
    public function seal(array $value): string
    {
        $iv = random_bytes(12); $tag = '';
        $cipher = openssl_encrypt(json($value), 'aes-256-gcm', base64_decode($this->config['encryptionKey']), OPENSSL_RAW_DATA, $iv, $tag, 'honza-briefing-v1');
        if ($cipher === false) throw new \RuntimeException('Šifrování selhalo.');
        return base64_encode($iv . $tag . $cipher);
    }
    public function unseal(string $value): array
    {
        $raw = base64_decode($value, true);
        if ($raw === false || strlen($raw) < 28) throw new \RuntimeException('Neplatná šifrovaná data.');
        $plain = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', base64_decode($this->config['encryptionKey']), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16), 'honza-briefing-v1');
        if ($plain === false) throw new \RuntimeException('Ověření šifrovaných dat selhalo.');
        return json_decode($plain, true, 512, JSON_THROW_ON_ERROR);
    }
    public function settings(): array
    {
        $v = $this->query('SELECT value FROM settings WHERE id=1')->fetchColumn();
        return $v ? $this->unseal($v) : ['apiKey' => '', 'voice' => 'Charon'];
    }
    public function saveSettings(array $settings): void { $this->query('INSERT OR REPLACE INTO settings VALUES(1,?)', [$this->seal($settings)]); }
    public function job(string $id): ?array
    {
        $v = $this->query('SELECT value FROM jobs WHERE id=?', [$id])->fetchColumn();
        return $v ? json_decode($v, true, 512, JSON_THROW_ON_ERROR) : null;
    }
    public function saveJob(array $job): void
    {
        $job['updatedAt'] = gmdate(DATE_ATOM);
        $this->query('INSERT OR REPLACE INTO jobs VALUES(?,?,?)', [$job['id'], $job['status'], json($job)]);
    }
    public function path(string $id): string
    {
        if (!validId($id)) throw new \RuntimeException('Neplatné ID vydání.');
        $p = $this->config['dataDir'] . '/audio/' . $id;
        if (!is_dir($p) && !mkdir($p, 0700, true)) throw new \RuntimeException('Nelze vytvořit úložiště vydání.');
        return $p;
    }
    public function rate(string $id, int $limit, int $seconds): bool
    {
        return $this->tx(function () use ($id, $limit, $seconds) {
            $r = $this->query('SELECT * FROM limits WHERE id=?', [$id])->fetch();
            if (!$r || (int)$r['starts'] < time() - $seconds) {
                $this->query('INSERT OR REPLACE INTO limits VALUES(?,?,1)', [$id,time()]); return true;
            }
            $this->query('UPDATE limits SET count=count+1 WHERE id=?', [$id]);
            return (int)$r['count'] < $limit;
        });
    }
}

function fetchJson(string $url, int $limit = 2000000): array
{
    $c = curl_init($url); $body = '';
    curl_setopt_array($c, [CURLOPT_TIMEOUT => 20, CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_FOLLOWLOCATION => false, CURLOPT_USERAGENT => 'HonzaBriefing/1', CURLOPT_HTTPHEADER => ['Accept: application/json'],
        CURLOPT_WRITEFUNCTION => function ($c, $part) use (&$body, $limit) { if (strlen($body) + strlen($part) > $limit) return 0; $body .= $part; return strlen($part); }]);
    $ok = curl_exec($c); $status = curl_getinfo($c, CURLINFO_RESPONSE_CODE); curl_close($c);
    if ($ok === false || $status !== 200) throw new \RuntimeException('Vydání se nepodařilo stáhnout.');
    return json_decode($body, true, 512, JSON_THROW_ON_ERROR);
}

function edition(string $id): array
{
    if (!validId($id)) throw new \RuntimeException('Neplatné vydání.');
    // The caller cannot choose a URL, host or arbitrary filesystem path.
    $base = 'https://raw.githubusercontent.com/jdjandobes-netizen/honza-briefing/main/';
    $index = fetchJson($base . 'data/archive/index.json');
    $entry = null;
    foreach ($index['editions'] ?? [] as $e) if (($e['id'] ?? '') === $id) $entry = $e;
    if (!$entry || $entry['path'] !== 'data/archive/' . $id . '.json') throw new \RuntimeException('Vydání není v archivu.');
    $data = fetchJson($base . $entry['path']);
    if (($data['publication']['edition'] ?? '') !== $entry['edition']) throw new \RuntimeException('Typ vydání neodpovídá archivu.');
    return $data;
}

function createJob(Store $s, string $id, array $data): array
{
    return $s->tx(function () use ($s, $id, $data) {
        if ($existing = $s->job($id)) return $existing;
        $settings = $s->settings();
        if (empty($settings['apiKey'])) throw new \RuntimeException('Nejprve ulož Gemini API klíč v nastavení.');
        $active = (int)$s->query("SELECT COUNT(*) FROM jobs WHERE status IN ('queued','running')")->fetchColumn();
        if ($active >= 3) throw new \RuntimeException('Ve frontě už jsou tři podcasty.');
        if (disk_free_space($s->config['dataDir']) < 400 * 1024 * 1024) throw new \RuntimeException('Na serveru není dost místa pro další podcast.');
        $script = script($data, $id);
        $chunks = array_map(fn($c) => $c + ['status' => 'pending', 'samples' => 0], $script['chunks']);
        $job = ['id'=>$id, 'status'=>'queued', 'createdAt'=>gmdate(DATE_ATOM), 'sourceHash'=>hash('sha256',json($data)), 'scriptVersion'=>SCRIPT_VERSION,
            'model'=>TTS_MODEL,'voice'=>$settings['voice'],'script'=>$script,'chunks'=>$chunks,'error'=>null,'duration'=>0,'chapters'=>[]];
        atomic($s->path($id) . '/source.json', json($data));
        atomic($s->path($id) . '/script.json', json($script));
        $s->saveJob($job);
        return $job;
    });
}

function publicJob(?array $job): ?array
{
    if (!$job) return null;
    return ['id'=>$job['id'],'status'=>$job['status'],'voice'=>$job['voice'],'model'=>$job['model'],
        'completed'=>count(array_filter($job['chunks'],fn($c)=>$c['status']==='done')),'total'=>count($job['chunks']),
        'duration'=>$job['duration'],'chapters'=>$job['chapters'],'error'=>$job['error'],
        'audioUrl'=>$job['status']==='ready' ? 'api/podcast.php?action=audio&id=' . rawurlencode($job['id']) : null];
}

function audioBlocks(array $response): string
{
    $candidate = $response['candidates'][0] ?? [];
    if (($candidate['finishReason'] ?? '') !== 'STOP') throw new \RuntimeException('Gemini nepotvrdilo úplné dokončení zvuku.');
    $pcm = '';
    foreach ($candidate['content']['parts'] ?? [] as $part) {
        if (!isset($part['inlineData'])) continue;
        $audio = $part['inlineData'];
        $mime = strtolower(str_replace(' ', '', $audio['mimeType'] ?? ''));
        if (!preg_match('~^audio/(?:l16|pcm);(?:codec=pcm;)?rate=24000(?:;channels=1)?$~D', $mime)) throw new \RuntimeException('Gemini vrátilo nekompatibilní formát audia.');
        $bytes = base64_decode($audio['data'] ?? '', true);
        if ($bytes === false || $bytes === '' || strlen($bytes) % 2 !== 0) throw new \RuntimeException('Neúplná audio data.');
        $pcm .= $bytes;
    }
    if (strlen($pcm) < 4800 || strlen($pcm) > 24000 * 2 * 300) throw new \RuntimeException('Neplatná délka audia.');
    return $pcm;
}

function gemini(string $apiKey, string $model, string $voice, string $text): string
{
    if ($model !== TTS_MODEL || !in_array($voice, VOICES, true)) throw new \RuntimeException('Neplatný hlas nebo model.');
    $body = ''; $c = curl_init('https://generativelanguage.googleapis.com/v1beta/models/' . $model . ':generateContent');
    curl_setopt_array($c, [CURLOPT_POST=>true,CURLOPT_TIMEOUT=>100,CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_FOLLOWLOCATION=>false,
        CURLOPT_HTTPHEADER=>['Content-Type: application/json','x-goog-api-key: ' . $apiKey],
        CURLOPT_POSTFIELDS=>json(['contents'=>[['parts'=>[['text'=>ttsPrompt($text)]]]],'generationConfig'=>['responseModalities'=>['AUDIO'],'speechConfig'=>['voiceConfig'=>['prebuiltVoiceConfig'=>['voiceName'=>$voice]]]]]),
        CURLOPT_WRITEFUNCTION=>function($c,$part) use (&$body) { if (strlen($body)+strlen($part)>24000000) return 0; $body.=$part; return strlen($part); }]);
    $ok = curl_exec($c); $status = curl_getinfo($c,CURLINFO_RESPONSE_CODE); curl_close($c);
    // Never expose upstream bodies: they can echo submitted text or credentials.
    if ($ok === false) throw new \RuntimeException('Spojení s Gemini se přerušilo. Účtování této části je nejisté.');
    if ($status !== 200) throw new \RuntimeException('Gemini požadavek nedokončilo (HTTP ' . $status . ').');
    return audioBlocks(json_decode($body,true,512,JSON_THROW_ON_ERROR));
}
