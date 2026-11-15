<?php
declare(strict_types=1);
function site_settings(array $s): array {
    return array_replace(['version'=>1,'hero_title'=>'Чистая вода. Каждый день.','hero_text'=>'Любимая вода для дома и офиса. Вы выбираете — мы привозим до двери.','announcement'=>'','orders_enabled'=>true,'featured'=>['cristal','kubai','dombay','arkhyz']], $s['settings']??[]);
}
// Legacy products retain their current prices until an owner saves explicit town prices.
function product_price(array $p,string $mode,string $town): int {
    if($mode==='store')return $p['store'];
    return $p['delivery_prices'][$town]??($p['delivery']+($p['category']==='water'&&in_array($town,['dinskaya','ukrainskaya'])?50:0));
}
function validate_delivery_prices($prices): array {
    if(!is_array($prices)||count($prices)<3||count($prices)>30)fail('Укажите цены для всех трёх населённых пунктов');
    $out=[];foreach(['plastunovskaya','dinskaya','ukrainskaya'] as $town)integer($prices[$town]??null,0,100050);foreach($prices as $town=>$price){if(!is_string($town)||!preg_match('/^[a-z][a-z0-9_-]{1,39}$/D',$town))fail('Неверный код цены');$out[$town]=integer($price,0,100050);}
    return $out;
}
function apply_product_prices(array &$p,array $d): void {
    if(array_key_exists('delivery_prices',$d)){
        $p['delivery_prices']=validate_delivery_prices($d['delivery_prices']);
        $p['delivery']=$p['delivery_prices']['plastunovskaya'];
    }elseif(isset($p['delivery_prices'])){
        // Old admin clients may update the base price without erasing other towns.
        $p['delivery_prices']['plastunovskaya']=$p['delivery'];
    }
}
function product_fields(array $d): array {
    $p=[];
    foreach(['name'=>160,'description'=>1200,'brand'=>160,'manufacturer'=>1000,'composition'=>2000,'mineralization'=>500,'storage'=>1200,'shelf_life'=>500,'conformity'=>1200,'origin'=>500] as $key=>$max)
        $p[$key]=text_field($d,$key,$max,in_array($key,['name','description']));
    foreach(['image','label_image'] as $key){
        $image=text_field($d,$key,240,$key==='image');
        if($image!==''){
            if(!preg_match('~^assets/[a-zA-Z0-9_./-]+\.(png|webp|jpe?g)$~i',$image))fail('Неверный путь фото');
            $real=realpath(dirname(__DIR__).'/'.$image);$assets=realpath(dirname(__DIR__).'/assets');
            if(!$real||!is_file($real)||!str_starts_with($real,$assets.'/'))fail('Фото не найдено');
        }
        $p[$key]=$image;
    }
    $p['category']=$d['category']??'';if(!in_array($p['category'],['water','accessory']))fail('Неверная категория');
    $p['volume']=integer($d['volume']??null,0,100);if($p['category']==='water'&&$p['volume']===0)fail('Укажите объём воды');
    foreach(['delivery','store'] as $k)$p[$k]=integer($d[$k]??null,0,100050);
    apply_product_prices($p,$d);
    $p['stock']=$d['stock']??null;if($p['stock']!==null)integer($p['stock'],0,100000);
    $p['active']=$d['active']??true;if(!is_bool($p['active']))fail('Неверный статус');
    return $p;
}
function save_catalog_product(array &$s,array $d): array {
    $fields=product_fields($d);$id=text_field($d,'id',80);
    if($id===''){$id='item-'.bin2hex(random_bytes(8));$p=array_merge($fields,['id'=>$id,'version'=>1]);$s['products'][]=$p;event($s,'product_created',['id'=>$id]);return $p;}
    foreach($s['products'] as &$p)if($p['id']===$id){
        if(($d['version']??null)!==($p['version']??1))fail('Товар изменён. Обновите панель и повторите правку',409);
        if($fields['stock']!==null&&$fields['stock']<reserved($s,$id))fail('Остаток ниже резерва',409);
        if($id==='butyl-tara'&&($fields['category']!=='accessory'||$fields['volume']!==19))fail('У обменной бутыли сохраняются категория Аксессуар и объём 19 л');
        $before=$p;$p=array_merge($p,$fields,['version'=>($p['version']??1)+1]);apply_product_prices($p,$d);event($s,'product_updated',['id'=>$id,'before'=>$before,'after'=>$p]);return $p;
    }
    fail('Товар не найден',404);
}
function save_site_settings(array &$s,array $d): array {
    $old=site_settings($s);if(($d['version']??null)!==$old['version'])fail('Настройки изменены. Обновите панель',409);
    $v=['version'=>$old['version']+1];
    foreach(['hero_title'=>240,'hero_text'=>1000,'announcement'=>1000] as $k=>$n)$v[$k]=text_field($d,$k,$n,$k!=='announcement');
    $v['orders_enabled']=$d['orders_enabled']??null;if(!is_bool($v['orders_enabled']))fail('Неверный режим заказов');
    $ids=$d['featured']??[];if(!is_array($ids)||count($ids)>8||count($ids)!==count(array_unique($ids,SORT_REGULAR)))fail('Выберите до 8 товаров');
    foreach($ids as $id)if(!is_string($id)||!in_array($id,array_column($s['products'],'id'),true))fail('Неизвестный товар');
    $v['featured']=$ids;$s['settings']=$v;event($s,'settings_updated',['before'=>$old,'after'=>$v]);return $v;
}
function upload_catalog_image(array $d): string {
    $encoded=$d['data']??null;if(!is_string($encoded)||strlen($encoded)>4194304)fail('Фото: не более 3 МБ',413);
    $bytes=base64_decode($encoded,true);if($bytes===false||strlen($bytes)>3145728)fail('Некорректное фото');
    $info=@getimagesizefromstring($bytes);$types=[IMAGETYPE_JPEG=>'jpg',IMAGETYPE_PNG=>'png',IMAGETYPE_WEBP=>'webp'];
    if(!$info||!isset($types[$info[2]])||$info[0]<1||$info[1]<1||$info[0]>6000||$info[1]>6000||$info[0]*$info[1]>16000000)fail('Нужно PNG, JPEG или WebP до 6000 px и 16 Мп');
    $dir=dirname(__DIR__).'/assets/uploads';if(!is_dir($dir))fail('Папка assets/uploads отсутствует',503);
    $path='assets/uploads/'.bin2hex(random_bytes(16)).'.'.$types[$info[2]];
    if(file_put_contents(dirname(__DIR__).'/'.$path,$bytes,LOCK_EX)!==strlen($bytes))throw new RuntimeException('Photo write failed');
    chmod(dirname(__DIR__).'/'.$path,0644);return $path;
}
function legal_evidence(): array {
    $dir=private_dir().'/legal';if(!is_dir($dir)&&!mkdir($dir,0700))throw new RuntimeException('Legal archive unavailable');
    $hashes=[];
    foreach(['consent','privacy','offer','returns','delivery-payment'] as $name){
        $bytes=file_get_contents(dirname(__DIR__).'/'.$name.'.html');$hash=hash('sha256',$bytes);$path=$dir.'/'.$hash.'.html';
        if(!is_file($path)){if(file_put_contents($path,$bytes,LOCK_EX)!==strlen($bytes))throw new RuntimeException('Legal archive write failed');chmod($path,0600);}
        $hashes[$name]=$hash;
    }
    return $hashes;
}
