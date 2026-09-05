<?php
declare(strict_types=1);
// Run only over SSH. All generated secrets remain in the private directory.
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
$private=$argv[1]??''; $public=$argv[2]??''; $origin=$argv[3]??'';
$publicBase=$argv[4]??'/';
if (!preg_match('~^/(?:[a-z0-9-]+/)*$~D',$publicBase)) exit("Invalid public base.\n");
if (!$private || !$public || !preg_match('~^https://[a-z0-9.-]+$~D',$origin) || !is_dir($public)) exit("Usage: php install.php /absolute/private /absolute/public https://briefing.example.cz\n");
if (!is_dir($private)) mkdir($private,0700,true);
$private=realpath($private); $public=realpath($public);
if ($private===$public || str_starts_with($private.'/', $public.'/')) exit("Private directory must be outside the web root.\n");
if (file_exists($private.'/config.php')) exit("Already configured; configuration was not overwritten.\n");
require_once __DIR__.'/vendor/autoload.php';
$vapid=\Minishlink\WebPush\VAPID::createVapidKeys();
$token=bin2hex(random_bytes(32));
$config=['origin'=>$origin,'publicBase'=>$publicBase,'publicDir'=>$public,'dataDir'=>$private.'/runtime','serverDir'=>__DIR__,'ffmpeg'=>'/usr/bin/ffmpeg','encryptionKey'=>base64_encode(random_bytes(32)),
    'setupTokenHash'=>hash('sha256',$token),'setupExpires'=>time()+86400,'vapid'=>$vapid+['subject'=>$origin]];
file_put_contents($private.'/config.php',"<?php\nreturn ".var_export($config,true).";\n",LOCK_EX); chmod($private.'/config.php',0600);
file_put_contents($private.'/setup-url.txt',$origin.$publicBase.'#setup='.$token."\n",LOCK_EX); chmod($private.'/setup-url.txt',0600);
echo "Configuration created. One-time setup link saved privately in setup-url.txt (valid 24 hours).\n";
