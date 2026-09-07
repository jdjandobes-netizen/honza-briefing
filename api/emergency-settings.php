<?php
declare(strict_types=1);
use Briefing\Store;
ini_set('display_errors','0');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
function reply(array $v,int $status=200):never{http_response_code($status);echo json_encode($v,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;}
function cleanKey(mixed $value):string{
  if(!is_string($value)) throw new RuntimeException('API klíč musí být text.');
  $key=preg_replace('/\A[\s\p{Z}\x{FEFF}]+|[\s\p{Z}\x{FEFF}]+\z/u','',$value);
  if($key===null) throw new RuntimeException('API klíč obsahuje nepodporované znaky.');
  if(strlen($key)>4096) throw new RuntimeException('API klíč je příliš dlouhý.');
  if($key!==''&&!preg_match('/\A[A-Za-z0-9._~+\/=-]+\z/D',$key)) throw new RuntimeException('Vlož pouze API klíč bez mezer nebo zalomení řádku.');
  return $key;
}
try{
  $configPath=getenv('BRIEFING_CONFIG') ?: (is_file(__DIR__.'/config-path.php') ? require __DIR__.'/config-path.php' : dirname(__DIR__,2).'/private/config.php');
  if(!is_file($configPath)) reply(['error'=>'Privátní konfigurace není dostupná.'],503);
  $config=require $configPath; require_once $config['serverDir'].'/core.php'; $s=new Store($config);
  $origin=$config['origin']??''; $secure=str_starts_with($origin,'https://'); $cookiePath=$config['publicBase']??'/';
  session_name('honza_podcast_'.substr(hash('sha256',$cookiePath),0,8));
  session_set_cookie_params(['lifetime'=>2592000,'path'=>$cookiePath,'secure'=>$secure,'httponly'=>true,'samesite'=>'Strict']);
  ini_set('session.use_strict_mode','1'); session_start(); $_SESSION['csrf']??=bin2hex(random_bytes(32));
  $csrf=$_SESSION['csrf']; $auth=isset($_SESSION['authenticatedAt'])&&$_SESSION['authenticatedAt']>time()-2592000;
  if(!$auth){session_write_close();reply(['error'=>'Přihlas se v nastavení podcastu.'],401);} $method=$_SERVER['REQUEST_METHOD']??'GET';
  if($method==='GET'){
    session_write_close(); $settings=$s->settings();
    reply(['hasEmergencyAiKey'=>!empty($settings['emergencyAiApiKey']),'usesPodcastKey'=>empty($settings['emergencyAiApiKey'])&&!empty($settings['apiKey']),'hasPodcastKey'=>!empty($settings['apiKey']),'model'=>'gemini-3.8-flash','csrf'=>$csrf]);
  }
  if($method!=='POST') reply(['error'=>'Nepodporovaná metoda.'],405);
  if(($_SERVER['HTTP_ORIGIN']??'')!==$origin||!hash_equals($csrf,$_SERVER['HTTP_X_CSRF_TOKEN']??'')) reply(['error'=>'Obnov stránku a zkus to znovu.'],403);
  if(!str_starts_with($_SERVER['CONTENT_TYPE']??'','application/json')) reply(['error'=>'Požadavek musí být JSON.'],415);
  $raw=file_get_contents('php://input',false,null,0,16385); if(!is_string($raw)||strlen($raw)>16384) reply(['error'=>'Požadavek je příliš velký.'],413);
  $input=json_decode($raw,true,32,JSON_THROW_ON_ERROR); if(!is_array($input)) reply(['error'=>'Neplatný požadavek.'],400);
  $s->tx(function() use($s,$input){$settings=$s->settings(); if(!empty($input['removeEmergencyKey'])){$settings['emergencyAiApiKey']='';} else { $key=cleanKey($input['emergencyAiApiKey']??''); if($key!=='')$settings['emergencyAiApiKey']=$key; } $s->saveSettings($settings);});
  $settings=$s->settings(); session_write_close();
  reply(['ok'=>true,'hasEmergencyAiKey'=>!empty($settings['emergencyAiApiKey']),'usesPodcastKey'=>empty($settings['emergencyAiApiKey'])&&!empty($settings['apiKey']),'hasPodcastKey'=>!empty($settings['apiKey']),'model'=>'gemini-3.8-flash']);
}catch(Throwable $e){$safe=($e instanceof RuntimeException)&&!($e instanceof PDOException);reply(['error'=>$safe?$e->getMessage():'Nastavení AI se nepodařilo uložit.'],400);}
