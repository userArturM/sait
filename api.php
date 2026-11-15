<?php
declare(strict_types=1);
require __DIR__.'/backend/core.php';
header('Content-Type: application/json; charset=UTF-8');header('Cache-Control: no-store');header('X-Content-Type-Options: nosniff');
ini_set('display_errors','0');ini_set('log_errors','1');
ini_set('session.use_strict_mode','1');ini_set('session.gc_maxlifetime','1800');session_name('cw3');
$sessionDir=private_dir().'/sessions';if(!is_dir($sessionDir))mkdir($sessionDir,0700);session_save_path($sessionDir);
session_set_cookie_params(['lifetime'=>0,'path'=>'/','secure'=>!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off','httponly'=>true,'samesite'=>'Strict']);session_start();
$_SESSION['csrf']??=bin2hex(random_bytes(32));
$_SESSION['order_owner']??=bin2hex(random_bytes(24));
function respond($v): never {echo json_encode($v,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);exit;}
function user_only(): string {if(empty($_SESSION['uid']))fail('Войдите в кабинет',401);return $_SESSION['uid'];}
function admin_only(): void {admin_allowed(admin_actor(),$_GET['action']??'');}
function user_view(array $u): array {unset($u['password'],$u['recovery']);return $u;}
try {
    $action=$_GET['action']??'bootstrap';$method=$_SERVER['REQUEST_METHOD'];
    if($method!=='GET'&&$method!=='POST')fail('Метод не поддерживается',405);
    $publicGets=['bootstrap','catalog','me','admin_data','bridge_orders','slots'];if($method==='GET'&&!in_array($action,$publicGets))fail('Требуется POST',405);
    $bridge=in_array($action,['bridge_orders','bridge_ack']);
    if($bridge){rate_limit('bridge',30,300);$t=json_decode((string)@file_get_contents(private_dir().'/bridge.json'),true);$auth=$_SERVER['HTTP_AUTHORIZATION']??'';if(!$t||!str_starts_with($auth,'Bearer ')||!hash_equals($t['hash'],hash('sha256',substr($auth,7))))fail('Неверный ключ интеграции',401);}
    $d=[];
    if($method==='POST'){
        $limit=$action==='admin_upload'?4200000:32768;
        if($action==='admin_upload')admin_only();
        if((int)($_SERVER['CONTENT_LENGTH']??0)>$limit)fail('Слишком большой запрос',413);
        $origin=$_SERVER['HTTP_ORIGIN']??'';
        if($origin && strtolower((string)parse_url($origin,PHP_URL_HOST))!==strtolower(explode(':',$_SERVER['HTTP_HOST'])[0]))fail('Чужой источник',403);
        if(!$bridge&&!hash_equals($_SESSION['csrf'],$_SERVER['HTTP_X_CSRF_TOKEN']??''))fail('Обновите страницу',403);
        $raw=file_get_contents('php://input');if(strlen($raw)>$limit)fail('Слишком большой запрос',413);
        $d=json_decode($raw,true,32,JSON_THROW_ON_ERROR);if(!is_array($d))fail('Неверный JSON');
        rate_limit('post',120,300);
    }
    if(isset($_SESSION['uid']) && !in_array($action,['login','register','recover','logout'])){
        $current=transaction(fn(&$s)=>$s['users'][$_SESSION['uid']]??null,false);
        if(!$current || ($_SESSION['auth_version']??0)!==($current['auth_version']??0)){unset($_SESSION['uid']);if(!in_array($action,['bootstrap','catalog']))fail('Войдите заново',401);}
    }
    if($action==='slots'){
        $date=(string)($_GET['date']??date('Y-m-d'));$pickup=($_GET['mode']??'')==='store';$dt=DateTimeImmutable::createFromFormat('!Y-m-d',$date);if(!$dt||$dt->format('Y-m-d')!==$date||$date<date('Y-m-d')||$date>date('Y-m-d',strtotime('+'.config()['max_days'].' days')))fail('Дата недоступна');
        respond(transaction(fn(&$s)=>['date'=>$date,'slots'=>slot_options($s,$date,$pickup?'store':'delivery',(string)($_GET['town']??'plastunovskaya'))],false));
    }
    if($action==='bridge_orders')respond(transaction(fn(&$s)=>['schema'=>1,'orders'=>array_values(array_map('order_view',array_filter($s['orders'],fn($o)=>in_array($o['status'],['new','confirmed'])&&!isset($o['water19_id']))))],false));
    if($action==='bridge_ack'){$id=text_field($d,'id',80,true);$external=integer($d['water19_id']??null,1,2147483647);transaction(function(&$s)use($id,$external){if(!isset($s['orders'][$id]))fail('Не найдено',404);if(isset($s['orders'][$id]['water19_id'])&&$s['orders'][$id]['water19_id']!==$external)fail('Уже связан с другим заказом',409);$s['orders'][$id]['water19_id']=$external;event($s,'water19_linked',['id'=>$id,'water19_id'=>$external]);});respond(['ok'=>true]);}
    if($action==='order_result'){
        $key=text_field($d,'request_id',80,true);
        respond(transaction(function(&$s)use($key){foreach($s['orders'] as $o)if($o['request_id']===$key && hash_equals($o['owner'],$_SESSION['order_owner']))return ['found'=>true,'id'=>$o['id'],'total'=>$o['total']];return ['found'=>false];},false));
    }
    if($action==='bootstrap'||$action==='catalog') {
        $products=transaction(fn(&$s)=>array_values(array_filter($s['products'],fn($p)=>$p['active'])),false);
        foreach($products as &$p){unset($p['stock']);}unset($p);
        respond(['csrf'=>$_SESSION['csrf'],'products'=>$products,'settings'=>transaction(fn(&$s)=>site_settings($s),false),'slots'=>transaction(fn(&$s)=>array_column(logistics($s)['slots'],'label'),false),'today'=>date('Y-m-d'),'max_date'=>date('Y-m-d',strtotime('+'.config()['max_days'].' days')),'user'=>isset($_SESSION['uid']),'admin'=>isset($_SESSION['admin']),'sms_available'=>sms_ready(),'zones'=>transaction(fn(&$s)=>delivery_zones($s),false)]);
    }
    if($action==='order'){
        rate_limit('order',8,300);if(text_field($d,'website',200)!=='')fail('Запрос отклонён');
        $result=transaction(fn(&$s)=>place_order($s,$d,$_SESSION['uid']??null));
        // Уведомление отправляется после устойчивого сохранения. Его сбой не откатывает заказ.
        if(!$result['duplicate']){try{notify_order($result['id']);}catch(Throwable $e){error_log('CW: order saved, notice pending');}}
        respond($result);
    }
    if(in_array($action,['register','login','recover','admin_login']))rate_limit('auth',8,900);
    if($action==='delivery_check'){respond(transaction(fn(&$s)=>check_delivery(text_field($d,'town',40,true),text_field($d,'address',700,true),$s),false));}
    if($action==='sms_request'){
        rate_limit('sms',5,3600);$purpose=text_field($d,'purpose',20,true);$p=phone(text_field($d,'phone',40,true));
        if($purpose==='verify'){$uid=user_only();$u=transaction(fn(&$s)=>$s['users'][$uid],false);$p=$u['phone'];}
        if($purpose==='recover'){
            $eligible=transaction(function(&$s)use($p){foreach($s['users'] as $u)if($u['phone']===$p&&!empty($u['phone_verified_at']))return true;return false;},false);
            if(!$eligible)fail('Для SMS-восстановления нужен ранее подтверждённый номер. Используйте ключ восстановления');
        }
        respond(['challenge'=>otp_request($p,$purpose),'expires_in'=>300,'retry_after'=>60]);
    }
    if($action==='verify_phone'){$uid=user_only();$p=transaction(fn(&$s)=>$s['users'][$uid]['phone'],false);otp_consume(text_field($d,'challenge',64,true),text_field($d,'code',6,true),$p,'verify');transaction(function(&$s)use($uid){$s['users'][$uid]['phone_verified_at']=date('c');});respond(['ok'=>true]);}
    if($action==='recover_sms'){
        rate_limit('auth',8,900);$p=phone(text_field($d,'phone',40,true));$pass=text_field($d,'password',200,true);if(strlen($pass)<12)fail('Пароль: минимум 12 символов');
        otp_consume(text_field($d,'challenge',64,true),text_field($d,'code',6,true),$p,'recover');$key=bin2hex(random_bytes(24));
        transaction(function(&$s)use($p,$pass,$key){foreach($s['users'] as &$u)if($u['phone']===$p&&!empty($u['phone_verified_at'])){$u['password']=password_hash($pass,PASSWORD_DEFAULT);$u['recovery']=hash('sha256',$key);$u['auth_version']=($u['auth_version']??0)+1;return;}fail('Используйте ключ восстановления');});respond(['ok'=>true,'recovery_key'=>$key]);
    }
    if($action==='privacy_request'){$uid=user_only();respond(transaction(fn(&$s)=>request_erasure($s,$uid,text_field($d,'password',200,true))));}
    if($action==='change_password'){
        $uid=user_only();rate_limit('password',5,900);$old=text_field($d,'current_password',200,true);$new=text_field($d,'new_password',200,true);if(strlen($new)<12)fail('Пароль: минимум 12 символов');
        $v=transaction(function(&$s)use($uid,$old,$new){$u=&$s['users'][$uid];if(!password_verify($old,$u['password']))fail('Текущий пароль неверен',403);$u['password']=password_hash($new,PASSWORD_DEFAULT);$u['auth_version']=($u['auth_version']??0)+1;return $u['auth_version'];});session_regenerate_id(true);$_SESSION['auth_version']=$v;respond(['ok'=>true]);
    }
    if($action==='register'){
        $p=phone(text_field($d,'phone',40,true));$password=text_field($d,'password',200,true);if(strlen($password)<12)fail('Пароль: минимум 12 символов');
        if(($d['consent']??false)!==true||($d['privacy_acknowledgement']??false)!==true)fail('Требуются отдельные подтверждения для кабинета');$name=text_field($d,'name',160);$recovery=bin2hex(random_bytes(24));
        if(sms_ready())otp_consume(text_field($d,'challenge',64,true),text_field($d,'code',6,true),$p,'register');
        $u=transaction(function(&$s)use($p,$password,$name,$recovery){foreach($s['users'] as $u)if($u['phone']===$p)fail('Телефон уже зарегистрирован. Войдите или используйте ключ восстановления',409);
            $id=bin2hex(random_bytes(16));$u=['id'=>$id,'phone'=>$p,'name'=>$name,'password'=>password_hash($password,PASSWORD_DEFAULT),'recovery'=>hash('sha256',$recovery),'addresses'=>[],'created_at'=>date('c'),'phone_verified_at'=>sms_ready()?date('c'):null,'consent_version'=>'2026-10-23.2','document_hashes'=>legal_evidence(),'privacy_acknowledgement'=>true,'consent_received_at'=>date('c')];$s['users'][$id]=$u;event($s,'account_created',['id'=>$id]);return $u;});
        session_regenerate_id(true);$_SESSION['uid']=$u['id'];$_SESSION['auth_version']=$u['auth_version']??0;respond(['user'=>user_view($u),'recovery_key'=>$recovery]);
    }
    if($action==='login'){
        $p=phone(text_field($d,'phone',40,true));$pass=text_field($d,'password',200,true);
        $u=transaction(function(&$s)use($p){foreach($s['users'] as $u)if($u['phone']===$p)return $u;return null;},false);
        $dummy='$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi';
        if(!password_verify($pass,$u['password']??$dummy)||!$u)fail('Неверный телефон или пароль',401);
        session_regenerate_id(true);$_SESSION['uid']=$u['id'];$_SESSION['auth_version']=$u['auth_version']??0;respond(['ok'=>true]);
    }
    if($action==='recover'){
        $p=phone(text_field($d,'phone',40,true));$key=text_field($d,'recovery_key',100,true);$password=text_field($d,'password',200,true);if(strlen($password)<12)fail('Пароль: минимум 12 символов');$newKey=bin2hex(random_bytes(24));
        transaction(function(&$s)use($p,$key,$password,$newKey){foreach($s['users'] as &$u)if($u['phone']===$p && hash_equals($u['recovery'],hash('sha256',$key))){$u['password']=password_hash($password,PASSWORD_DEFAULT);$u['recovery']=hash('sha256',$newKey);$u['auth_version']=($u['auth_version']??0)+1;return;}fail('Неверные данные восстановления',401);});respond(['ok'=>true,'recovery_key'=>$newKey]);
    }
    // Изменение пароля аннулирует старые пользовательские сессии.
    if(isset($_SESSION['uid'])){
        $u=transaction(fn(&$s)=>$s['users'][$_SESSION['uid']]??null,false);
        if(!$u || ($_SESSION['auth_version']??0)!==($u['auth_version']??0)){unset($_SESSION['uid']);fail('Войдите заново',401);}
    }
    if($action==='logout'){session_unset();session_regenerate_id(true);$_SESSION['csrf']=bin2hex(random_bytes(32));respond(['ok'=>true]);}
    if($action==='me'){
        $id=user_only();respond(transaction(function(&$s)use($id){return ['user'=>user_view($s['users'][$id]),'privacy_request'=>$s['privacy_requests'][$id]??null,'orders'=>array_values(array_map('order_view',array_filter($s['orders'],fn($o)=>$o['user_id']===$id))),'subscriptions'=>array_values(array_filter($s['subscriptions'],fn($x)=>$x['user_id']===$id))];},false));
    }
    if($action==='repeat_quote'){respond(transaction(function(&$s)use($d){$draft=$d;if(!empty($d['reminder_id'])){$uid=user_only();$r=$s['subscriptions'][$d['reminder_id']]??null;if(!$r||$r['user_id']!==$uid||!$r['active']||$r['kind']!=='reminder')fail('Напоминание не найдено',404);$draft=$r['draft'];}elseif(!empty($d['order_id'])){$uid=user_only();$draft=$s['orders'][$d['order_id']]??null;if(!$draft||$draft['user_id']!==$uid)fail('Заказ не найден',404);}return repeat_draft($s,$draft);},false));}
    if($action==='addresses'){$id=user_only();respond(transaction(fn(&$s)=>save_address($s,$id,$d)));}
    if($action==='reminder_snooze'){$uid=user_only();$id=text_field($d,'id',80,true);transaction(function(&$s)use($uid,$id){$r=&$s['subscriptions'][$id];if(!$r||$r['user_id']!==$uid||!$r['active']||($r['kind']??'')!=='reminder')fail('Напоминание не найдено',404);$r['next_date']=date('Y-m-d',strtotime('+'.$r['days'].' days'));});respond(['ok'=>true]);}
    if($action==='reminder'){$id=user_only();respond(transaction(fn(&$s)=>set_reminder($s,$id,$d)));}
    if($action==='delete_address'){
        $id=user_only();$aid=text_field($d,'id',80,true);transaction(function(&$s)use($id,$aid){$s['users'][$id]['addresses']=array_values(array_filter($s['users'][$id]['addresses'],fn($a)=>$a['id']!==$aid));});respond(['ok'=>true]);
    }
    if($action==='subscription'){
        $id=user_only();$oid=text_field($d,'order_id',80,true);$days=integer($d['days']??null,7,90);
        respond(transaction(function(&$s)use($id,$oid,$days){$o=$s['orders'][$oid]??null;if(!$o||$o['user_id']!==$id)fail('Заказ не найден',404);$sid=bin2hex(random_bytes(12));$s['subscriptions'][$sid]=['id'=>$sid,'user_id'=>$id,'order_id'=>$oid,'days'=>$days,'next_date'=>date('Y-m-d',strtotime('+'.$days.' days')),'active'=>true];event($s,'subscription_requested',['id'=>$sid]);return ['ok'=>true];}));
    }
    if($action==='cancel_subscription'){
        $id=user_only();$sid=text_field($d,'id',80,true);transaction(function(&$s)use($id,$sid){if(!isset($s['subscriptions'][$sid])||$s['subscriptions'][$sid]['user_id']!==$id)fail('Не найдено',404);$s['subscriptions'][$sid]['active']=false;});respond(['ok'=>true]);
    }
    if($action==='admin_login'){
        $login=strtolower(text_field($d,'login',50));$pass=text_field($d,'password',200,true);
        if($login===''||$login==='admin'){
            $path=private_dir().'/admin.json';if(!is_file($path))fail('Администратор не настроен',503);$a=json_decode(file_get_contents($path),true,512,JSON_THROW_ON_ERROR);
            if(!password_verify($pass,$a['password']))fail('Неверный логин или пароль',401);$id='owner';$hash=hash('sha256',$a['password']);
        }else{
            $u=transaction(function(&$s)use($login){foreach($s['staff']??[] as $u)if($u['login']===$login)return $u;return null;},false);
            $dummy='$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi';if(!password_verify($pass,$u['password']??$dummy)||!$u||!$u['active'])fail('Неверный логин или пароль',401);
            $id=$u['id'];$hash=hash('sha256',$u['password'].'|'.$u['version']);
        }
        session_regenerate_id(true);$_SESSION['admin']=$id;$_SESSION['admin_hash']=$hash;$_SESSION['admin_at']=time();respond(['ok'=>true]);
    }
    if(str_starts_with($action,'admin_')){
        admin_only();
        $actor=admin_actor();
        if($action==='admin_data')respond(transaction(fn(&$s)=>business_data($s,$actor),false));
        if($action==='admin_logistics')respond(transaction(fn(&$s)=>save_logistics($s,$d)));
        if($action==='admin_staff')respond(transaction(fn(&$s)=>save_staff($s,$d)));
        if($action==='admin_assign')respond(transaction(fn(&$s)=>assign_courier($s,$d)));
        if($action==='admin_courier_update')respond(transaction(fn(&$s)=>courier_update($s,$d,$actor['id'])));
        if($action==='admin_upload'){rate_limit('image_upload',30,300);$image=upload_catalog_image($d);transaction(function(&$s)use($image){event($s,'image_uploaded',['path'=>$image]);});respond(['image'=>$image]);}
        if($action==='admin_save_product')respond(transaction(fn(&$s)=>save_catalog_product($s,$d)));
        if($action==='admin_settings')respond(transaction(fn(&$s)=>save_site_settings($s,$d)));
        if($action==='admin_password'){
            rate_limit('admin_password',5,900);$path=private_dir().'/admin.json';$a=json_decode(file_get_contents($path),true);
            if(!password_verify(text_field($d,'current_password',200,true),$a['password']))fail('Текущий пароль неверен',403);
            $new=text_field($d,'new_password',200,true);if(strlen($new)<16)fail('Новый пароль: минимум 16 символов');
            $hash=password_hash($new,PASSWORD_DEFAULT);$tmp=tempnam(private_dir(),'admin-');chmod($tmp,0600);
            if(file_put_contents($tmp,json_encode(['password'=>$hash]))===false||!rename($tmp,$path))throw new RuntimeException('Password write failed');
            session_regenerate_id(true);$_SESSION['admin_hash']=hash('sha256',$hash);$_SESSION['admin_at']=time();transaction(function(&$s){event($s,'admin_password_changed',[]);});respond(['ok'=>true]);
        }
        if($action==='admin_maintenance')respond(maintenance(($d['apply']??false)===true));
        if($action==='admin_retry_notices'){transaction(function(&$s){foreach($s['outbox']??[] as $id=>$j)if(in_array($j['status'],['failed','dead'])&&empty($s['orders'][$j['order_id']]['personal_data_erased_at'])){$s['outbox'][$id]['status']='pending';$s['outbox'][$id]['next_at']=0;$s['outbox'][$id]['attempts']=0;}});respond(process_notices(10));}
        if($action==='admin_notices')respond(process_notices(10));
        if($action==='admin_full_backup')respond(['backup'=>transaction(fn(&$s)=>backup_bundle($s),false)]);
        if($action==='admin_external_cleanup'){$id=text_field($d,'id',80,true);$note=text_field($d,'note',300,true);transaction(function(&$s)use($id,$note){if(!isset($s['external_cleanup'][$id]))fail('Задача не найдена',404);$s['external_cleanup'][$id]['status']='completed';$s['external_cleanup'][$id]['completed_at']=date('c');$s['external_cleanup'][$id]['note']=$note;});respond(['ok'=>true]);}
        if($action==='admin_hold'){
            $id=text_field($d,'id',80,true);$reason=text_field($d,'reason',500);transaction(function(&$s)use($id,$reason){if(!isset($s['orders'][$id]))fail('Заказ не найден',404);$s['orders'][$id]['legal_hold']=$reason;event($s,'legal_hold_changed',['id'=>$id,'enabled'=>$reason!=='']);});respond(['ok'=>true]);
        }
        if($action==='admin_order')respond(transaction(fn(&$s)=>update_order($s,$d)));
        if($action==='admin_add_product'){
            $name=text_field($d,'name',160,true);$description=text_field($d,'description',400,true);$image=text_field($d,'image',200,true);
            $real=realpath(__DIR__.'/'.$image);$assets=realpath(__DIR__.'/assets');if(!$real||!is_file($real)||!str_starts_with($real,$assets.'/')||!preg_match('/\.(webp|png|jpe?g)$/i',$image))fail('Изображение должно быть существующим файлом assets');
            $category=$d['category']??'';if(!in_array($category,['water','accessory']))fail('Неверная категория');$volume=integer($d['volume']??null,0,100);$delivery=integer($d['delivery']??null,0,100050);$store=integer($d['store']??null,0,100000);
            $id='item-'.bin2hex(random_bytes(8));transaction(function(&$s)use($name,$description,$image,$category,$volume,$delivery,$store,$id,$d){$new=['id'=>$id,'name'=>$name,'description'=>$description,'image'=>$image,'category'=>$category,'volume'=>$volume,'delivery'=>$delivery,'store'=>$store,'stock'=>null,'active'=>true,'version'=>1];apply_product_prices($new,$d);$s['products'][]=$new;event($s,'product_created',['id'=>$id]);});respond(['id'=>$id]);
        }
        if($action==='admin_product'){
            $id=text_field($d,'id',80,true);$delivery=integer($d['delivery']??null,0,100050);$store=integer($d['store']??null,0,100000);$stock=$d['stock']??null;if($stock!==null)integer($stock,0,100000);$active=$d['active']??null;if(!is_bool($active))fail('Неверный статус товара');
            transaction(function(&$s)use($id,$delivery,$store,$stock,$active,$d){foreach($s['products'] as &$p)if($p['id']===$id){if(($d['version']??null)!==($p['version']??1))fail('Каталог изменён. Обновите панель',409);if($stock!==null&&$stock<reserved($s,$id))fail('Остаток ниже резерва',409);$before=$p;$p['delivery']=$delivery;apply_product_prices($p,$d);$p['store']=$store;$p['stock']=$stock;$p['active']=$active;$p['version']=($p['version']??1)+1;event($s,'product_updated',['id'=>$id,'before'=>$before,'after'=>$p]);return;}fail('Товар не найден',404);});respond(['ok'=>true]);
        }
        if($action==='admin_retry_mail'){$id=text_field($d,'id',80,true);notify_order($id,true);respond(['ok'=>true]);}
        if($action==='admin_subscription'){
            $id=text_field($d,'id',80,true);$date=text_field($d,'next_date',10,true);$dt=DateTimeImmutable::createFromFormat('!Y-m-d',$date);if(!$dt||$dt->format('Y-m-d')!==$date)fail('Неверная дата');
            transaction(function(&$s)use($id,$date){if(!isset($s['subscriptions'][$id]))fail('Не найдено',404);$s['subscriptions'][$id]['next_date']=$date;event($s,'subscription_contacted',['id'=>$id,'next_date'=>$date]);});respond(['ok'=>true]);
        }
        if($action==='admin_export'){
            $rows=transaction(fn(&$s)=>array_values($s['orders']),false);$f=fopen('php://temp','r+');fputcsv($f,['id','created_at','status','phone','name','address','date','slot','total_rub','paid_rub','empty_planned','empty_received','delivery_fee_rub','items_json'],';', '"', '');
            foreach($rows as $o){$safe=fn($v)=>preg_match('/^[=+@\-\t\r]/',(string)$v)?"'".$v:$v;fputcsv($f,array_map($safe,[$o['id'],$o['created_at'],$o['status'],$o['phone'],$o['name'],$o['address'],$o['date'],$o['slot'],$o['total'],$o['paid'],$o['empty_count'],$o['actual_empty'],$o['delivery_fee']??0,json_encode($o['items'],JSON_UNESCAPED_UNICODE)]),';','"','');}
            rewind($f);respond(['csv'=>"\xEF\xBB\xBF".stream_get_contents($f)]);
        }
        if($action==='admin_backup')respond(transaction(fn(&$s)=>['backup'=>$s,'format'=>'cw3-schema-1'],false));
    }
    fail('Неизвестное действие',404);
}catch(DomainException $e){http_response_code($e->getCode()?:400);respond(['error'=>$e->getMessage()]);}
catch(JsonException $e){http_response_code(400);respond(['error'=>'Некорректные данные']);}
catch(Throwable $e){error_log('CW3: '.$e->getMessage());http_response_code(500);respond(['error'=>'Сервис временно недоступен. Корзина сохранена. Позвоните +7 928 223-53-33']);}
function notify_order(string $id,bool $retry=false): void {
    transaction(function(&$s)use($id,$retry){if(!isset($s['orders'][$id]))fail('Не найдено',404);enqueue_notice($s,$id,'owner');$key=$id.':owner';
        if($retry&&in_array($s['outbox'][$key]['status'],['failed','dead'])){$s['outbox'][$key]['status']='pending';$s['outbox'][$key]['next_at']=0;$s['outbox'][$key]['attempts']=0;}
        if(!config()['mail_enabled'])$s['orders'][$id]['mail_status']='disabled';
    });process_notices(2);
}
