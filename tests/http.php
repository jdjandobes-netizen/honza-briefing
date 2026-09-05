<?php
declare(strict_types=1);
require_once __DIR__.'/../server/core.php';
$root=sys_get_temp_dir().'/briefing-http-'.bin2hex(random_bytes(8));
mkdir($root);mkdir($root.'/public');mkdir($root.'/public/api');mkdir($root.'/private');
copy(__DIR__.'/../api/podcast.php',$root.'/public/api/podcast.php');
$config=['dataDir'=>$root.'/private/runtime','publicDir'=>$root.'/public','serverDir'=>realpath(__DIR__.'/../server'),
 'encryptionKey'=>base64_encode(random_bytes(32)),'origin'=>'http://127.0.0.1:8847','development'=>true];
file_put_contents($root.'/private/config.php','<?php return '.var_export($config,true).';');
$s=new Briefing\Store($config);
$s->query('INSERT INTO meta VALUES(?,?)',['passwordHash',password_hash('synthetic-test-password-123',PASSWORD_DEFAULT)]);
$id='2026-09-05-morning';
$job=['id'=>$id,'status'=>'ready','voice'=>'Charon','model'=>Briefing\TTS_MODEL,'chunks'=>[['status'=>'done']],
 'chapters'=>[['title'=>'Syntetický test','start'=>0]],'duration'=>1,'error'=>null];
$s->saveJob($job);$bytes=str_repeat('SYNTHETIC',2048);Briefing\atomic($s->path($id).'/podcast.mp3',$bytes);
$server=proc_open([PHP_BINARY,'-S','127.0.0.1:8847','-t',$root.'/public'],[0=>['pipe','r'],1=>['file',$root.'/http.log','a'],2=>['file',$root.'/http.log','a']],$pipes);
if (!is_resource($server)) exit("Test server unavailable\n");fclose($pipes[0]);
$cookie=$root.'/cookie';$csrf='';$checks=0;
function req(string $action,?array $body=null,array $headers=[]): array {
 global $cookie,$csrf;
 $c=curl_init('http://127.0.0.1:8847/api/podcast.php?action='.$action);
 curl_setopt_array($c,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_HEADER=>true,CURLOPT_COOKIEFILE=>$cookie,CURLOPT_COOKIEJAR=>$cookie,CURLOPT_TIMEOUT=>5]);
 if ($body!==null) curl_setopt_array($c,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>json_encode($body),CURLOPT_HTTPHEADER=>array_merge(['Content-Type: application/json','Origin: http://127.0.0.1:8847','X-CSRF-Token: '.$csrf],$headers)]);
 else curl_setopt($c,CURLOPT_HTTPHEADER,$headers);
 $result=curl_exec($c);$size=curl_getinfo($c,CURLINFO_HEADER_SIZE);$status=curl_getinfo($c,CURLINFO_RESPONSE_CODE);curl_close($c);
 return [$status,substr((string)$result,$size),substr((string)$result,0,$size)];
}
function check(bool $test,string $label): void {global $checks;if(!$test)throw new RuntimeException($label);$checks++;}
try {
 for($i=0;$i<20;$i++){usleep(100000);$r=req('session');if($r[0]===200)break;}
 check($r[0]===200,'anonymous session');$csrf=json_decode($r[1],true)['csrf'];
 check(req('settings')[0]===401,'private settings protected');
 check(req('audio&id='.$id)[0]===401,'audio protected');
 $valid=$csrf;$csrf='bad';check(req('login',['password'=>'synthetic-test-password-123'])[0]===403,'CSRF rejected');$csrf=$valid;
 check(req('login',['password'=>'synthetic-test-password-123'],['Origin: https://evil.example'])[0]===403,'foreign origin rejected');
 $r=req('login',['password'=>'synthetic-test-password-123']);check($r[0]===200,'login');$csrf=json_decode($r[1],true)['csrf'];
 check(str_contains(strtolower($r[2]),'httponly') && str_contains(strtolower($r[2]),'samesite=strict'),'cookie protections');
 $r=req('settings',['voice'=>'Charon','apiKey'=>'synthetic-test-key-never-sent']);
 check($r[0]===200 && json_decode($r[1],true)['hasKey'] && !str_contains($r[1],'synthetic-test'),'key never returned');
 check(!str_contains(req('settings')[1],'synthetic-test'),'saved key never returned');
 check(req('prepare&id=2026-09-04-morning',[])[0]===503,'inactive worker prevents charge');
 $r=req('audio&id='.$id,null,['Range: bytes=12-37']);check($r[0]===206 && $r[1]===substr($bytes,12,26),'range seeking');
 check(str_contains(strtolower($r[2]),'no-store'),'audio not cached');
 check(req('audio&id='.$id,null,['Range: bytes=999999-'])[0]===416,'invalid range');
 check(req('status&id=../../config')[0]===400,'traversal rejected');
 $r=req('logout',[]);check($r[0]===200 && req('settings')[0]===401,'logout revokes session');
 echo "PASS: $checks HTTP checks; zero Gemini requests.\n";
} finally {proc_terminate($server);proc_close($server);}
