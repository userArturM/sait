<?php
declare(strict_types=1);
// Operational services. All changes use the same state lock as orders.
function atomic_json(string $path,array $data): void {
    $tmp=tempnam(dirname($path),'write-');chmod($tmp,0600);
    try{$json=json_encode($data,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);if(file_put_contents($tmp,$json)!==strlen($json)||!rename($tmp,$path))throw new RuntimeException('Write failed');}finally{if(is_file($tmp))unlink($tmp);}
}
function tombstones(): array {$p=private_dir().'/erasure.json';return is_file($p)?json_decode(file_get_contents($p),true,512,JSON_THROW_ON_ERROR):['users'=>[],'orders'=>[]];}
function trim_technical(array &$s): void {
    foreach($s['otp']??[] as $id=>$v){if(($v['created']??0)<time()-86400)unset($s['otp'][$id]);elseif(($v['expires']??0)<time())unset($s['otp'][$id]['hash']);}
    $s['events']=array_values(array_filter($s['events'],fn($e)=>strtotime($e['at'])>time()-30*86400));
}
function apply_erasure(array &$s,array $t): void {
    trim_technical($s);
    foreach($t['users'] as $id=>$at){$phone=$s['users'][$id]['phone']??null;if($phone)foreach($s['otp']??[] as $key=>$otp)if($otp['phone']===$phone)unset($s['otp'][$key]);unset($s['users'][$id]);foreach($s['subscriptions'] as $sid=>$sub)if($sub['user_id']===$id)unset($s['subscriptions'][$sid]);foreach($s['orders'] as &$o)if(($o['user_id']??null)===$id)$o['user_id']=null;unset($o);}
    foreach($t['orders'] as $id=>$at)if(isset($s['orders'][$id])){$o=&$s['orders'][$id];foreach(['name','phone','address','comment','email','owner','request_id','fingerprint'] as $k)$o[$k]='';$o['user_id']=null;$o['personal_data_erased_at']=$at;unset($o);}
    foreach($s['outbox']??[] as $key=>$job)if(isset($t['orders'][$job['order_id']]))unset($s['outbox'][$key]);
    // Events must never bring back an erased person's identifiers or addresses.
    if($t['users']||$t['orders'])$s['events']=array_values(array_filter($s['events'],function($e)use($t){return !isset($t['users'][$e['actor']??''])&&!isset($t['orders'][$e['details']['id']??''])&&!isset($t['users'][$e['details']['id']??'']);}));
}
function privacy_plan(array $s,int $now): array {
    $users=[];$orders=[];$blocked=[];$legacy=[];
    foreach($s['privacy_requests']??[] as $id=>$r)if($r['status']==='pending'&&isset($s['users'][$id]))$users[]=$id;
    foreach($s['orders'] as $id=>$o){if(!empty($o['personal_data_erased_at']))continue;
        if(!in_array($o['status'],['delivered','cancelled'])||($o['status']==='delivered'&&$o['paid']<$o['total']))continue;
        if(!empty($o['legal_hold'])){$blocked[]=$id;continue;}
        $closedAt=$o['closed_at']??null;if(!$closedAt)foreach($s['events'] as $event)if(($event['details']['id']??'')===$id&&in_array($event['details']['after']['status']??'', ['delivered','cancelled']))$closedAt=$event['at'];if(!$closedAt){$legacy[]=$id;continue;}$closed=strtotime($closedAt);
        if($closed!==false&&$closed<=$now-config()['order_contact_days']*86400)$orders[]=$id;
    }return ['users'=>$users,'orders'=>$orders,'legal_holds'=>$blocked,'legacy_review'=>$legacy];
}
function maintenance(bool $apply=false): array {
    return transaction(function(&$s)use($apply){$now=time();$plan=privacy_plan($s,$now);if(!$apply)return $plan;
        $t=tombstones();foreach($plan['users'] as $id)$t['users'][$id]=date('c');foreach($plan['orders'] as $id)$t['orders'][$id]=date('c');
        // Durable deny-list first: even an interrupted run or an old restore cannot resurrect erased data.
        atomic_json(private_dir().'/erasure.json',$t);foreach($plan['orders'] as $oid)$s['external_cleanup'][$oid]??=['order_id'=>$oid,'water19_id'=>$s['orders'][$oid]['water19_id']??null,'requested_at'=>date('c'),'status'=>'pending'];apply_erasure($s,$t);
        foreach($plan['users'] as $id){$s['privacy_requests'][$id]['status']='account_erased';$s['privacy_requests'][$id]['completed_at']=date('c');}
        $s['events']=array_values(array_filter($s['events'],fn($e)=>strtotime($e['at'])>$now-30*86400));
        foreach($s['otp']??[] as $id=>$v){if($v['created']<$now-86400)unset($s['otp'][$id]);elseif($v['expires']<$now)unset($s['otp'][$id]['hash']);}
        foreach($s['outbox']??[] as $id=>$v)if(($v['status']??'')==='sent'&&strtotime($v['updated_at']??$v['created_at'])<$now-30*86400)unset($s['outbox'][$id]);
        foreach(array_merge(glob(private_dir().'/backups/*.json')?:[],glob(private_dir().'/manual-*.json')?:[]) as $p){$mtime=filemtime($p);if($mtime<$now-30*86400){unlink($p);continue;}$b=json_decode(file_get_contents($p),true,512,JSON_THROW_ON_ERROR);if(isset($b['schema']))apply_erasure($b,$t);elseif(($b['format']??'')==='cw-full-v1'){apply_erasure($b['payload']['state'],$t);$b['sha256']=hash('sha256',json_encode($b['payload'],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));}atomic_json($p,$b);touch($p,$mtime);}
        $s['maintenance']=['last_run'=>date('c'),'erased_accounts'=>count($plan['users']),'erased_order_contacts'=>count($plan['orders']),'external_cleanup_required'=>count(array_filter($s['external_cleanup']??[],fn($x)=>$x['status']==='pending'))>0];
        atomic_json(private_dir().'/last-erasure-report.json',['operator'=>'ИП Манукян Артур Баградович','at'=>date('c'),'basis'=>'account request / completed and settled order retention','account_ids'=>$plan['users'],'order_ids'=>$plan['orders'],'external_cleanup_required'=>$plan['orders'],'managed_backups_processed'=>true]);event($s,'privacy_maintenance',['accounts'=>count($plan['users']),'orders'=>count($plan['orders'])]);return $plan;
    },$apply);
}
function request_erasure(array &$s,string $uid,string $password): array {
    if(!password_verify($password,$s['users'][$uid]['password']))fail('Текущий пароль неверен',403);
    $s['privacy_requests'][$uid]??=['id'=>$uid,'requested_at'=>date('c'),'due_at'=>date('c',time()+30*86400),'status'=>'pending'];
    return $s['privacy_requests'][$uid];
}
function check_delivery(string $town,string $address,?array $s=null): array {
    $zones=delivery_zones($s);if(!isset($zones[$town])||!$zones[$town]['active'])fail('Адрес вне стандартной зоны. Согласуйте доставку по телефону');
    // Structured settlement is authoritative; reject a contradictory explicitly typed settlement.
    foreach($zones as $key=>$z)if($key!==$town&&preg_match('/'.preg_quote($z['name'],'/').'/ui',$address))fail('Населённый пункт в адресе не совпадает с выбранным');
    if(!preg_match('/[0-9]/u',$address)||strlen(trim($address))<5)fail('Укажите улицу и номер дома');
    return $zones[$town]+['validation'=>'settlement_and_house','geocoded'=>false];
}
function enqueue_notice(array &$s,string $id,string $kind): void {
    $key=$id.':'.$kind;$s['outbox'][$key]??=['id'=>$key,'order_id'=>$id,'kind'=>$kind,'status'=>'pending','attempts'=>0,'next_at'=>0,'created_at'=>date('c')];
}
function notice_text(array $o,string $kind): array {
    if(str_starts_with($kind,'owner'))return [config()['mail_to'],'Новый заказ '.$o['id'],'Заказ '.$o['id'].' сохранён. Откройте защищённую панель сайта.'];
    $labels=['new'=>'Получен','confirmed'=>'Подтверждён','on_route'=>'В пути','delivered'=>'Доставлен','cancelled'=>'Отменён'];
    $text='Чистая вода. Заказ '.$o['id']."\nСтатус: ".($labels[$o['status']]??$o['status'])."\n";
    if(!empty($o['delivery_fee']))$text.='Доставка: '.$o['delivery_fee']." руб.\n";
    foreach($o['items'] as $i)$text.=$i['name'].' × '.$i['qty'].' = '.($i['price']*$i['qty'])." руб.\n";
    $text.='Итого: '.$o['total']." руб.\n".($o['confirmed_date']??$o['date']).' '.($o['confirmed_slot']??$o['slot']).(empty($o['confirmed_date'])?' (желаемое время; согласуем по телефону)':' (согласовано)')."\nТелефон: +7 928 223-53-33\nЭто подтверждение, не кассовый чек.";
    return [$o['email']??'','Заказ '.$o['id'].' - '.($labels[$o['status']]??$o['status']),$text];
}
function process_notices(int $limit=10,?callable $sender=null): array {
    if(!$sender&&(!config()['mail_enabled']||!filter_var(config()['mail_from'],FILTER_VALIDATE_EMAIL)))return ['enabled'=>false,'processed'=>0];
    transaction(function(&$s){foreach($s['orders'] as $o)if($o['status']==='new'&&strtotime($o['created_at'])<time()-config()['new_order_alert_minutes']*60)enqueue_notice($s,$o['id'],'owner-reminder');});
    $count=0;
    while($count<$limit){$token=bin2hex(random_bytes(12));$job=transaction(function(&$s)use($token){foreach($s['outbox']??[] as $id=>&$j){if($j['status']==='sent'||$j['status']==='dead'||($j['next_at']??0)>time()||($j['status']==='sending'&&($j['lease_until']??0)>time()))continue;
            $o=$s['orders'][$j['order_id']]??null;if(!$o||!empty($o['personal_data_erased_at'])){$s['outbox'][$id]['status']='dead';continue;}
            $s['outbox'][$id]['status']='sending';$s['outbox'][$id]['lease']=$token;$s['outbox'][$id]['lease_until']=time()+120;$s['outbox'][$id]['attempts']++;return ['id'=>$id,'order'=>$o,'kind'=>$j['kind']];}return null;});
        if(!$job)break;[$to,$subject,$body]=notice_text($job['order'],$job['kind']);$ok=false;
        if(filter_var($to,FILTER_VALIDATE_EMAIL)){try{$ok=$sender?(bool)$sender($to,$subject,$body,$job['id']):@mail($to,'=?UTF-8?B?'.base64_encode($subject).'?=',$body,"Content-Type: text/plain; charset=UTF-8\r\nFrom: ".config()['mail_from']."\r\nMessage-ID: <".hash('sha256',$job['id']).'@'.substr(strrchr(config()['mail_from'],'@'),1).'>');}catch(Throwable $e){$ok=false;}}
        transaction(function(&$s)use($job,$token,$ok){if(!isset($s['outbox'][$job['id']]))return;$j=&$s['outbox'][$job['id']];if(($j['lease']??'')!==$token)return;$j['status']=$ok?'sent':($j['attempts']>=5?'dead':'failed');$j['updated_at']=date('c');$j['next_at']=time()+min(3600,60*(2**$j['attempts']));if($job['kind']==='owner')$s['orders'][$job['order']['id']]['mail_status']=$j['status'];});$count++;
    }return ['enabled'=>true,'processed'=>$count];
}
function sms_ready(): bool {return config()['sms_enabled']&&config()['sms_api_id']!=='';}
function sms_send(string $phone,string $code): bool {
    if(!sms_ready())return false;
    $body=http_build_query(['api_id'=>config()['sms_api_id'],'to'=>ltrim($phone,'+'),'msg'=>'Чистая вода: код '.$code.'. Никому не сообщайте. Действует 5 минут.','json'=>1]);
    $ctx=stream_context_create(['http'=>['method'=>'POST','header'=>"Content-Type: application/x-www-form-urlencoded\r\n",'content'=>$body,'timeout'=>8,'ignore_errors'=>true],'ssl'=>['verify_peer'=>true,'verify_peer_name'=>true]]);
    $result=json_decode((string)@file_get_contents('https://sms.ru/sms/send',false,$ctx),true);
    return ($result['status']??'')==='OK'&&($result['sms'][ltrim($phone,'+')]['status']??'')==='OK';
}
function otp_request(string $phone,string $purpose,?callable $sender=null): string {
    if(!$sender&&!sms_ready())fail('SMS-подтверждение ещё не подключено',503);
    if(!in_array($purpose,['register','verify','recover']))fail('Неверное назначение кода');
    $id=bin2hex(random_bytes(16));$code=(string)random_int(100000,999999);$owner=$_SESSION['order_owner'];
    transaction(function(&$s)use($id,$code,$phone,$purpose,$owner){$recent=0;$global=0;foreach($s['otp']??[] as $o){if($o['created']>time()-86400)$global++;if($o['phone']===$phone&&$o['created']>time()-3600){$recent++;if($o['created']>time()-60)fail('Повторный код можно запросить через минуту',429);}}
        if($recent>=3||$global>=config()['sms_daily_limit'])fail('Лимит SMS исчерпан. Попробуйте позже',429);
        $s['otp'][$id]=['phone'=>$phone,'purpose'=>$purpose,'owner'=>$owner,'hash'=>password_hash($code,PASSWORD_DEFAULT),'created'=>time(),'expires'=>time()+300,'attempts'=>0,'sent'=>false];});
    $sent=false;try{$sent=$sender?(bool)$sender($phone,$code):sms_send($phone,$code);}catch(Throwable $e){}
    transaction(function(&$s)use($id,$sent){$s['otp'][$id]['sent']=$sent;});if(!$sent)fail('Не удалось отправить SMS. Попробуйте позднее',503);return $id;
}
function otp_consume(string $id,string $code,string $phone,string $purpose): void {
    $valid=transaction(function(&$s)use($id,$code,$phone,$purpose){if(!isset($s['otp'][$id]))return false;$o=&$s['otp'][$id];
        if(!$o['sent']||$o['expires']<time()||$o['attempts']>=5||$o['phone']!==$phone||$o['purpose']!==$purpose||!hash_equals($o['owner'],$_SESSION['order_owner'])||!empty($o['used']))return false;
        $o['attempts']++;if(!password_verify($code,$o['hash']))return false;$o['used']=true;unset($o['hash']);return true;});
    if(!$valid)fail('Код неверен, истёк или уже использован',400);
}
function backup_bundle(array $s): array {
    $files=[];foreach(['assets/uploads','legal'] as $prefix){$root=$prefix==='legal'?private_dir().'/legal':dirname(__DIR__).'/assets/uploads';foreach(glob($root.'/*')?:[] as $p){if(!is_file($p)||is_link($p))continue;$rel=$prefix.'/'.basename($p);$bytes=file_get_contents($p);$files[$rel]=['sha256'=>hash('sha256',$bytes),'data'=>base64_encode($bytes)];}}
    $payload=['state'=>$s,'files'=>$files,'erasure'=>tombstones()];return ['format'=>'cw-full-v1','created_at'=>date('c'),'sha256'=>hash('sha256',json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)),'payload'=>$payload];
}
function validate_state(array $s): void {
    if(($s['schema']??null)!==1)fail('Неизвестная схема копии');foreach(['products','orders','users','events','subscriptions'] as $k)if(!is_array($s[$k]??null))fail('Неполная копия: '.$k);
    if(isset($s['logistics']))validate_logistics($s['logistics']);
    foreach($s['staff']??[] as $id=>$u)if(($u['id']??null)!==$id||!is_string($u['password']??null)||!is_bool($u['active']??null)||!in_array($u['role']??'', ['dispatcher','courier']))fail('Неверная запись сотрудника');
    $ids=[];foreach($s['products'] as $p){if(!is_string($p['id']??null)||isset($ids[$p['id']]))fail('Повтор или отсутствие ID товара');$ids[$p['id']]=true;foreach(['store','delivery'] as $k)integer($p[$k]??null,0,100050);if(array_key_exists('delivery_prices',$p))validate_delivery_prices($p['delivery_prices']);if(($p['stock']??null)!==null)integer($p['stock'],0,100000);}
    foreach($s['orders'] as $id=>$o){if(($o['id']??null)!==$id||!in_array($o['status']??'', ['new','confirmed','on_route','delivered','cancelled']))fail('Неверный заказ');$sum=0;if(!is_array($o['items']??null)||!$o['items'])fail('Нет позиций заказа');foreach($o['items'] as $i){if(!isset($ids[$i['id']??'']))fail('Неизвестный товар в заказе');$sum+=integer($i['qty']??null,1,99)*integer($i['price']??null,0,100050);}if(($sum+=integer($o['delivery_fee']??0,0,100000))!==($o['total']??null))fail('Нарушен итог заказа');integer($o['paid']??null,0,$sum);if($o['status']==='cancelled'&&$o['paid']!==0)fail('Отмена с оплатой');if(!empty($o['user_id'])&&!isset($s['users'][$o['user_id']]))fail('Нарушена связь кабинета');}
    foreach($s['products'] as $p)if($p['stock']!==null&&reserved($s,$p['id'])>$p['stock'])fail('Резерв превышает остаток');
    foreach($s['users'] as $id=>$u)if(($u['id']??null)!==$id||!is_string($u['password']??null)||!is_array($u['addresses']??null))fail('Неверный аккаунт');
    foreach($s['subscriptions'] as $x)if(!isset($s['users'][$x['user_id']??''])||!isset($s['orders'][$x['order_id']??'']))fail('Нарушена связь подписки');
}
function validate_bundle(array $b): array {
    if(($b['format']??'')!=='cw-full-v1')fail('Нужна полная копия cw-full-v1');$p=$b['payload']??[];if(!is_array($p['files']??null)||!is_array($p['state']??null))fail('Неполная копия');
    if(!hash_equals($b['sha256']??'',hash('sha256',json_encode($p,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR))))fail('Контрольная сумма копии не совпадает');validate_state($p['state']);
    foreach($p['files'] as $path=>$f){if(!preg_match('~^(legal/[a-f0-9]{64}\.html|assets/uploads/(?:[a-f0-9]{32}\.(?:png|jpg|webp)|index\.html))$~D',$path))fail('Недопустимый путь копии');$bytes=base64_decode($f['data'],true);if($bytes===false||strlen($bytes)>5000000||!hash_equals($f['sha256'],hash('sha256',$bytes)))fail('Файл копии повреждён');}
    foreach($p['state']['products'] as $product)foreach(['image','label_image'] as $field){$image=$product[$field]??'';if(str_starts_with($image,'assets/uploads/')&&!isset($p['files'][$image]))fail('Нет загруженного фото товара');}
    foreach($p['state']['users'] as $user)foreach($user['document_hashes']??[] as $hash)if(!isset($p['files']['legal/'.$hash.'.html']))fail('Нет редакции согласия кабинета');
    foreach($p['state']['orders'] as $o)foreach($o['consents']['document_hashes']??[] as $hash)if(!isset($p['files']['legal/'.$hash.'.html']))fail('Нет архивной редакции документа');return $p;
}
function restore_bundle(array $b,bool $apply=false): array {
    $payload=validate_bundle($b);$next=$payload['state'];$merged=tombstones();foreach(['users','orders'] as $k)$merged[$k]=array_replace($payload['erasure'][$k]??[],$merged[$k]);apply_erasure($next,$merged);validate_state($next);
    if(!$apply)return ['valid'=>true,'orders'=>count($next['orders']),'users'=>count($next['users']),'files'=>count($payload['files'])];
    return transaction(function(&$s)use($payload,$next,$merged){if(site_settings($s)['orders_enabled'])fail('Перед восстановлением приостановите приём заказов');$next['staff']=$s['staff']??[];
        // Immutable files: preflight all conflicts before writing any file.
        foreach($payload['files'] as $rel=>$f){$path=str_starts_with($rel,'legal/')?private_dir().'/'.$rel:dirname(__DIR__).'/'.$rel;if(is_file($path)&&hash_file('sha256',$path)!==$f['sha256'])fail('Конфликт файла: '.$rel);}
        foreach($payload['files'] as $rel=>$f){$path=str_starts_with($rel,'legal/')?private_dir().'/'.$rel:dirname(__DIR__).'/'.$rel;if(!is_dir(dirname($path)))mkdir(dirname($path),0700,true);if(!is_file($path)){if(file_put_contents($path,base64_decode($f['data']),LOCK_EX)===false)throw new RuntimeException('Restore failed');chmod($path,str_starts_with($rel,'legal/')?0600:0644);}}
        $requests=$s['privacy_requests']??[];$external=$s['external_cleanup']??[];$current=tombstones();foreach(['users','orders'] as $k)$current[$k]=array_replace($merged[$k],$current[$k]);atomic_json(private_dir().'/erasure.json',$current);$s=$next;$s['privacy_requests']=array_replace($s['privacy_requests']??[],$requests);$s['external_cleanup']=array_replace($s['external_cleanup']??[],$external);apply_erasure($s,$current);foreach($s['users'] as &$u)$u['auth_version']=($u['auth_version']??0)+1;unset($u);$s['otp']=[];
        $s['settings']=site_settings($s);$s['settings']['orders_enabled']=false;$s['settings']['version']++;
        foreach($s['outbox']??[] as $id=>$j)if($j['status']!=='sent')$s['outbox'][$id]['status']='dead';
        event($s,'backup_restored',['orders'=>count($s['orders'])]);return ['restored'=>true,'orders_paused'=>true];
    });
}
function operations_summary(array $s): array {
    $overdue=[];foreach($s['orders'] as $o)if($o['status']==='new'&&strtotime($o['created_at'])<time()-config()['new_order_alert_minutes']*60)$overdue[]=$o['id'];
    $counts=[];foreach($s['outbox']??[] as $j)$counts[$j['status']]=($counts[$j['status']]??0)+1;
    return ['overdue_orders'=>$overdue,'outbox'=>$counts,'mail_enabled'=>config()['mail_enabled'],'sms_available'=>sms_ready(),'maintenance'=>$s['maintenance']??null,'privacy_requests'=>array_values($s['privacy_requests']??[]),'external_cleanup'=>array_values($s['external_cleanup']??[]),'retention_preview'=>privacy_plan($s,time())];
}

// A repeat is a fresh draft, never a new order. Prices and availability come from the locked state.
function repeat_draft(array $s,array $d): array {
    $mode=$d['mode']??'delivery';$town=$d['town']??'plastunovskaya';
    if(!in_array($mode,['store','delivery'])||!isset(delivery_zones($s)[$town])||($mode==='delivery'&&!delivery_zones($s)[$town]['active']))fail('Неверный способ получения');
    $source=$d['items']??[];if(!is_array($source)||count($source)>30)fail('Неверный состав');
    $items=[];$changes=[];$seen=[];$total=0;
    foreach($source as $i){if(!is_array($i))fail('Неверный состав');$id=text_field($i,'id',80,true);$qty=integer($i['qty']??null,1,99);if(isset($seen[$id]))fail('Повтор товара');$seen[$id]=true;
        $p=null;foreach($s['products'] as $v)if($v['id']===$id){$p=$v;break;}
        if(!$p||!$p['active']){$changes[]='Товар '.($p['name']??$id).' больше не продаётся и исключён.';continue;}
        $available=$p['stock']===null?99:max(0,$p['stock']-reserved($s,$id));$actual=min($qty,$available);
        if($actual<$qty)$changes[]=$p['name'].': доступно '.$actual.' из '.$qty.'.';if(!$actual)continue;
        $price=product_price($p,$mode,$town);
        $items[]=['id'=>$id,'qty'=>$actual,'price'=>$price];$total+=$actual*$price;
    }
    return ['items'=>$items,'total'=>$total+($mode==='delivery'&&count($items)?delivery_zones($s)[$town]['fee']:0),'changes'=>$changes,'mode'=>$mode,'town'=>$town];
}
function save_address(array &$s,string $uid,array $d): array {
    $town=text_field($d,'town',40,true);$address=text_field($d,'address',700,true);$label=text_field($d,'label',100,true);check_delivery($town,$address,$s);
    $a=&$s['users'][$uid]['addresses'];foreach($a as $v)if(($v['town']??'')===$town&&$v['address']===$address)return ['addresses'=>$a];
    if(count($a)>=10)fail('До 10 адресов');$a[]=['id'=>bin2hex(random_bytes(6)),'label'=>$label,'town'=>$town,'address'=>$address];return ['addresses'=>$a];
}
function set_reminder(array &$s,string $uid,array $d): array {
    if(($d['consent']??false)!==true)fail('Подтвердите включение напоминания');
    $oid=text_field($d,'order_id',80,true);$days=integer($d['days']??null,7,90);$o=$s['orders'][$oid]??null;
    if(!$o||$o['user_id']!==$uid)fail('Заказ не найден',404);
    $sid='reminder-'.hash('sha256',$uid.':'.$oid);
    $s['subscriptions'][$sid]=['id'=>$sid,'kind'=>'reminder','user_id'=>$uid,'order_id'=>$oid,'days'=>$days,'next_date'=>date('Y-m-d',strtotime('+'.$days.' days')),'active'=>true,'draft'=>['items'=>array_map(fn($i)=>['id'=>$i['id'],'qty'=>$i['qty']],$o['items']),'mode'=>$o['mode'],'town'=>$o['town']],'consent_at'=>date('c'),'consent_text'=>'Напоминать о повторном заказе в кабинете. Без автоматических заказов и списаний.'];
    return ['reminder'=>$s['subscriptions'][$sid]];
}
