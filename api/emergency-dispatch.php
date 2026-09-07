<?php
declare(strict_types=1);
use Briefing\Store;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;

ini_set('display_errors','0');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');

const EMERGENCY_AI_MODEL='gemini-3.8-flash';

function reply(array $v,int $status=200):never{http_response_code($status);echo json_encode($v,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;}
function allowed():bool{$ips=['62.109.128.59','212.57.32.9','62.109.150.10','212.57.32.162'];$ip=$_SERVER['REMOTE_ADDR']??'';if(in_array($ip,$ips,true))return true;$expected=getenv('BRIEFING_EMERGENCY_TOKEN')?:'';$given=(string)($_GET['token']??'');return $expected!==''&&$given!==''&&hash_equals($expected,$given);}
function impactRank(string $v):int{return ['not_affected'=>0,'watch'=>1,'affected'=>2][$v]??0;}
function validImpact(string $v):bool{return in_array($v,['affected','watch','not_affected'],true);}
function fingerprint(array $a):string{return hash('sha256',json_encode([$a['severity']??'',$a['category']??'',$a['title']??'',$a['summary']??'',$a['issuedAt']??'',$a['location']??null,$a['itineraryImpact']??null],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));}
function categoryCz(string $category):string{return ['tsunami'=>'tsunami','earthquake'=>'zemětřesení','volcano'=>'sopečná aktivita','storm'=>'bouře / silný vítr','rain-flood-landslide'=>'silný déšť / povodeň / sesuv','coastal'=>'pobřežní nebezpečí','other'=>'mimořádná informace'][$category]??'mimořádná informace';}
function severityCz(string $severity):string{return ['critical'=>'KRITICKÉ VAROVÁNÍ','warning'=>'VAROVÁNÍ','advisory'=>'UPOZORNĚNÍ','info'=>'INFORMACE'][$severity]??'INFORMACE';}
function fallbackCz(array $alert):array{
  $where=$alert['location']['name']??'';$risk=categoryCz((string)($alert['category']??'other'));$sev=severityCz((string)($alert['severity']??'info'));
  $title=$sev.': '.$risk.($where!==''?' – '.$where:'');
  $explanation='Oficiální japonský zdroj vydal novou informaci typu „'.$risk.'“. Automatický český překlad se nyní nepodařilo získat; pro rozhodnutí otevři oficiální zdroj a řiď se JMA nebo místními úřady.';
  $actions=['Otevři oficiální zdroj a zkontroluj aktuální oblast a instrukce.'];
  if(in_array($alert['severity']??'',['critical','warning'],true))$actions[]='Pokud se nacházíš v uvedené oblasti, nečekej na AI překlad a postupuj podle JMA, místních hlášení a personálu.';
  return ['titleCs'=>$title,'translationCs'=>null,'plainExplanationCs'=>$explanation,'recommendedActions'=>$actions,'urgency'=>$alert['severity']==='critical'?'critical':(($alert['severity']==='warning')?'high':'medium'),'why'=>'Fallback bez AI; oficiální zdroj má přednost.','transportImpact'=>null,'impactStatus'=>$alert['itineraryImpact']['status']??'not_affected','affectedStopIds'=>$alert['itineraryImpact']['affectedStopIds']??[],'confidence'=>'low','model'=>null,'translated'=>false];
}
function gemini(string $key,array $alert,array $itinerary):?array{
  if($key==='')return null;
  $stops=array_map(fn($s)=>['id'=>$s['id']??'','name'=>$s['name']??'','prefecture'=>$s['prefecture']??'','stay'=>$s['stayLabel']??'','highlights'=>array_column($s['routeHighlights']??[],'name')],$itinerary['trip']['stops']??[]);
  $official=['titleJa'=>$alert['title']??'','bodyJa'=>$alert['summary']??'','severity'=>$alert['severity']??'','category'=>$alert['category']??'','issuedAt'=>$alert['issuedAt']??'','source'=>$alert['source']??null,'deterministicImpact'=>$alert['itineraryImpact']??null,'location'=>$alert['location']??null];
  $prompt="Jsi překladatel a bezpečnostní asistent pro českého cestovatele v Japonsku. Pracuj POUZE s oficiální událostí a itinerářem níže. Nejprve věrně přelož japonštinu do češtiny, potom ji krátce vysvětli a vyhodnoť praktický dopad. Nikdy nezlehčuj ani nepřepisuj oficiální instrukce, nevymýšlej fakta a nepřidávej neověřenou paniku. JMA a místní úřady mají vždy přednost. Vrať pouze JSON objekt s klíči: titleCs (krátký český titulek), translationCs (věrný český překlad podstaty oficiálního textu), plainExplanationCs (1–3 věty lidsky), impactStatus (affected|watch|not_affected), affectedStopIds (pole platných ID), recommendedActions (pole 0–5 konkrétních kroků), urgency (critical|high|medium|low), why (stručné zdůvodnění dopadu), transportImpact (string nebo null), confidence (high|medium|low). Pokud oficiální text neobsahuje konkrétní dopravní dopad, nevymýšlej ho.\nOFICIÁLNÍ UDÁLOST:\n".json_encode($official,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\nITINERÁŘ:\n".json_encode($stops,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
  $body=['contents'=>[['role'=>'user','parts'=>[['text'=>$prompt]]]],'generationConfig'=>['temperature'=>0.05,'maxOutputTokens'=>1200,'responseMimeType'=>'application/json']];
  $ch=curl_init('https://generativelanguage.googleapis.com/v1beta/models/'.EMERGENCY_AI_MODEL.':generateContent');
  curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>25,CURLOPT_POST=>true,CURLOPT_HTTPHEADER=>['Content-Type: application/json','x-goog-api-key: '.$key],CURLOPT_POSTFIELDS=>json_encode($body,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS]);
  $raw=curl_exec($ch);$code=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);curl_close($ch);if(!is_string($raw)||$code!==200)return null;
  $resp=json_decode($raw,true);$text=$resp['candidates'][0]['content']['parts'][0]['text']??null;if(!is_string($text))return null;$ai=json_decode($text,true);if(!is_array($ai)||!validImpact((string)($ai['impactStatus']??'')))return null;
  $known=array_column($itinerary['trip']['stops']??[],'id');$ids=array_values(array_intersect(array_map('strval',$ai['affectedStopIds']??[]),$known));
  $actions=[];foreach(array_slice(is_array($ai['recommendedActions']??null)?$ai['recommendedActions']:[],0,5) as $a){$a=trim((string)$a);if($a!=='')$actions[]=mb_substr($a,0,500);}
  $urgency=in_array($ai['urgency']??'',['critical','high','medium','low'],true)?$ai['urgency']:'medium';
  return ['titleCs'=>mb_substr(trim((string)($ai['titleCs']??'')),0,300),'translationCs'=>mb_substr(trim((string)($ai['translationCs']??'')),0,1800),'plainExplanationCs'=>mb_substr(trim((string)($ai['plainExplanationCs']??'')),0,1200),'impactStatus'=>$ai['impactStatus'],'affectedStopIds'=>$ids,'recommendedActions'=>$actions,'urgency'=>$urgency,'why'=>mb_substr(trim((string)($ai['why']??'')),0,900),'transportImpact'=>isset($ai['transportImpact'])&&is_string($ai['transportImpact'])?mb_substr(trim($ai['transportImpact']),0,900):null,'confidence'=>in_array($ai['confidence']??'',['high','medium','low'],true)?$ai['confidence']:'low','model'=>EMERGENCY_AI_MODEL,'translated'=>true];
}
function mergeInterpretation(array $alert,?array $ai):array{
  $interpretation=$ai?:fallbackCz($alert);$official=$alert['itineraryImpact']??['status'=>'not_affected','text'=>'','affectedStopIds'=>[]];
  $status=impactRank((string)$interpretation['impactStatus'])>impactRank((string)($official['status']??''))?$interpretation['impactStatus']:(string)($official['status']??'not_affected');
  $ids=array_values(array_unique(array_merge($official['affectedStopIds']??[],$interpretation['affectedStopIds']??[])));
  $alert['officialOriginal']=['title'=>$alert['title']??'','summary'=>$alert['summary']??''];
  $alert['titleCs']=$interpretation['titleCs'];$alert['translationCs']=$interpretation['translationCs'];$alert['plainExplanationCs']=$interpretation['plainExplanationCs'];$alert['recommendedActions']=$interpretation['recommendedActions'];$alert['urgency']=$interpretation['urgency'];$alert['why']=$interpretation['why'];$alert['transportImpact']=$interpretation['transportImpact'];
  $alert['itineraryImpact']['status']=$status;$alert['itineraryImpact']['affectedStopIds']=$ids;
  if($ai)$alert['ai']=$interpretation;else $alert['aiFallback']=true;
  return $alert;
}
function shouldAi(array $a):bool{$sev=$a['severity']??'info';$impact=$a['itineraryImpact']['status']??'not_affected';return $sev==='critical'||$sev==='warning'||($sev==='advisory'&&in_array($impact,['affected','watch'],true))||$impact==='affected'||$impact==='watch';}
function shouldPush(array $a):bool{$sev=$a['severity']??'info';$impact=$a['itineraryImpact']['status']??'not_affected';if($sev==='critical')return true;if($sev==='warning')return in_array($impact,['affected','watch'],true);if($sev==='advisory')return $impact==='affected';return false;}
function pushAll(Store $s,array $config,array $alert):array{
  if(!class_exists(WebPush::class)||empty($config['vapid']['publicKey'])||empty($config['vapid']['privateKey']))return ['sent'=>0,'failed'=>0,'targets'=>0,'available'=>false];
  $impact=['affected'=>'ITINERÁŘ DOTČEN','watch'=>'ITINERÁŘ SLEDOVAT','not_affected'=>'ITINERÁŘ NEDOTČEN'][$alert['itineraryImpact']['status']??'not_affected']??'ITINERÁŘ';
  $title=($alert['severity']==='critical'?'🚨':'⚠️').' '.mb_substr((string)($alert['titleCs']??severityCz((string)($alert['severity']??'info')).': '.categoryCz((string)($alert['category']??'other'))),0,110);
  $actions=implode(' ',array_slice($alert['recommendedActions']??[],0,1));$body=trim((string)($alert['plainExplanationCs']??'').' '.$impact.'. '.$actions);$body=mb_substr($body,0,420);
  $payload=json_encode(['title'=>$title,'body'=>$body,'tag'=>'japan-'.substr(hash('sha256',(string)($alert['id']??$title)),0,24),'url'=>'./#japonsko'],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
  $auth=['VAPID'=>['subject'=>$config['vapid']['subject']??($config['origin']??'https://briefing.nacestach.online'),'publicKey'=>$config['vapid']['publicKey'],'privateKey'=>$config['vapid']['privateKey']]];$ttl=$alert['severity']==='critical'?86400:21600;$urgency=$alert['severity']==='critical'?'high':'normal';$wp=new WebPush($auth,['TTL'=>$ttl,'urgency'=>$urgency,'batchSize'=>20,'contentType'=>'application/json']);$wp->setReuseVAPIDHeaders(true);$targets=0;
  foreach($s->query('SELECT id,value FROM subscriptions')->fetchAll() as $row){try{$sub=$s->unseal($row['value']);$wp->queueNotification(Subscription::create($sub),$payload);$targets++;}catch(Throwable $e){}}
  $sent=0;$failed=0;foreach($wp->flush() as $report){$endpoint=$report->getRequest()->getUri()->__toString();$id=hash('sha256',$endpoint);if($report->isSuccess())$sent++;else{$failed++;if(method_exists($report,'isSubscriptionExpired')&&$report->isSubscriptionExpired())$s->query('DELETE FROM subscriptions WHERE id=?',[$id]);}}
  return ['sent'=>$sent,'failed'=>$failed,'targets'=>$targets,'available'=>true];
}

if(($_SERVER['REQUEST_METHOD']??'GET')!=='GET')reply(['error'=>'GET only'],405);if(!allowed())reply(['error'=>'Forbidden'],403);
try{
  $configPath=getenv('BRIEFING_CONFIG') ?: (is_file(__DIR__.'/config-path.php') ? require __DIR__.'/config-path.php' : dirname(__DIR__,2).'/private/config.php');if(!is_file($configPath))reply(['error'=>'Private config missing'],503);$config=require $configPath;require_once $config['serverDir'].'/core.php';$vendor=$config['serverDir'].'/vendor/autoload.php';if(is_file($vendor))require_once $vendor;$s=new Store($config);
  $public=dirname(__DIR__);$rawPath=$public.'/data/emergency-raw.json';$currentPath=$public.'/data/emergency-current.json';$itineraryPath=$public.'/data/japan-itinerary.json';if(!is_file($rawPath)||!is_file($itineraryPath))reply(['error'=>'Emergency raw data missing'],503);$raw=json_decode((string)file_get_contents($rawPath),true,64,JSON_THROW_ON_ERROR);$itinerary=json_decode((string)file_get_contents($itineraryPath),true,64,JSON_THROW_ON_ERROR);
  $stateRaw=$s->query("SELECT value FROM meta WHERE id='emergencyDispatchState'")->fetchColumn();$state=is_string($stateRaw)?json_decode($stateRaw,true):[];if(!is_array($state))$state=[];$events=$state['events']??[];$settings=$s->settings();
  $emergencyKey=(string)($settings['emergencyAiApiKey']??'');$podcastKey=(string)($settings['apiKey']??'');$aiKey=$emergencyKey!==''?$emergencyKey:$podcastKey;$keySource=$emergencyKey!==''?'emergency':($podcastKey!==''?'podcast':'none');
  $changed=0;$aiCalls=0;$aiSuccess=0;$pushes=0;$pushFailed=0;$enriched=[];$now=time();
  foreach($raw['alerts']??[] as $alert){
    $id=(string)($alert['id']??'');if($id==='')continue;$fp=fingerprint($alert);$old=$events[$id]??[];$isChanged=($old['fingerprint']??'')!==$fp;if($isChanged)$changed++;
    $cachedAi=(($old['fingerprint']??'')===$fp&&is_array($old['ai']??null))?$old['ai']:null;$ai=$cachedAi;$attempts=(int)($old['aiAttempts']??0);$lastAttempt=(int)($old['lastAiAttempt']??0);
    $retryNeeded=!$cachedAi&&$aiKey!==''&&shouldAi($alert)&&$attempts<5&&($isChanged||$lastAttempt<$now-120);
    if($retryNeeded){$aiCalls++;$attempts++;$lastAttempt=$now;$candidate=gemini($aiKey,$alert,$itinerary);if($candidate){$ai=$candidate;$aiSuccess++;}}
    $alert=mergeInterpretation($alert,$ai);
    $pushResult=['sent'=>0,'failed'=>0,'targets'=>0,'available'=>false];$notified=(string)($old['notifiedFingerprint']??'');
    if(shouldPush($alert)&&$notified!==$fp){$pushResult=pushAll($s,$config,$alert);$pushes+=$pushResult['sent'];$pushFailed+=$pushResult['failed'];if($pushResult['sent']>0||($pushResult['available']&&$pushResult['targets']===0))$notified=$fp;}
    $events[$id]=['fingerprint'=>$fp,'notifiedFingerprint'=>$notified?:null,'ai'=>$ai,'aiAttempts'=>$attempts,'lastAiAttempt'=>$lastAttempt,'seenAt'=>gmdate(DATE_ATOM)];
    $enriched[]=$alert;
  }
  uasort($events,fn($a,$b)=>strcmp((string)($b['seenAt']??''),(string)($a['seenAt']??'')));$events=array_slice($events,0,250,true);$state=['updatedAt'=>gmdate(DATE_ATOM),'events'=>$events];$s->query('INSERT OR REPLACE INTO meta VALUES(?,?)',['emergencyDispatchState',json_encode($state,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);
  $current=['schemaVersion'=>1,'kind'=>'japan-emergency-status','generatedAt'=>$raw['generatedAt']??(new DateTimeImmutable('now',new DateTimeZone('Asia/Tokyo')))->format(DATE_ATOM),'pollIntervalSeconds'=>60,'alerts'=>$enriched,'sourceStatus'=>$raw['sourceStatus']??[],'advisory'=>'Český překlad a interpretace jsou pomocná vrstva. Pro životně důležitá rozhodnutí mají vždy přednost JMA, J-Alert, místní úřady a pokyny personálu.','dispatch'=>['processedAt'=>(new DateTimeImmutable('now',new DateTimeZone('Asia/Tokyo')))->format(DATE_ATOM),'changed'=>$changed,'aiCalls'=>$aiCalls,'aiSuccess'=>$aiSuccess,'pushSent'=>$pushes,'pushFailed'=>$pushFailed,'aiEnabled'=>$aiKey!=='','aiKeySource'=>$keySource,'model'=>EMERGENCY_AI_MODEL]];
  $tmp=$currentPath.'.dispatch.'.bin2hex(random_bytes(4));$encoded=json_encode($current,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT);if(file_put_contents($tmp,$encoded,LOCK_EX)===false||!rename($tmp,$currentPath)){@unlink($tmp);reply(['error'=>'write failed'],500);}reply(['ok'=>true,'changed'=>$changed,'aiCalls'=>$aiCalls,'aiSuccess'=>$aiSuccess,'pushSent'=>$pushes,'pushFailed'=>$pushFailed,'aiEnabled'=>$aiKey!=='','aiKeySource'=>$keySource,'model'=>EMERGENCY_AI_MODEL]);
}catch(Throwable $e){reply(['error'=>'Emergency dispatch failed'],500);}
