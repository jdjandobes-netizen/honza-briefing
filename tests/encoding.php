<?php
declare(strict_types=1);
require_once __DIR__.'/../server/worker.php';
$root=sys_get_temp_dir().'/briefing-encoding-'.bin2hex(random_bytes(8));mkdir($root);mkdir($root.'/public');
$s=new Briefing\Store(['publicDir'=>$root.'/public','dataDir'=>$root.'/private','encryptionKey'=>base64_encode(random_bytes(32)),'ffmpeg'=>$argv[1]??'/usr/bin/ffmpeg']);
$s->saveSettings(['apiKey'=>'synthetic-key-never-sent','voice'=>'Charon']);
$j=Briefing\createJob($s,'2000-01-01-morning',['schemaVersion'=>1,'publication'=>['title'=>'Syntetický test','intro'=>'Pouze testovací zvuk.'],'sections'=>[]]);
$calls=0;$generate=function()use(&$calls){$calls++;return str_repeat("\0",48000*10);};
for($i=0;$i<count($j['chunks']);$i++) Briefing\tick($s,$generate);
$ready=$s->job($j['id']);$dir=$s->path($j['id']);
if($ready['status']!=='ready' || !is_file($dir.'/podcast.mp3') || filesize($dir.'/podcast.mp3')<10000)throw new RuntimeException('MP3 encoding failed');
if(abs($ready['duration']-count($j['chunks'])*10.25)>.001)throw new RuntimeException('Duration mismatch');
if(!is_file($dir.'/source.json')||!is_file($dir.'/script.json')||is_file($dir.'/0.pcm'))throw new RuntimeException('Retention mismatch');
$before=$calls;Briefing\tick($s,$generate);if($calls!==$before)throw new RuntimeException('Unexpected repurchase');
if (($argv[2]??'')==='--fixture') copy($dir.'/podcast.mp3',__DIR__.'/synthetic.mp3');
echo 'PASS: actual MP3 encoded, '.count($ready['chapters']).' chapters, '.$ready['duration'].' seconds, '.filesize($dir.'/podcast.mp3')." bytes; zero Gemini requests.\n";
