<?php
require __DIR__.'/core.php';
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
$command=$argv[1]??'';
if($command==='admin'){
    echo "Новый пароль администратора (минимум 16 символов). Ввод из STDIN: ";
    if(function_exists('shell_exec'))@shell_exec('stty -echo 2>/dev/null');
    $pass=rtrim(fgets(STDIN),"\r\n");if(function_exists('shell_exec'))@shell_exec('stty echo 2>/dev/null');echo "\n";
    if(strlen($pass)<16){fwrite(STDERR,"Короткий пароль\n");exit(1);}
    $p=private_dir().'/admin.json';file_put_contents($p,json_encode(['password'=>password_hash($pass,PASSWORD_DEFAULT)]),LOCK_EX);chmod($p,0600);echo "Администратор настроен\n";
}elseif($command==='bridge-token'){
    $token=bin2hex(random_bytes(32));$file=private_dir().'/bridge.json';file_put_contents($file,json_encode(['hash'=>hash('sha256',$token)]));chmod($file,0600);echo "Ключ интеграции (сохраните, показывается один раз): $token\n";
}elseif($command==='backup'){
    $s=transaction(fn(&$s)=>$s,false);$path=private_dir().'/manual-'.date('Ymd-His').'.json';file_put_contents($path,json_encode($s,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));chmod($path,0600);echo "$path\n";
}elseif($command==='maintenance'){
    echo json_encode(maintenance(in_array('--apply',$argv,true)),JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)."\n";
}elseif($command==='notifications'){
    echo json_encode(process_notices(20),JSON_UNESCAPED_UNICODE)."\n";
}elseif($command==='full-backup'){
    $b=transaction(fn(&$s)=>backup_bundle($s),false);$path=private_dir().'/manual-full-'.date('Ymd-His').'.json';atomic_json($path,$b);echo $path."\n";
}elseif($command==='verify-backup'||$command==='restore-full'){
    $b=json_decode(file_get_contents($argv[2]??''),true,512,JSON_THROW_ON_ERROR);
    echo json_encode(restore_bundle($b,$command==='restore-full'&&in_array('--apply',$argv,true)),JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)."\n";
}elseif($command==='restore'){
    $file=$argv[2]??'';$s=json_decode(file_get_contents($file),true,512,JSON_THROW_ON_ERROR);
    if(($s['schema']??null)!==1||!isset($s['products'],$s['orders'],$s['users'],$s['events'],$s['subscriptions']))throw new RuntimeException('Неверный формат');
    validate_state($s);transaction(function(&$state)use($s){if(site_settings($state)['orders_enabled'])fail('Приостановите приём заказов перед восстановлением');$s['staff']=$state['staff']??[];apply_erasure($s,tombstones());$s['settings']=site_settings($s);$s['settings']['orders_enabled']=false;foreach($s['users'] as &$u)$u['auth_version']=($u['auth_version']??0)+1;unset($u);$s['otp']=[];foreach($s['outbox']??[] as $k=>$v)$s['outbox'][$k]['status']='dead';$state=$s;});echo "Восстановлено. Сначала переведите сайт в режим обслуживания на время восстановления.\n";
}elseif($command==='health'){
    echo 'PHP '.PHP_VERSION."\n";echo 'Каталог данных: '.private_dir()."\n";transaction(fn(&$s)=>count($s['orders']),false);echo "Хранилище доступно\n";
}else echo "php backend/manage.php admin|bridge-token|backup|full-backup|verify-backup FILE|restore-full FILE --apply|maintenance [--apply]|notifications|health\n";
