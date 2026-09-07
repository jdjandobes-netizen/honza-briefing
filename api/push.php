<?php
declare(strict_types=1);
use Briefing\Store;
ini_set('display_errors','0');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
function reply(array $v,int $status=200):never{http_response_code($status);echo json_encode($v,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;}
try{
  $configPath=getenv('BRIEFING_CONFIG') ?: (is_file(__DIR__.'/config-path.php') ? require __DIR__.'/config-path.php' : dirname(__DIR__,2).'/private/config.php');
  if(!is_file($configPath)) reply(['enabled'=>false,'error'=>'Push backend není nastavený.'],503);
  $config=require $configPath;
  require_once $config['serverDir'].'/core.php';
  $vendor=$config['serverDir'].'/vendor/autoload.php';
  $enabled=is_file($vendor)&&!empty($config['vapid']['publicKey'])&&!empty($config['vapid']['privateKey']);
  $action=(string)($_GET['action']??'config');
  $method=$_SERVER['REQUEST_METHOD']??'GET';
  if($action==='config'&&$method==='GET') reply(['enabled'=>$enabled,'publicKey'=>$enabled?$config['vapid']['publicKey']:null]);
  if(!$enabled) reply(['error'=>'Push backend není aktivní.'],503);
  if(!in_array($method,['POST'],true)) reply(['error'=>'Nepodporovaná metoda.'],405);
  if(($_SERVER['HTTP_ORIGIN']??'')!==($config['origin']??'')) reply(['error'=>'Neplatný origin.'],403);
  if(!str_starts_with($_SERVER['CONTENT_TYPE']??'','application/json')) reply(['error'=>'Požadavek musí být JSON.'],415);
  $raw=file_get_contents('php://input',false,null,0,16385);
  if(!is_string($raw)||strlen($raw)>16384) reply(['error'=>'Požadavek je příliš velký.'],413);
  $input=json_decode($raw,true,32,JSON_THROW_ON_ERROR);
  if(!is_array($input)) reply(['error'=>'Neplatný požadavek.'],400);
  $s=new Store($config);
  $ip=hash('sha256',$_SERVER['REMOTE_ADDR']??'unknown');
  if(!$s->rate('push-'.$ip,30,3600)) reply(['error'=>'Příliš mnoho požadavků.'],429);
  if($action==='subscribe'){
    $sub=$input['subscription']??[];
    if(!Briefing\validSubscription($sub)) reply(['error'=>'Nepodporované push předplatné.'],400);
    $id=hash('sha256',$sub['endpoint']);
    $old=$s->query('SELECT value FROM subscriptions WHERE id=?',[$id])->fetchColumn();
    $sub['createdAt']=$old?($s->unseal($old)['createdAt']??gmdate(DATE_ATOM)):gmdate(DATE_ATOM);
    $sub['purpose']='emergency';
    if(!$old&&(int)$s->query('SELECT COUNT(*) FROM subscriptions')->fetchColumn()>=20) reply(['error'=>'Je připojeno příliš mnoho zařízení.'],409);
    $s->query('INSERT OR REPLACE INTO subscriptions VALUES(?,?)',[$id,$s->seal($sub)]);
    reply(['ok'=>true]);
  }
  if($action==='unsubscribe'){
    $endpoint=(string)($input['endpoint']??'');
    if($endpoint==='') reply(['error'=>'Chybí endpoint.'],400);
    $s->query('DELETE FROM subscriptions WHERE id=?',[hash('sha256',$endpoint)]);
    reply(['ok'=>true]);
  }
  reply(['error'=>'Neznámá akce.'],404);
}catch(Throwable $e){reply(['error'=>'Push služba požadavek nedokončila.'],500);}
