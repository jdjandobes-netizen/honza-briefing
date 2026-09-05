<?php
declare(strict_types=1);
require_once __DIR__.'/../server/worker.php';
use Briefing\Store;
use function Briefing\{script,cue,spoken,validId,audioBlocks,createJob,tick,atomic,validSubscription};
$checks=0;
function ok(bool $value,string $label): void { global $checks; if (!$value) throw new RuntimeException($label); $checks++; }
function rejects(callable $fn,string $label): void { try {$fn();} catch(Throwable) {ok(true,$label);return;} ok(false,$label); }
$root=sys_get_temp_dir().'/briefing-test-'.bin2hex(random_bytes(8));
mkdir($root); mkdir($root.'/public');
$config=['dataDir'=>$root.'/private','publicDir'=>$root.'/public','encryptionKey'=>base64_encode(random_bytes(32)),'ffmpeg'=>'/usr/bin/ffmpeg'];
$s=new Store($config);
$s->saveSettings(['apiKey'=>'synthetic-test-key-never-sent','voice'=>'Charon']);
ok(!str_contains($s->query('SELECT value FROM settings')->fetchColumn(),'synthetic-test'),'key encrypted at rest');
ok($s->settings()['apiKey']==='synthetic-test-key-never-sent','key decrypts');
$sealed=$s->seal(['value'=>1]); $raw=base64_decode($sealed);$raw[30]=chr(ord($raw[30])^1);
rejects(fn()=>$s->unseal(base64_encode($raw)),'GCM rejects tampering');
ok(validId('2026-09-05-morning') && !validId('2026-02-30-morning') && !validId('../config.php'),'strict ids');
ok(cue(['title'=>'Útok zanechal oběti','narration'=>['audioTag'=>'excited']],'tech')==='serious','tragic tone override');
ok(cue(['title'=>'Nový čip'],'tech')==='curious','tech fallback');
ok(spoken('[excited] <b>Ahoj</b> https://example.com')==='Ahoj','strip only markup and URL');
$story=['title'=>'Testovací zpráva','summary'=>'Tohle je syntetická zpráva pro automatické ověření. Nejde o skutečnou událost.','source'=>['name'=>'Test']];
$data=['schemaVersion'=>1,'publication'=>['title'=>'Ranní přehled','date'=>'5. září 2026','edition'=>'morning','intro'=>'Syntetické ověření.'],
 'topStories'=>[$story],'sections'=>[['id'=>'cesko','title'=>'Česko','items'=>[$story],'minor'=>[['title'=>'Menší zpráva','summary'=>'Další syntetický bod.']]]],
 'vwce'=>['price'=>'100','currency'=>'EUR','metrics'=>[['label'=>'Test','value'=>'1 %']]],
 'podcasts'=>['shows'=>[['show'=>'Test podcast','title'=>'Testovací epizoda']]]];
$script=script($data,'2026-09-05-morning');
ok(str_contains($script['text'],'Menší zpráva') && str_contains($script['text'],'Testovací epizoda') && str_contains($script['text'],'100 EUR'),'all content types covered');
foreach($script['chunks'] as $chunk) ok(mb_strlen($chunk['text'])<=1600 && preg_match('/^\[(serious|curious|excited|warm|calm)\]/',$chunk['text'])===1,'bounded tagged chunk');
$long=$data;$long['sections'][0]['items'][0]['summary']=str_repeat('Dlouhá syntetická věta. ',300);
foreach(script($long,'2026-09-05-morning')['chunks'] as $chunk) ok(mb_strlen($chunk['text'])<=1600,'long input split');
$part=fn($p)=>['inlineData'=>['mimeType'=>'audio/L16;codec=pcm;rate=24000','data'=>base64_encode($p)]];
$pcmA=str_repeat("\0",4800);$pcmB=str_repeat("\1",4800);
$response=['candidates'=>[['finishReason'=>'STOP','content'=>['parts'=>[$part($pcmA),$part($pcmB)]]]]];
ok(audioBlocks($response)===$pcmA.$pcmB,'all PCM blocks in order');
$bad=$response;$bad['candidates'][0]['finishReason']='MAX_TOKENS';rejects(fn()=>audioBlocks($bad),'incomplete rejected');
$bad=$response;$bad['candidates'][0]['content']['parts'][0]['inlineData']['mimeType']='audio/L16;rate=22050';rejects(fn()=>audioBlocks($bad),'incompatible rate rejected');
$sub=['endpoint'=>'https://web.push.apple.com/test','keys'=>['p256dh'=>base64_encode(str_repeat('a',65)),'auth'=>base64_encode(str_repeat('b',16))]];
ok((bool)validSubscription($sub),'Apple push permitted');
$sub['endpoint']='https://user@web.push.apple.com/test';ok(!validSubscription($sub),'userinfo rejected');
$sub['endpoint']='https://127.0.0.1/test';ok(!validSubscription($sub),'SSRF rejected');
$j=createJob($s,'2026-09-05-morning',$data);$original=serialize($s->job($j['id']));
$s->saveSettings(['apiKey'=>'','voice'=>'Kore']);
ok(serialize(createJob($s,'2026-09-05-morning',$long))===$original,'existing edition returned unchanged even without key');
$s->saveSettings(['apiKey'=>'synthetic-test-key-never-sent','voice'=>'Kore']);
$calls=0;$encodes=0;
$generate=function()use(&$calls){$calls++;return str_repeat("\0",48000*20);};
$encode=function(Store $store,array &$job)use(&$encodes){$encodes++;$job['status']='ready';$job['readyAt']=gmdate(DATE_ATOM);$store->saveJob($job);};
for($i=0;$i<count($j['chunks'])+2;$i++) tick($s,$generate,$encode);
ok($calls===count($j['chunks']) && $encodes===1,'each chunk bought once');
ok($s->job($j['id'])['voice']==='Charon','voice locked at creation');
tick($s,$generate,$encode);ok($calls===count($j['chunks']),'ready never regenerated');
$j2=createJob($s,'2026-09-05-afternoon',$data);$j2['chunks'][0]['status']='inflight';$j2['status']='running';$s->saveJob($j2);
$before=$calls;tick($s,$generate,$encode);ok($calls===$before && $s->job($j2['id'])['status']==='needs_retry','uncertain crash never auto retried');
$j3=createJob($s,'2026-09-04-morning',$data);$j3['chunks'][0]['status']='inflight';$j3['status']='running';$s->saveJob($j3);
atomic($s->path($j3['id']).'/0.pcm',str_repeat("\0",48000*20));
tick($s,$generate,$encode);ok($s->job($j3['id'])['chunks'][0]['status']==='done' && $calls===$before+1,'saved PCM recovered, next chunk only generated');
$lock=fopen($config['dataDir'].'/worker.lock','c+');flock($lock,LOCK_EX);$before=$calls;
ok(tick($s,$generate,$encode)===false && $calls===$before,'concurrent worker blocked');flock($lock,LOCK_UN);fclose($lock);
if (isset($argv[1])) {
    $real=json_decode(file_get_contents($argv[1]),true,512,JSON_THROW_ON_ERROR);
    $rs=script($real,basename($argv[1],'.json'));
    foreach($real['sections'] as $section) foreach(array_merge($section['items']??[],$section['minor']??[]) as $item) ok(str_contains(spoken($rs['text']),spoken($item['title']??$item['text']??'')),'real archive story included');
    echo 'Archive script: '.count($rs['chunks']).' chunks, '.$rs['estimatedMinutes']." minutes estimated\n";
}
echo "PASS: $checks checks; zero external API calls. Synthetic state: $root\n";
