<?php
require __DIR__.'/backend/core.php';
header('Cache-Control: no-store');header('X-Robots-Tag: noindex, nofollow');header('X-Content-Type-Options: nosniff');
if(empty($_SERVER['HTTPS'])||$_SERVER['HTTPS']==='off'){http_response_code(403);exit('Откройте установку по HTTPS с действительным сертификатом.');}
ini_set('display_errors','0');ini_set('session.use_strict_mode','1');session_name('cwsetup');
$dir=private_dir();$sd=$dir.'/sessions';if(!is_dir($sd))mkdir($sd,0700);session_save_path($sd);
session_set_cookie_params(['secure'=>true,'httponly'=>true,'samesite'=>'Strict','path'=>'/']);session_start();$_SESSION['csrf']??=bin2hex(random_bytes(32));
$keyFile=$dir.'/setup.key';if(is_file($dir.'/admin.json')||!is_file($keyFile)){http_response_code(404);exit('Установка недоступна.');}
$error='';$done=false;
if($_SERVER['REQUEST_METHOD']==='POST'){
 try{
  rate_limit('setup',5,1800);
  if(!hash_equals($_SESSION['csrf'],$_POST['csrf']??''))fail('Обновите страницу');
  $key=trim(file_get_contents($keyFile));if(strlen($key)<32||!hash_equals($key,(string)($_POST['key']??'')))fail('Неверный одноразовый ключ');
  $pass=(string)($_POST['password']??'');if(strlen($pass)<16||strlen($pass)>200||$pass!==($_POST['repeat']??''))fail('Минимум 16 символов; пароли должны совпадать');
  $lock=fopen($dir.'/setup.lock','c');flock($lock,LOCK_EX);
  try{if(is_file($dir.'/admin.json'))fail('Администратор уже создан');$tmp=tempnam($dir,'admin-');chmod($tmp,0600);file_put_contents($tmp,json_encode(['password'=>password_hash($pass,PASSWORD_DEFAULT)]));rename($tmp,$dir.'/admin.json');unlink($keyFile);$done=true;}finally{flock($lock,LOCK_UN);fclose($lock);}
 }catch(Throwable $e){$error=$e instanceof DomainException?$e->getMessage():'Ошибка установки. Проверьте каталог данных.';}
}
function h($v){return htmlspecialchars($v,ENT_QUOTES,'UTF-8');}
?><!doctype html><html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex"><title>Первичная настройка — Чистая вода</title><link rel="stylesheet" href="premium.css"></head><body><main class="wrap section"><section class="account-panel3"><span class="kicker blue">ПЕРВИЧНАЯ НАСТРОЙКА</span><h1>Доступ владельца</h1><?php if($done): ?><p>Пароль сохранён. Одноразовый ключ уничтожен.</p><a class="button primary full" href="admin.html">Открыть панель</a><?php else: ?><p class="muted">Введите ключ из файла setup.key вне папки сайта.</p><p role="alert"><?=h($error)?></p><form method="post"><input type="hidden" name="csrf" value="<?=h($_SESSION['csrf'])?>"><label>Одноразовый ключ<input name="key" type="password" minlength="32" required autocomplete="off"></label><label>Пароль владельца<input name="password" type="password" minlength="16" maxlength="100" required autocomplete="new-password"></label><label>Повторите пароль<input name="repeat" type="password" minlength="16" maxlength="100" required autocomplete="new-password"></label><button class="button primary full">Сохранить пароль</button></form><?php endif ?></section></main></body></html>
