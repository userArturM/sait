"""Read-only site inbox + explicit transactional import into Water19 Pro 2 schema 2.
No payment/delivery posting. Full inbox includes incompatible items for review.
"""
import argparse, json, sqlite3, urllib.request, getpass, hashlib
from pathlib import Path
from datetime import datetime
from urllib.parse import urlsplit, urlunsplit, quote

SCHEMA = 2

def normalize_url(url):
    p=urlsplit(url)
    if p.scheme!='https' or not p.hostname or p.username or p.password:raise ValueError('Нужен HTTPS адрес без логина и пароля')
    host=p.hostname.encode('idna').decode('ascii')
    if p.port:host+=':'+str(p.port)
    return urlunsplit(('https',host,quote(p.path.rstrip('/'),safe='/'),'', ''))

def fetch(url, token):
    url=normalize_url(url)
    req=urllib.request.Request(url.rstrip('/')+'/api.php?action=bridge_orders',headers={'Authorization':'Bearer '+token,'Accept':'application/json'})
    with urllib.request.urlopen(req,timeout=30) as r: data=json.load(r)
    if data.get('schema')!=1 or not isinstance(data.get('orders'),list): raise ValueError('Неподдерживаемый формат сайта')
    return data

def acknowledge(url,token,external_id,order_id):
    url=normalize_url(url)
    req=urllib.request.Request(url.rstrip('/')+'/api.php?action=bridge_ack',data=json.dumps({'id':external_id,'water19_id':order_id}).encode(),headers={'Authorization':'Bearer '+token,'Content-Type':'application/json'})
    with urllib.request.urlopen(req,timeout=30) as r:return json.load(r)

def import_order(db,order,mapping):
    path=Path(db)
    if not path.is_file():raise ValueError('База Water19 должна существовать')
    c=sqlite3.connect(path,timeout=15,isolation_level=None);c.row_factory=sqlite3.Row;c.execute('PRAGMA foreign_keys=ON')
    try:
        version=c.execute('SELECT version FROM meta').fetchone()[0]
        if version!=SCHEMA:raise ValueError('Неподдерживаемая версия Water19. Не изменяйте базу другой версии.')
        if order['status'] not in ('new','confirmed') or order['paid']!=0:raise ValueError('Переносится только непроведённый заказ без оплаты')
        if not order['items'] or any(i['category']!='water' or i['volume']!=19 for i in order['items']):raise ValueError('Этот заказ остаётся во входящих: Water19 2.0 учитывает только воду 19 л. Аксессуары и 10 л нельзя проводить как воду.')
        signature=hashlib.sha256(json.dumps(order,sort_keys=True,ensure_ascii=False).encode()).hexdigest()
        c.execute('BEGIN IMMEDIATE')
        c.execute('CREATE TABLE IF NOT EXISTS cw_site_links(external_id TEXT PRIMARY KEY,order_id INTEGER UNIQUE NOT NULL REFERENCES orders(id),signature TEXT NOT NULL,imported_at TEXT NOT NULL)')
        previous=c.execute('SELECT order_id FROM cw_site_links WHERE external_id=?',(order['id'],)).fetchone()
        if previous:c.execute('COMMIT');return previous[0],False
        lines=[];wanted={}
        for i in order['items']:
            pid=mapping.get(i['id']);p=c.execute('SELECT * FROM products WHERE id=? AND active=1',(pid,)).fetchone()
            if not p:raise ValueError('Задайте соответствие марки '+i['id'])
            q=i['qty'];price=i['price']
            if isinstance(q,bool) or not isinstance(q,int) or not 1<=q<=99 or not isinstance(price,int) or price<0:raise ValueError('Некорректные позиции')
            lines.append((pid,p['name'],q,price*100));wanted[pid]=wanted.get(pid,0)+q
        if sum(q*price for _,_,q,price in lines)!=order['total']*100:raise ValueError('Заказ с отдельной платой за доставку требует ручного переноса' if order.get('delivery_fee') else 'Итог не совпадает с позициями')
        for pid,q in wanted.items():
            stock=c.execute('SELECT COALESCE(SUM(delta),0) FROM stock WHERE product_id=?',(pid,)).fetchone()[0]
            reserve=c.execute("SELECT COALESCE(SUM(l.qty),0) FROM lines l JOIN orders o ON o.id=l.order_id WHERE l.product_id=? AND o.status IN ('Новый','В доставке')",(pid,)).fetchone()[0]
            if stock-reserve<q:raise ValueError('Недостаточно свободного остатка в Water19')
        # Separate customer snapshot per delivery address to preserve existing profiles.
        cust=c.execute('SELECT * FROM customers WHERE phone=? AND address=? AND active=1',(order['phone'],order['address'])).fetchone()
        if cust:cid=cust['id']
        else:cid=c.execute('INSERT INTO customers(name,phone,address,notes) VALUES(?,?,?,?)',(order['name'] or order['phone'],order['phone'],order['address'],'Создан из '+order['id'])).lastrowid
        note='Сайт: '+order['id']+'; интервал: '+order['slot']+'; план возврата: '+str(order['empty_count'])+'; '+order.get('comment','')
        oid=c.execute('INSERT INTO orders(customer_id,planned,fee,route,position,note,customer_name,customer_phone,customer_address) VALUES(?,?,0,?,0,?,?,?,?)',(cid,order['date'],order['town'],note,order['name'] or order['phone'],order['phone'],order['address'])).lastrowid
        c.executemany('INSERT INTO lines(order_id,product_id,product_name,qty,price) VALUES(?,?,?,?,?)',[(oid,*line) for line in lines])
        c.execute('INSERT INTO cw_site_links VALUES(?,?,?,?)',(order['id'],oid,signature,datetime.now().isoformat()))
        c.execute('INSERT INTO audit(ts,action,detail) VALUES(?,?,?)',(datetime.now().isoformat(),'Импорт сайта',json.dumps({'external':order['id'],'order':oid},ensure_ascii=False)))
        c.execute('COMMIT');return oid,True
    except BaseException:
        if c.in_transaction:c.execute('ROLLBACK')
        raise
    finally:c.close()

def main():
    ap=argparse.ArgumentParser(description='Входящие заказы сайта Чистая вода');ap.add_argument('--site');ap.add_argument('--inbox',default='site-inbox.json');ap.add_argument('--db');ap.add_argument('--map');ap.add_argument('--order');args=ap.parse_args();token=None
    if args.site:
        token=getpass.getpass('Ключ интеграции: ');data=fetch(args.site,token);Path(args.inbox).write_text(json.dumps(data,ensure_ascii=False,indent=2),encoding='utf-8')
    else:data=json.loads(Path(args.inbox).read_text(encoding='utf-8'))
    for o in data['orders']:print(o['id'],o['status'],o['total'],'₽',[(i['name'],i['qty']) for i in o['items']])
    if args.order:
        if not args.db or not args.map:ap.error('Для импорта нужны --db и --map')
        order=next((o for o in data['orders'] if o['id']==args.order),None)
        if not order:raise ValueError('Заказ не найден во входящих')
        mapping=json.loads(Path(args.map).read_text(encoding='utf-8'))
        # Consistent copy before opening writable connection. Close Water19 first.
        source=sqlite3.connect(args.db);backup=Path(args.db).with_name('before_site_'+datetime.now().strftime('%Y%m%d_%H%M%S_%f')+'.sqlite3');dest=sqlite3.connect(backup)
        source.backup(dest);dest.close();source.close()
        oid,created=import_order(args.db,order,mapping);print('Номер Water19:',oid,'Создан' if created else 'Уже импортирован')
        if args.site:acknowledge(args.site,token,order['id'],oid);print('Связь зафиксирована на сайте')
    print('Статусы доставки и оплаты между системами автоматически не синхронизируются.')
if __name__=='__main__':main()
