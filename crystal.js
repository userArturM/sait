'use strict';
(() => {
 const main=document.querySelector('main');
 if(!main||document.body.classList.contains('admin-page'))return;
 const current=location.pathname.split('/').pop()||'index.html';
 const links=[['water','Вода 19 л','Питьевая вода для дома и офиса'],['accessory','Помпы и тара','Всё для удобного использования']];
 const sidebar=document.createElement('aside');sidebar.className='catalog-sidebar';sidebar.setAttribute('aria-label','Каталог и информация');
 sidebar.innerHTML=`<h2>Каталог товаров</h2><nav>${links.map(([cat,title,desc])=>`<a href="catalog.html?category=${cat}" data-catalog-category="${cat}"><span class="side-drop" aria-hidden="true">${cat==='water'?'◊':'▤'}</span><span><b>${title}</b><small>${desc}</small></span><span aria-hidden="true">›</span></a>`).join('')}<a href="catalog.html" data-catalog-category="all"><span class="side-drop" aria-hidden="true">▦</span><span><b>Весь каталог</b><small>Все марки и аксессуары</small></span><span aria-hidden="true">›</span></a></nav><div class="sidebar-note"><span class="kicker blue">МЫ РЯДОМ</span><h3>Вода без лишних забот</h3><p>Доставляем домой и в офис. Принимаем пустые бутыли при обмене.</p><a href="delivery.html">Условия доставки →</a></div><nav class="side-info"><a href="delivery-payment.html">Доставка и оплата <span>›</span></a><a href="faq.html">Вопросы о заказе <span>›</span></a><a href="contacts.html">Адрес самовывоза <span>›</span></a></nav><div class="sidebar-phone"><small>Поможем выбрать воду</small><a href="tel:+79282235333">+7 928 223-53-33</a><small>Ежедневно, 08:00–20:00</small></div>`;
 const isInfo=main.hasAttribute('data-info-page');
 if(current==='index.html') {
   const section=document.querySelector('.home-shopping');
   if(section){const shell=document.createElement('div');shell.className='shop-layout wrap';section.before(shell);section.classList.remove('wrap');shell.append(sidebar,section);}
 } else if(current==='catalog.html'){
   const section=document.querySelector('.catalog3');const shell=document.createElement('div');shell.className='shop-layout wrap';section.before(shell);section.classList.remove('wrap');shell.append(sidebar,section);
 } else if(isInfo){
   const shell=document.createElement('div');shell.className='information-layout wrap';const body=document.createElement('div');body.className='information-body';while(main.firstChild)body.append(main.firstChild);shell.append(sidebar,body);main.append(shell);
 }
 if(current!=='index.html'){
   const crumb=document.createElement('nav');crumb.className='breadcrumbs wrap';crumb.setAttribute('aria-label','Хлебные крошки');const title=document.querySelector('h1')?.textContent.replace(/\s+/g,' ').trim()||'Информация';const home=document.createElement('a');home.href='index.html';home.textContent='Главная';const span=document.createElement('span');span.textContent=' / '+title;crumb.append(home,span);main.prepend(crumb);
 }
 const btn=document.createElement('button');btn.type='button';btn.className='back-top';btn.setAttribute('aria-label','Наверх');btn.textContent='↑';btn.hidden=true;document.body.append(btn);btn.addEventListener('click',()=>window.scrollTo({top:0,behavior:matchMedia('(prefers-reduced-motion: reduce)').matches?'instant':'smooth'}));addEventListener('scroll',()=>{btn.hidden=scrollY<650},{passive:true});
})();
