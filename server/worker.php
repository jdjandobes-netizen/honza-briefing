<?php
declare(strict_types=1);
namespace Briefing;
require_once __DIR__ . '/core.php';

function assemble(Store $s, array &$job): void
{
    $dir = $s->path($job['id']);
    $pcmPath = $dir . '/joined.pcm';
    $out = fopen($pcmPath, 'wb');
    if (!$out) throw new \RuntimeException('Nelze sestavit zvuk.');
    $samples = 0; $chapter = null; $job['chapters'] = [];
    try {
        foreach ($job['chunks'] as $i => $chunk) {
            if ($chapter !== $chunk['chapter']) {
                $chapter = $chunk['chapter'];
                $job['chapters'][] = ['title'=>$chunk['title'],'start'=>$samples/24000];
            }
            $path = $dir . '/' . $i . '.pcm';
            if (!is_file($path) || hash_file('sha256',$path) !== $chunk['hash']) throw new \RuntimeException('Uložená část zvuku neprošla kontrolou.');
            $in = fopen($path, 'rb'); $written = stream_copy_to_stream($in,$out); fclose($in);
            if ($written !== $chunk['samples'] * 2) throw new \RuntimeException('Sestavení části zvuku selhalo.');
            $samples += $chunk['samples'];
            if (fwrite($out, str_repeat("\0",12000)) !== 12000) throw new \RuntimeException('Zápis mezery selhal.');
            $samples += 6000; // 250 ms between sections/chunks; no synthetic sound effects.
        }
    } finally { fclose($out); }
    $mp3 = $dir . '/podcast.pending.mp3';
    $process = proc_open([$s->config['ffmpeg'],'-hide_banner','-loglevel','error','-y','-f','s16le','-ar','24000','-ac','1','-i',$pcmPath,'-codec:a','libmp3lame','-b:a','96k',$mp3], [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    if (!is_resource($process)) throw new \RuntimeException('Převod do MP3 není dostupný.');
    fclose($pipes[0]); stream_get_contents($pipes[1]); fclose($pipes[1]); stream_get_contents($pipes[2]); fclose($pipes[2]);
    if (proc_close($process) !== 0 || !is_file($mp3) || filesize($mp3)<1000) throw new \RuntimeException('Převod do MP3 selhal. Hotové části zůstaly uložené.');
    chmod($mp3,0600);
    if (!rename($mp3,$dir . '/podcast.mp3')) throw new \RuntimeException('Uložení MP3 selhalo.');
    $job['duration']=$samples/24000;
    $job['audioHash']=hash_file('sha256',$dir . '/podcast.mp3');
    $job['status']='ready'; $job['readyAt']=gmdate(DATE_ATOM); $job['error']=null; $s->saveJob($job);
    // Only disposable PCM is reclaimed, after durable MP3 + manifest publication.
    // Source, script, chapters and purchased MP3 are retained without an expiry.
    @unlink($pcmPath);
    foreach ($job['chunks'] as $i=>$c) @unlink($dir . '/' . $i . '.pcm');
}

function validSubscription(array $sub): bool
{
    $u = parse_url($sub['endpoint'] ?? ''); $host = strtolower($u['host'] ?? '');
    $allowed = in_array($host,['fcm.googleapis.com','updates.push.services.mozilla.com'],true)
        || preg_match('/^[a-z0-9-]+\.push\.apple\.com$/D',$host)
        || preg_match('/^[a-z0-9.-]+\.notify\.windows\.com$/D',$host);
    $decode = fn($v)=>base64_decode(strtr($v,'-_','+/'),true);
    return ($u['scheme']??'')==='https' && !isset($u['user']) && !isset($u['pass']) && !isset($u['port']) && $allowed
        && strlen($sub['endpoint']) < 3000 && strlen($decode($sub['keys']['p256dh']??'')?:'')===65 && strlen($decode($sub['keys']['auth']??'')?:'')===16;
}

function notifyReady(Store $s): void
{
    $vendor=__DIR__.'/vendor/autoload.php';
    if (!is_file($vendor)) return;
    require_once $vendor;
    $vapid=$s->config['vapid']??null;
    if (!$vapid) return;
    foreach ($s->query("SELECT value FROM jobs WHERE status='ready'")->fetchAll() as $row) {
        $job=json_decode($row['value'],true); $event=$job['id'];
        foreach ($s->query('SELECT * FROM subscriptions')->fetchAll() as $row) {
            $sub=$s->unseal($row['value']);
            // Opt-in during generation is supported; do not notify new devices about old history.
            if (($sub['createdAt']??'') > ($job['readyAt']??$job['createdAt'])) continue;
            $s->query('INSERT OR IGNORE INTO deliveries(event,subscription) VALUES(?,?)',[$event,$row['id']]);
            $delivery=$s->query('SELECT * FROM deliveries WHERE event=? AND subscription=?',[$event,$row['id']])->fetch();
            if ((int)$delivery['sent']===1 || (int)$delivery['attempts']>=5) continue;
            $s->query('UPDATE deliveries SET attempts=attempts+1 WHERE event=? AND subscription=?',[$event,$row['id']]);
            try {
                $webPush=new \Minishlink\WebPush\WebPush(['VAPID'=>$vapid],['TTL'=>86400,'urgency'=>'normal'],10,['allow_redirects'=>false]);
                $report=$webPush->sendOneNotification(\Minishlink\WebPush\Subscription::create($sub),json([
                    'title'=>'Podcast je připravený','body'=>'Honzův briefing · '.$event,'tag'=>'podcast-'.$event,'url'=>'./?podcast='.$event]));
                if ($report->isSuccess()) $s->query('UPDATE deliveries SET sent=1 WHERE event=? AND subscription=?',[$event,$row['id']]);
                elseif ($report->isSubscriptionExpired()) $s->query('DELETE FROM subscriptions WHERE id=?',[$row['id']]);
            } catch (\Throwable) { /* Delivery failure does not invalidate paid audio. Retry only push. */ }
        }
    }
}

function tick(Store $s, ?callable $generate=null, ?callable $encode=null): bool
{
    $lock=fopen($s->config['dataDir'].'/worker.lock','c+');
    if (!$lock || !flock($lock,LOCK_EX|LOCK_NB)) { if ($lock) fclose($lock); return false; }
    try {
        $s->query('INSERT OR REPLACE INTO meta VALUES(?,?)',['workerHeartbeat',(string)time()]);
        $row=$s->query("SELECT id FROM jobs WHERE status IN ('queued','running') ORDER BY id LIMIT 1")->fetch();
        if (!$row) { notifyReady($s); return false; }
        $job=$s->job($row['id']); $dir=$s->path($job['id']);
        // An interrupted in-flight request is never silently bought a second time.
        foreach ($job['chunks'] as $i=>&$chunk) {
            if ($chunk['status']!=='inflight') continue;
            $file=$dir.'/'.$i.'.pcm';
            if (is_file($file) && filesize($file)>=4800 && filesize($file)%2===0) {
                $chunk['status']='done'; $chunk['samples']=filesize($file)/2; $chunk['hash']=hash_file('sha256',$file);
            } else {
                $chunk['status']='uncertain'; $job['status']='needs_retry';
                $job['error']='Generování se přerušilo. Tato část mohla být zaplacena. Opakování vyžaduje tvoje potvrzení.';
            }
        }
        unset($chunk); $s->saveJob($job);
        if ($job['status']==='needs_retry') return true;
        foreach ($job['chunks'] as $i=>&$chunk) {
            if ($chunk['status']!=='pending') continue;
            $key=$s->settings()['apiKey']??'';
            if ($key==='') { $job['status']='paused'; $job['error']='Doplň API klíč a pokračuj.'; $s->saveJob($job); return true; }
            $chunk['status']='inflight'; $job['status']='running'; $s->saveJob($job);
            try {
                $pcm=($generate??__NAMESPACE__.'\\gemini')($key,$job['model'],$job['voice'],$chunk['text']);
                // Basic truncation check, not a substitute for a first Czech listening test.
                $words=count(preg_split('/\s+/u',spoken($chunk['text'])));
                if (strlen($pcm)/48000 < max(.1,$words/6)) {
                    atomic($dir.'/'.$i.'.review.pcm',$pcm);
                    throw new \RuntimeException('Audio je podezřele krátké. Část zůstala k ruční kontrole.');
                }
                atomic($dir.'/'.$i.'.pcm',$pcm);
                $chunk['status']='done'; $chunk['samples']=strlen($pcm)/2; $chunk['hash']=hash('sha256',$pcm);
                $job['status']='queued'; $s->saveJob($job);
            } catch (\Throwable $e) {
                $chunk['status']='uncertain'; $job['status']='needs_retry';
                $job['error']=$e instanceof \JsonException ? 'Gemini vrátilo neplatnou odpověď.' : $e->getMessage();
                $s->saveJob($job);
            }
            unset($chunk);
            break;
        }
        if (count(array_filter($job['chunks'],fn($c)=>$c['status']==='done'))===count($job['chunks'])) {
            try { ($encode??__NAMESPACE__.'\\assemble')($s,$job); }
            catch (\Throwable) { $job['status']='assembly_failed'; $job['error']='Sestavení MP3 selhalo. Všechny zaplacené části zůstaly uložené; pokračování je znovu nekupuje.'; $s->saveJob($job); }
        }
        notifyReady($s);
        return true;
    } finally { flock($lock,LOCK_UN); fclose($lock); }
}

if (PHP_SAPI==='cli' && realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__) {
    $configPath=$argv[1]??getenv('BRIEFING_CONFIG');
    if (!$configPath || !is_file($configPath)) { fwrite(STDERR,"Usage: php worker.php /absolute/private/config.php\n"); exit(1); }
    try { tick(new Store(require $configPath)); echo "Worker OK\n"; }
    catch (\Throwable) { fwrite(STDERR,"Worker failed; inspect private state.\n"); exit(1); }
}
