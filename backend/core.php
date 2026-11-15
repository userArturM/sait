<?php
declare(strict_types=1);
require_once __DIR__.'/catalog_admin.php';
require_once __DIR__.'/services.php';
require_once __DIR__.'/business.php';
function config(): array {
    static $c;
    if (!$c) { $c = require __DIR__.'/config.example.php'; if (is_file(__DIR__.'/config.php')) $c = array_replace($c, require __DIR__.'/config.php'); }
    date_default_timezone_set($c['timezone']); return $c;
}
function private_dir(): string {
    $d = config()['data_dir'];
    $root = realpath(dirname(__DIR__));
    if (!is_dir($d) && !mkdir($d,0700,true)) throw new RuntimeException('Не создан каталог данных');
    $real = realpath($d);
    if (!$real || $real === $root || str_starts_with($real,$root.'/')) throw new RuntimeException('Каталог данных должен быть вне сайта');
    return $real;
}
function initial_state(): array {
    return ['schema'=>1,'products'=>array_map(fn($p)=>array_merge($p,['version'=>1]),json_decode(file_get_contents(__DIR__.'/seed.json'),true,512,JSON_THROW_ON_ERROR)),'orders'=>[],'users'=>[],'subscriptions'=>[],'events'=>[]];
}
function transaction(callable $fn, bool $write = true) {
    $dir=private_dir(); $lock=fopen($dir.'/state.lock','c'); if (!$lock || !flock($lock,LOCK_EX)) throw new RuntimeException('Хранилище недоступно');
    try {
        $file=$dir.'/state.json'; $s=is_file($file)?json_decode(file_get_contents($file),true,512,JSON_THROW_ON_ERROR):initial_state();
        apply_erasure($s,tombstones());
        $result=$fn($s);
        if ($write) {
            $json=json_encode($s,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
            $tmp=tempnam($dir,'state-'); chmod($tmp,0600);
            if (file_put_contents($tmp,$json)!==strlen($json)) { unlink($tmp);throw new RuntimeException('Ошибка записи'); }
            if (is_file($file)) {
                $bd=$dir.'/backups'; if(!is_dir($bd))mkdir($bd,0700);
                $backup=$bd.'/state-'.date('Ymd-His').'-'.bin2hex(random_bytes(3)).'.json';
                $previous=json_decode(file_get_contents($file),true,512,JSON_THROW_ON_ERROR);apply_erasure($previous,tombstones());atomic_json($backup,$previous);
                $files=glob($bd.'/*.json');sort($files);while(count($files)>30)unlink(array_shift($files));
            }
            if(!rename($tmp,$file)){unlink($tmp);throw new RuntimeException('Ошибка фиксации');}
        }
        return $result;
    } finally {flock($lock,LOCK_UN);fclose($lock);}
}
function fail(string $msg,int $code=400): never { throw new DomainException($msg,$code); }
function text_field(array $d,string $k,int $max,bool $required=false): string {
    if(isset($d[$k]) && !is_string($d[$k]))fail('Некорректное поле: '.$k);
    $v=trim($d[$k]??''); if(strlen($v)>$max || ($required && $v===''))fail('Проверьте поле: '.$k);return $v;
}
function integer($v,int $min,int $max): int { if(!is_int($v)||$v<$min||$v>$max)fail('Некорректное количество или сумма');return $v; }
function phone(string $v): string { $p=preg_replace('/\D/','',$v); if(strlen($p)===11 && $p[0]==='8')$p='7'.substr($p,1);if(!preg_match('/^7\d{10}$/',$p))fail('Укажите российский телефон из 11 цифр'); return '+'.$p; }
function event(array &$s,string $kind,array $details): void { $s['events'][]=['at'=>date('c'),'actor'=>$_SESSION['admin']??($_SESSION['uid']??'guest'),'kind'=>$kind,'details'=>$details]; }
function reserved(array $s,string $pid): int {
    $n=0; foreach($s['orders'] as $o)if(!in_array($o['status'],['cancelled','delivered']))foreach($o['items'] as $i)if($i['id']===$pid)$n+=$i['qty'];return $n;
}
function quote(array $s,array $d): array {
    $mode=$d['mode']??'';$town=$d['town']??'';
    if(!in_array($mode,['delivery','store'])||!isset(delivery_zones($s)[$town])||($mode==='delivery'&&!delivery_zones($s)[$town]['active']))fail('Неверный способ получения');
    if(!is_array($d['items']??null)||count($d['items'])<1||count($d['items'])>30)fail('Корзина пуста');
    $items=[];$total=0;$bottles=0;$bottles19=0;$tara=0;$seen=[];
    foreach($d['items'] as $item){
        if(!is_array($item)||!is_string($item['id']??null))fail('Неверный товар');$id=$item['id'];if(isset($seen[$id]))fail('Повтор товара');$seen[$id]=1;
        $found=array_values(array_filter($s['products'],fn($p)=>$p['id']===$id && $p['active']));if(!$found)fail('Товар больше не доступен');$p=$found[0];$q=integer($item['qty']??null,1,99);
        if($p['stock']!==null && $q>$p['stock']-reserved($s,$id))fail('Недостаточно товара: '.$p['name'],409);
        $price=product_price($p,$mode,$town);
        if($p['category']==='water'){$bottles+=$q;if($p['volume']===19)$bottles19+=$q;}if($id==='butyl-tara')$tara+=$q;
        $items[]=['id'=>$id,'name'=>$p['name'],'qty'=>$q,'price'=>$price,'volume'=>$p['volume'],'category'=>$p['category']];$total+=$price*$q;
    }
    $fee=$mode==='store'?0:delivery_zones($s)[$town]['fee'];$total+=$fee;
    $min=$mode==='store'?0:delivery_zones($s)[$town]['minimum'];if($bottles<$min)fail('Минимальный заказ воды: '.$min.' бутыли');
    $empty=integer($d['empty_count']??0,0,$bottles19);$container=$d['container']??'exchange';if(!in_array($container,['exchange','new','own']))fail('Выберите вариант тары');
    if($container==='new' && $tara<max(0,$bottles19-$empty))fail('Добавьте новые бутыли в корзину');
    return ['items'=>$items,'total'=>$total,'delivery_fee'=>$fee,'bottles19'=>$bottles19,'tara'=>$tara,'empty_count'=>$empty,'container'=>$container,'mode'=>$mode,'town'=>$town];
}
function place_order(array &$s,array $d,?string $uid): array {
    $key=text_field($d,'request_id',80,true);if(!preg_match('/^[a-zA-Z0-9-]{16,80}$/',$key))fail('Неверный ключ заказа');
    $fingerprint=hash('sha256',json_encode($d,JSON_UNESCAPED_UNICODE));
    foreach($s['orders'] as $o)if($o['request_id']===$key){if($o['fingerprint']!==$fingerprint)fail('Корзина изменена. Обновите ключ заказа',409);if(!hash_equals($o['owner'],$_SESSION['order_owner']))fail('Ключ недоступен',403);return ['id'=>$o['id'],'total'=>$o['total'],'duplicate'=>true];}
    if(!site_settings($s)['orders_enabled'])fail('Приём заказов временно приостановлен. Позвоните +7 928 223-53-33',503);
    foreach(['privacy_acknowledgement','offer_acceptance'] as $c)if(($d[$c]??false)!==true)fail('Требуются отдельные подтверждения документов');
    $q=quote($s,$d);$expected=integer($d['expected_total']??null,0,10000000);if($expected!==$q['total'])fail('Цена изменилась. Обновите каталог и проверьте итог',409);
    $p=phone(text_field($d,'phone',40,true));$name=text_field($d,'name',160);$address=text_field($d,'address',700,$q['mode']==='delivery');$comment=text_field($d,'comment',1500);
    if($q['mode']==='delivery')check_delivery($q['town'],$address,$s);
    $email=text_field($d,'email',254);if($email!==''&&!filter_var($email,FILTER_VALIDATE_EMAIL))fail('Проверьте e-mail');
    $date=text_field($d,'date',10,true);$dt=DateTimeImmutable::createFromFormat('!Y-m-d',$date);
    if(!$dt||$dt->format('Y-m-d')!==$date||$date<date('Y-m-d')||$date>date('Y-m-d',strtotime('+'.config()['max_days'].' days')))fail('Дата недоступна');
    $slot=text_field($d,'slot',80,true);require_slot($s,$date,$slot,$q['mode'],$q['town']);
    $pay=text_field($d,'payment',100,true);if(!in_array($pay,['Наличными при получении','Безналичный перевод / СБП']))fail('Выберите способ оплаты');
    $id='CW-'.date('Ymd').'-'.strtoupper(bin2hex(random_bytes(5)));
    $o=array_merge($q,['id'=>$id,'request_id'=>$key,'fingerprint'=>$fingerprint,'owner'=>$_SESSION['order_owner']??'test','user_id'=>$uid,'name'=>$name,'phone'=>$p,'email'=>$email,'address'=>$address,'comment'=>$comment,'date'=>$date,'slot'=>$slot,'payment'=>$pay,'status'=>'new','paid'=>0,'actual_empty'=>0,'created_at'=>date('c'),'version'=>1,'mail_status'=>'pending','processing_basis'=>'contract_152fz_6_1_5','consents'=>['personal'=>false,'privacy'=>true,'offer'=>true,'version'=>'2026-10-25.3','document_hashes'=>legal_evidence(),'received_at'=>date('c')]]);
    $o['history']=[['at'=>date('c'),'status'=>'new']];$s['orders'][$id]=$o;enqueue_notice($s,$id,'owner');if($email!=='')enqueue_notice($s,$id,'customer-new');event($s,'order_created',['id'=>$id]);return ['id'=>$id,'total'=>$o['total'],'duplicate'=>false];
}
function order_view(array $o): array {unset($o['fingerprint'],$o['request_id'],$o['owner']);return $o;}
function update_order(array &$s,array $d): array {
    $id=text_field($d,'id',80,true);if(!isset($s['orders'][$id]))fail('Заказ не найден',404);$o=&$s['orders'][$id];
    if(($d['version']??null)!==$o['version'])fail('Заказ изменён. Обновите список',409);
    $status=$d['status']??$o['status'];$moves=['new'=>['confirmed','cancelled'],'confirmed'=>['on_route','delivered','cancelled'],'on_route'=>['delivered','cancelled'],'delivered'=>[],'cancelled'=>[]];
    if($status!==$o['status']&&!in_array($status,$moves[$o['status']]??[]))fail('Недопустимый переход статуса');
    $paid=integer($d['paid']??$o['paid'],0,$o['total']);if($status==='cancelled'&&$paid!==0)fail('Сначала зафиксируйте возврат оплаты');
    $empty=integer($d['actual_empty']??$o['actual_empty'],0,$o['bottles19']);if($o['status']==='delivered'&&$empty!==$o['actual_empty'])fail('Тара завершённого заказа не изменяется');
    if($status==='delivered'&&$o['status']!=='delivered'){
        foreach($o['items'] as $i)foreach($s['products'] as &$p)if($p['id']===$i['id']&&$p['stock']!==null){if($p['stock']<$i['qty'])fail('Остаток недостаточен');$p['stock']-=$i['qty'];$p['version']=($p['version']??1)+1;}unset($p);
    }
    if(isset($d['confirmed_date'])&&$d['confirmed_date']!==''&&($d['confirmed_date']!==($o['confirmed_date']??'')||($d['confirmed_slot']??'')!==($o['confirmed_slot']??''))){
        $cd=text_field($d,'confirmed_date',10,true);$dt=DateTimeImmutable::createFromFormat('!Y-m-d',$cd);$cs=text_field($d,'confirmed_slot',80,true);
        if(!$dt||$dt->format('Y-m-d')!==$cd)fail('Проверьте согласованное время');
        if(in_array($o['status'],['delivered','cancelled']))fail('Время завершённого заказа не меняется');
        if(($d['customer_agreed']??false)!==true)fail('Подтвердите согласование времени с покупателем');
        require_slot($s,$cd,$cs,$o['mode'],$o['town'],$id);$o['confirmed_date']=$cd;$o['confirmed_slot']=$cs;$o['agreed_at']=date('c');
    }
    if($status!==$o['status']){$o['history'][]=['at'=>date('c'),'status'=>$status];if(in_array($status,['delivered','cancelled']))$o['closed_at']=date('c');if(!empty($o['email']))enqueue_notice($s,$id,'customer-'.$status);}
    $before=['status'=>$o['status'],'paid'=>$o['paid'],'actual_empty'=>$o['actual_empty']];$o['status']=$status;$o['paid']=$paid;$o['actual_empty']=$empty;$o['version']++;event($s,'order_updated',['id'=>$id,'before'=>$before,'after'=>['status'=>$status,'paid'=>$paid,'actual_empty'=>$empty]]);return order_view($o);
}
function rate_limit(string $bucket,int $max,int $seconds): void {
    $path=private_dir().'/rate.json';$f=fopen($path,'c+');if(!$f||!flock($f,LOCK_EX))throw new RuntimeException('Rate unavailable');
    try{$v=json_decode(stream_get_contents($f),true)?:[];$now=time();foreach($v as $k=>$ts){$v[$k]=array_values(array_filter($ts,fn($t)=>$t>$now-86400));if(!$v[$k])unset($v[$k]);}
        $key=hash('sha256',$bucket.'|'.($_SERVER['REMOTE_ADDR']??'cli'));$ts=array_values(array_filter($v[$key]??[],fn($t)=>$t>$now-$seconds));if(count($ts)>=$max)fail('Слишком много запросов. Попробуйте позже',429);$ts[]=$now;$v[$key]=$ts;rewind($f);ftruncate($f,0);fwrite($f,json_encode($v));
    }finally{flock($f,LOCK_UN);fclose($f);}
}
