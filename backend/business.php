<?php
declare(strict_types=1);
function logistics(array $s): array {
    $zones=[];foreach(['plastunovskaya'=>'Пластуновская','dinskaya'=>'Динская','ukrainskaya'=>'Украинская'] as $id=>$name)$zones[$id]=['name'=>$name,'minimum'=>$id==='plastunovskaya'?2:3,'fee'=>0,'active'=>true,'weekdays'=>[1,2,3,4,5,6,7]];
    return $s['logistics']??['version'=>1,'zones'=>$zones,'closed_dates'=>[],'cutoff'=>'23:59','slots'=>array_map(fn($label)=>['label'=>$label,'capacity'=>config()['slot_capacity'],'active'=>true],config()['slots'])];
}
function delivery_zones(?array $s=null): array {return logistics($s??[])['zones'];}
function date_valid(string $date): bool {$d=DateTimeImmutable::createFromFormat('!Y-m-d',$date);return $d&&$d->format('Y-m-d')===$date;}
function validate_logistics(array $v): array {
    $zones=$v['zones']??null;if(!is_array($zones)||!$zones||count($zones)>30)fail('Нужно от 1 до 30 населённых пунктов');$out=[];
    foreach($zones as $id=>$z){if(!is_string($id)||!preg_match('/^[a-z][a-z0-9_-]{1,39}$/D',$id)||!is_array($z))fail('Неверный код населённого пункта');
        $days=$z['weekdays']??null;if(!is_array($days)||!$days||count($days)>7||count(array_unique($days,SORT_REGULAR))!==count($days))fail('Выберите дни доставки');foreach($days as $day)integer($day,1,7);
        if(!is_bool($z['active']??null))fail('Неверный статус зоны');
        $name=text_field($z,'name',120,true);foreach($out as $previous)if(preg_match('/^'.preg_quote($name,'/').'$/ui',$previous['name']))fail('Названия населённых пунктов не должны повторяться');
        $out[$id]=['name'=>$name,'minimum'=>integer($z['minimum']??null,0,99),'fee'=>integer($z['fee']??null,0,100000),'active'=>$z['active'],'weekdays'=>$days];
    }
    $dates=$v['closed_dates']??[];if(!is_array($dates)||count($dates)>366)fail('Слишком много выходных');foreach($dates as $date)if(!is_string($date)||!date_valid($date))fail('Проверьте даты выходных');
    $cutoff=text_field($v,'cutoff',5,true);if(!preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/D',$cutoff))fail('Проверьте время окончания приёма');
    $slots=$v['slots']??null;if(!is_array($slots)||!$slots||count($slots)>12)fail('Нужно от 1 до 12 интервалов');$seen=[];
    foreach($slots as &$slot){$label=text_field($slot,'label',40,true);if(!preg_match('/^([0-2][0-9]:[0-5][0-9])–([0-2][0-9]:[0-5][0-9])$/D',$label,$m)||$m[1]>='24:00'||$m[2]>='24:00'||$m[1]>=$m[2]||isset($seen[$label]))fail('Интервалы: ЧЧ:ММ–ЧЧ:ММ, без повторов');$seen[$label]=1;if(!is_bool($slot['active']??null))fail('Неверный статус интервала');$slot=['label'=>$label,'capacity'=>integer($slot['capacity']??null,0,10000),'active'=>$slot['active']];}unset($slot);
    $active=array_values(array_filter($slots,fn($x)=>$x['active']));usort($active,fn($a,$b)=>strcmp($a['label'],$b['label']));for($i=1;$i<count($active);$i++)if(explode('–',$active[$i]['label'])[0]<explode('–',$active[$i-1]['label'])[1])fail('Активные интервалы не должны пересекаться');
    return ['version'=>integer($v['version']??null,1,2147483647),'zones'=>$out,'closed_dates'=>array_values(array_unique($dates)),'cutoff'=>$cutoff,'slots'=>array_values($slots)];
}
function save_logistics(array &$s,array $d): array {
    $old=logistics($s);if(($d['version']??null)!==$old['version'])fail('Условия изменились. Обновите панель',409);$v=validate_logistics($d);
    foreach($old['zones'] as $id=>$z)if(!isset($v['zones'][$id]))fail('Вместо удаления населённого пункта отключите доставку');
    foreach($v['zones'] as $id=>$z)if(!isset($old['zones'][$id])){foreach($s['products'] as &$p){foreach($old['zones'] as $oldId=>$oldZone)$p['delivery_prices'][$oldId]=product_price($p,'delivery',$oldId);$p['delivery_prices'][$id]=$p['delivery'];$p['version']=($p['version']??1)+1;}unset($p);}
    $v['version']++;$s['logistics']=$v;event($s,'logistics_updated',['version'=>$v['version']]);return $v;
}
function slot_options(array $s,string $date,string $mode,string $town,string $exclude=''): array {
    $v=logistics($s);$z=$v['zones'][$town]??null;
    $valid=date_valid($date)&&$date>=date('Y-m-d')&&$date<=date('Y-m-d',strtotime('+'.config()['max_days'].' days'));
    $dayOpen=$valid&&!in_array($date,$v['closed_dates'],true)&&($mode==='store'||($z&&$z['active']&&in_array((int)date('N',strtotime($date)),$z['weekdays'],true)));
    $rows=[];foreach($v['slots'] as $slot){$n=0;foreach($s['orders'] as $id=>$o)if($id!==$exclude&&$o['mode']===$mode&&($o['confirmed_date']??$o['date'])===$date&&($o['confirmed_slot']??$o['slot'])===$slot['label']&&$o['status']!=='cancelled')$n++;
        $end=explode('–',$slot['label'])[1];$available=$dayOpen&&$slot['active']&&($date!==date('Y-m-d')||(date('H:i')<$v['cutoff']&&date('H:i')<$end))&&($slot['capacity']===0||$n<$slot['capacity']);
        $rows[]=['label'=>$slot['label'],'available'=>$available,'booked'=>$n,'capacity'=>$slot['capacity']];
    }return $rows;
}
function require_slot(array $s,string $date,string $slot,string $mode,string $town,string $exclude=''): void {foreach(slot_options($s,$date,$mode,$town,$exclude) as $r)if($r['label']===$slot&&$r['available'])return;fail('Дата или интервал недоступны. Выберите другое время',409);}
function staff_public(array $u): array {unset($u['password']);return $u;}
function save_staff(array &$s,array $d): array {
    $id=text_field($d,'id',80);$old=$id!==''?($s['staff'][$id]??null):null;if($id!==''&&!$old)fail('Сотрудник не найден',404);
    if($old&&($d['version']??null)!==$old['version'])fail('Сотрудник изменён. Обновите панель',409);
    $login=strtolower(text_field($d,'login',50,true));if(!preg_match('/^[a-z][a-z0-9_.-]{2,39}$/D',$login)||in_array($login,['admin','owner']))fail('Логин: 3–40 латинских букв, цифр, точки, дефиса; admin зарезервирован');
    foreach($s['staff']??[] as $uid=>$u)if($uid!==$id&&$u['login']===$login)fail('Логин уже используется',409);
    $role=$d['role']??'';if(!in_array($role,['dispatcher','courier']))fail('Неверная роль');$active=$d['active']??null;if(!is_bool($active))fail('Неверный статус');
    $password=text_field($d,'password',200);if((!$old||$password!=='')&&strlen($password)<16)fail('Пароль: минимум 16 символов');
    $id=$id?:bin2hex(random_bytes(12));$u=['id'=>$id,'login'=>$login,'name'=>text_field($d,'name',120,true),'role'=>$role,'active'=>$active,'password'=>$password!==''?password_hash($password,PASSWORD_DEFAULT):$old['password'],'version'=>($old['version']??0)+1];
    $s['staff'][$id]=$u;event($s,'staff_updated',['id'=>$id,'login'=>$login,'role'=>$role,'active'=>$active]);return staff_public($u);
}
function admin_actor(): array {
    if(empty($_SESSION['admin']))fail('Войдите в управление',401);
    if(time()-($_SESSION['admin_at']??0)>1800){unset($_SESSION['admin']);fail('Сеанс завершён',401);}
    if($_SESSION['admin']==='owner'){$a=json_decode((string)@file_get_contents(private_dir().'/admin.json'),true);if(!$a||!hash_equals(hash('sha256',$a['password']),$_SESSION['admin_hash']??'')){unset($_SESSION['admin']);fail('Войдите заново',401);}$u=['id'=>'owner','name'=>'Владелец','role'=>'owner'];}
    else{$u=transaction(fn(&$s)=>$s['staff'][$_SESSION['admin']]??null,false);if(!$u||!$u['active']||!hash_equals(hash('sha256',$u['password'].'|'.$u['version']),$_SESSION['admin_hash']??'')){unset($_SESSION['admin']);fail('Доступ сотрудника изменён. Войдите заново',401);}$u=staff_public($u);}
    $_SESSION['admin_at']=time();return $u;
}
function admin_allowed(array $u,string $action): void {
    if($u['role']==='owner')return;
    $allow=['dispatcher'=>['admin_data','admin_order','admin_assign','admin_subscription'],'courier'=>['admin_data','admin_courier_update']];
    if(!in_array($action,$allow[$u['role']]??[],true))fail('Недостаточно прав',403);
}
function assign_courier(array &$s,array $d): array {
    $id=text_field($d,'id',80,true);$o=&$s['orders'][$id];if(!$o)fail('Заказ не найден',404);if(($d['version']??null)!==$o['version'])fail('Заказ изменён',409);if($o['mode']!=='delivery')fail('Курьер назначается только для доставки');if(in_array($o['status'],['delivered','cancelled']))fail('Заказ завершён');
    $courier=text_field($d,'courier_id',80);$u=$s['staff'][$courier]??null;if($courier!==''&&(!$u||!$u['active']||$u['role']!=='courier'))fail('Курьер недоступен');
    $o['courier_id']=$courier;$o['version']++;event($s,'courier_assigned',['id'=>$id,'courier_id'=>$courier]);return order_view($o);
}
function courier_update(array &$s,array $d,string $uid): array {
    $o=$s['orders'][$d['id']??'']??null;if(!$o||($o['courier_id']??'')!==$uid)fail('Заказ недоступен',403);
    $status=$d['status']??'';if(!in_array($o['status'],['confirmed','on_route'])||!in_array($status,['on_route','delivered']))fail('Курьер может принять в путь или завершить назначенный заказ');
    $paid=integer($d['paid']??$o['paid'],0,$o['total']);if($paid<$o['paid'])fail('Уменьшение оплаты доступно диспетчеру');
    return update_order($s,['id'=>$o['id'],'version'=>$d['version']??null,'status'=>$status,'paid'=>$paid,'actual_empty'=>$d['actual_empty']??$o['actual_empty']]);
}
function courier_order(array $o): array {return array_intersect_key($o,array_flip(['id','version','name','phone','address','comment','mode','town','date','slot','confirmed_date','confirmed_slot','status','items','total','paid','actual_empty','empty_count','bottles19','delivery_fee','courier_id']));}
function business_data(array $s,array $actor): array {
    $role=$actor['role'];$v=logistics($s);$orders=$s['orders'];$staff=array_values(array_map('staff_public',$s['staff']??[]));
    if($role==='courier')$orders=array_filter($orders,fn($o)=>($o['courier_id']??'')===$actor['id']&&in_array($o['status'],['confirmed','on_route','delivered'])&&($o['status']!=='delivered'||substr($o['closed_at']??$o['date'],0,10)===date('Y-m-d')));
    $result=['actor'=>$actor,'logistics'=>$v,'orders'=>array_values(array_map($role==='courier'?'courier_order':'order_view',$orders)),'staff'=>$role==='owner'?$staff:array_values(array_filter($staff,fn($u)=>$u['active']&&$u['role']==='courier')),'products'=>[],'users'=>[],'subscriptions'=>[],'events'=>[],'settings'=>[]];
    if($role==='courier'){$result['staff']=[];return $result;}
    $result['subscriptions']=array_values($s['subscriptions']);$result['operations']=operations_summary($s);
    if($role==='dispatcher'){unset($result['operations']);return $result;}
    $p=$s['products'];foreach($p as &$x)$x['reserved']=reserved($s,$x['id']);unset($x);
    return array_replace($result,['settings'=>site_settings($s),'products'=>$p,'users'=>array_values(array_map('user_view',$s['users'])),'events'=>array_slice($s['events'],-200)]);
}
