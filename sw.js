const CACHE='cw3-public-20261025-admin-business1';
const PUBLIC=['index.html','catalog.html','premium.css','crystal.css','crystal.js','app.js','catalog-data.json','manifest.json','assets/logo.png','assets/icon-192.png'];
self.addEventListener('install',e=>e.waitUntil(caches.open(CACHE).then(c=>c.addAll(PUBLIC)).then(()=>self.skipWaiting())));
self.addEventListener('activate',e=>e.waitUntil(caches.keys().then(keys=>Promise.all(keys.filter(k=>k.startsWith('clean-water-')||k.startsWith('cw3-public-')&&k!==CACHE).map(k=>caches.delete(k)))).then(()=>self.clients.claim())));
self.addEventListener('fetch',e=>{const r=e.request,u=new URL(r.url);if(r.method!=='GET'||u.origin!==location.origin)return;
 if(u.pathname.endsWith('.php')||/\/(backend|admin|account)(\/|\.|$)/.test(u.pathname))return;
 const allowed=PUBLIC.some(p=>u.pathname.endsWith('/'+p))||u.pathname.endsWith('/')||u.pathname.includes('/assets/');if(!allowed)return;
 e.respondWith(fetch(r).then(res=>{if(res.ok&&res.type==='basic'){const copy=res.clone();caches.open(CACHE).then(c=>c.put(r,copy));}return res;}).catch(async()=>{const cached=await caches.match(r);if(cached)return cached;if(r.mode==='navigate')return caches.match('index.html');throw Error('Нет соединения');}));
});
