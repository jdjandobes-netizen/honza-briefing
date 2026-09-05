<?php
declare(strict_types=1);
use Briefing\Store;

ini_set('display_errors','0');
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('Content-Type: application/json; charset=utf-8');

function reply(array $value, int $status=200): never { http_response_code($status); echo json_encode($value,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); exit; }
function fail(string $message,int $status=400): never { reply(['error'=>$message],$status); }

try {
    $configPath=getenv('BRIEFING_CONFIG') ?: (is_file(__DIR__.'/config-path.php') ? require __DIR__.'/config-path.php' : dirname(__DIR__,2).'/private/config.php');
    if (!is_file($configPath)) fail('Podcastová služba zatím není nastavená.',503);
    $config=require $configPath;
    require_once $config['serverDir'].'/core.php';
    require_once $config['serverDir'].'/worker.php';
    $s=new Store($config);
    $origin=$config['origin'];
    $secure=str_starts_with($origin,'https://');
    if (!$secure && !($config['development']??false)) fail('Služba vyžaduje HTTPS.',503);
    $cookiePath=$config['publicBase']??'/';
    session_name('honza_podcast_'.substr(hash('sha256',$cookiePath),0,8));
    session_set_cookie_params(['lifetime'=>2592000,'path'=>$cookiePath,'secure'=>$secure,'httponly'=>true,'samesite'=>'Strict']);
    ini_set('session.use_strict_mode','1');
    session_start();
    $_SESSION['csrf']??=bin2hex(random_bytes(32));
    $csrf=$_SESSION['csrf'];
    $auth=isset($_SESSION['authenticatedAt']) && $_SESSION['authenticatedAt']>time()-2592000;
    $action=$_GET['action']??'session';
    $method=$_SERVER['REQUEST_METHOD'];
    $input=[];
    if (!in_array($method,['GET','POST','HEAD'],true)) fail('Nepodporovaná metoda.',405);
    if ($method==='POST') {
        if (($_SERVER['HTTP_ORIGIN']??'')!==$origin || !hash_equals($csrf,$_SERVER['HTTP_X_CSRF_TOKEN']??'')) fail('Obnov stránku a zkus to znovu.',403);
        if (!str_starts_with($_SERVER['CONTENT_TYPE']??'','application/json')) fail('Požadavek musí být JSON.',415);
        $raw=file_get_contents('php://input',false,null,0,16385);
        if (strlen($raw)>16384) fail('Požadavek je příliš velký.',413);
        $input=json_decode($raw,true,32,JSON_THROW_ON_ERROR);
        if (!is_array($input)) fail('Neplatný požadavek.');
    }
    $passwordHash=$s->query("SELECT value FROM meta WHERE id='passwordHash'")->fetchColumn();
    if ($action==='session' && $method==='GET') {
        session_write_close();
        reply(['authenticated'=>$auth,'csrf'=>$csrf,'setupRequired'=>!$passwordHash,'service'=>true]);
    }
    if (in_array($action,['login','setup'],true) && $method==='POST') {
        $ip=hash('sha256',$_SERVER['REMOTE_ADDR']??'unknown');
        if (!$s->rate('auth-'.$ip,10,900)) fail('Příliš mnoho pokusů. Počkej 15 minut.',429);
        $password=(string)($input['password']??'');
        if (strlen($password)<12 || strlen($password)>200) fail('Heslo musí mít 12 až 200 znaků.');
        if ($action==='setup') {
            if ($passwordHash || time()>($config['setupExpires']??0) || !hash_equals($config['setupTokenHash']??'',hash('sha256',$input['setupToken']??''))) fail('Prvotní nastavení není dostupné.',403);
            $s->tx(function() use ($s,$password) {
                if ($s->query("SELECT value FROM meta WHERE id='passwordHash'")->fetchColumn()) throw new RuntimeException('Účet je již nastavený.');
                $s->query('INSERT INTO meta VALUES(?,?)',['passwordHash',password_hash($password,PASSWORD_DEFAULT)]);
            });
        } elseif (!$passwordHash || !password_verify($password,$passwordHash)) fail('Nesprávné heslo.',401);
        session_regenerate_id(true); $_SESSION['authenticatedAt']=time(); $_SESSION['csrf']=bin2hex(random_bytes(32));
        $csrf=$_SESSION['csrf']; session_write_close(); reply(['authenticated'=>true,'csrf'=>$csrf]);
    }
    if (!$auth) fail('Přihlas se v nastavení podcastu.',401);
    if ($action==='logout' && $method==='POST') {
        $_SESSION=[]; session_destroy(); setcookie(session_name(),'',['expires'=>1,'path'=>$cookiePath,'secure'=>$secure,'httponly'=>true,'samesite'=>'Strict']); reply(['ok'=>true]);
    }
    session_write_close();
    if ($action==='settings') {
        if ($method==='POST') {
            $s->tx(function() use ($s,$input) {
                $settings=$s->settings();
                if (!in_array($input['voice']??'',Briefing\VOICES,true)) throw new RuntimeException('Neplatný hlas.');
                $settings['voice']=$input['voice'];
                if (!empty($input['apiKey'])) {
                    if (!preg_match('/^[A-Za-z0-9_-]{25,200}$/D',$input['apiKey'])) throw new RuntimeException('Neplatný formát API klíče.');
                    $settings['apiKey']=$input['apiKey'];
                }
                if (!empty($input['removeKey'])) $settings['apiKey']='';
                $s->saveSettings($settings);
            });
        }
        $settings=$s->settings();
        $beat=(int)$s->query("SELECT value FROM meta WHERE id='workerHeartbeat'")->fetchColumn();
        reply(['hasKey'=>!empty($settings['apiKey']),'voice'=>$settings['voice'],'voices'=>Briefing\VOICES,'model'=>Briefing\TTS_MODEL,
            'pushPublicKey'=>is_file($config['serverDir'].'/vendor/autoload.php')?($config['vapid']['publicKey']??null):null,'workerOnline'=>$beat>time()-300]);
    }
    if ($action==='library' && $method==='GET') {
        $jobs=array_map(fn($r)=>Briefing\publicJob(json_decode($r['value'],true)),$s->query('SELECT value FROM jobs ORDER BY id DESC')->fetchAll());
        reply(['jobs'=>$jobs]);
    }
    if ($action==='subscribe' && $method==='POST') {
        $sub=$input['subscription']??[];
        if (!Briefing\validSubscription($sub)) fail('Nepodporované předplatné notifikací.');
        $id=hash('sha256',$sub['endpoint']);
        $old=$s->query('SELECT value FROM subscriptions WHERE id=?',[$id])->fetchColumn();
        $sub['createdAt']=$old?($s->unseal($old)['createdAt']??gmdate(DATE_ATOM)):gmdate(DATE_ATOM);
        if (!$old && (int)$s->query('SELECT COUNT(*) FROM subscriptions')->fetchColumn()>=20) fail('Je připojeno příliš mnoho zařízení.');
        $s->query('INSERT OR REPLACE INTO subscriptions VALUES(?,?)',[$id,$s->seal($sub)]); reply(['ok'=>true]);
    }
    if ($action==='unsubscribe' && $method==='POST') { $s->query('DELETE FROM subscriptions WHERE id=?',[hash('sha256',$input['endpoint']??'')]); reply(['ok'=>true]); }
    $id=(string)($_GET['id']??$input['id']??'');
    if (!Briefing\validId($id)) fail('Neplatné vydání.');
    $job=$s->job($id);
    if ($action==='status' && $method==='GET') reply(['job'=>Briefing\publicJob($job)]);
    if ($action==='script' && $method==='GET') reply(['script'=>$job['script']??Briefing\script(Briefing\edition($id),$id)]);
    if ($action==='prepare' && $method==='POST') {
        if ($job) reply(['job'=>Briefing\publicJob($job)]);
        if (!$s->rate('prepare',10,3600)) fail('Limit nových podcastů pro tuto hodinu je vyčerpán.',429);
        $beat=(int)$s->query("SELECT value FROM meta WHERE id='workerHeartbeat'")->fetchColumn();
        if ($beat<time()-300) fail('Generátor na serveru není aktivní. Je potřeba zkontrolovat plánovanou úlohu.',503);
        $job=Briefing\createJob($s,$id,Briefing\edition($id)); reply(['job'=>Briefing\publicJob($job)],202);
    }
    if ($action==='retry' && $method==='POST') {
        $job=$s->tx(function() use ($s,$id,$input) {
            $j=$s->job($id);
            if (!$j || !in_array($j['status'],['needs_retry','assembly_failed','paused'],true)) throw new RuntimeException('Tento podcast nelze opakovat.');
            if ($j['status']==='needs_retry' && ($input['acknowledgeCharge']??false)!==true) throw new RuntimeException('Opakování nejisté části může být znovu účtováno.');
            if (empty($s->settings()['apiKey']) && $j['status']!=='assembly_failed') throw new RuntimeException('Nejprve doplň API klíč.');
            foreach ($j['chunks'] as &$c) if ($c['status']==='uncertain') $c['status']='pending';
            unset($c); $j['status']='queued'; $j['error']=null; $s->saveJob($j); return $j;
        }); reply(['job'=>Briefing\publicJob($job)],202);
    }
    if ($action==='audio' && in_array($method,['GET','HEAD'],true)) {
        if (!$job || $job['status']!=='ready') fail('Podcast zatím není připravený.',404);
        $file=$s->path($id).'/podcast.mp3';
        if (!is_file($file)) fail('Zvukový soubor chybí. Je potřeba obnova ze zálohy.',503);
        $size=filesize($file); $start=0; $end=$size-1;
        if (isset($_SERVER['HTTP_RANGE'])) {
            if (!preg_match('/^bytes=(\d*)-(\d*)$/D',$_SERVER['HTTP_RANGE'],$m) || ($m[1]===''&&$m[2]==='')) { header('Content-Range: bytes */'.$size); fail('Neplatný rozsah.',416); }
            if ($m[1]==='') $start=max(0,$size-(int)$m[2]);
            else { $start=(int)$m[1]; if ($m[2]!=='') $end=min($end,(int)$m[2]); }
            if ($start>$end || $start>=$size) { header('Content-Range: bytes */'.$size); fail('Rozsah není dostupný.',416); }
            http_response_code(206); header("Content-Range: bytes $start-$end/$size");
        }
        header('Content-Type: audio/mpeg'); header('Accept-Ranges: bytes'); header('Content-Length: '.($end-$start+1));
        header('Content-Disposition: '.(!empty($_GET['download'])?'attachment':'inline').'; filename="'.$id.'.mp3"');
        if ($method==='HEAD') exit;
        $f=fopen($file,'rb'); fseek($f,$start); $left=$end-$start+1;
        while ($left>0 && !feof($f)) { $part=fread($f,min(65536,$left)); if ($part===false || $part==='') break; echo $part; $left-=strlen($part); }
        fclose($f); exit;
    }
    fail('Neznámá akce.',404);
} catch (Throwable $e) {
    // Never return config paths, SQL diagnostics, request bodies or secrets.
    $safe=($e instanceof RuntimeException) && !($e instanceof PDOException);
    fail($safe ? $e->getMessage() : 'Služba požadavek nedokončila.',400);
}
