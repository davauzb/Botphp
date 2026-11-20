'use strict';
/* Ishga tushirish: Telegram, avtorizatsiya, yuklash, yuqori panel va asboblar. */
function applyTheme(){
  const t=TG(); const root=document.documentElement;
  const dark=t&&t.colorScheme==='dark'; root.dataset.theme=dark?'dark':'light';
  try{ if(t){ t.ready(); t.expand(); t.setHeaderColor&&t.setHeaderColor(dark?'#1c1c1e':'#ffffff'); t.disableVerticalSwipes&&t.disableVerticalSwipes(); } }catch(e){}
}
function setVH(){ const vv=window.visualViewport; document.documentElement.style.setProperty('--vh',(vv?vv.height:window.innerHeight)+'px'); }

async function authenticate(){
  const initData=getInitData();
  if(!initData){ return {ok:false,error:'no_tg'}; }
  let r; try{ const res=await fetch('api.php?a=auth',{method:'POST',headers:{'content-type':'application/json'},body:JSON.stringify({t:TOKEN,initData})}); r=await res.json().catch(()=>null); if(!res.ok) return {ok:false,error:res.status}; }catch(e){ return {ok:false,error:'network'}; }
  return r||{ok:false};
}
function fail(msg){ const b=$('#boot'); b.classList.add('err'); b.textContent=msg; }

async function boot(){
  applyTheme(); setVH();
  window.addEventListener('resize',setVH); if(window.visualViewport) window.visualViewport.addEventListener('resize',setVH);
  const a=await authenticate();
  if(!a.ok||!a.session){ fail(a.error==='no_tg'?'Bu sahifa faqat Telegram ichida ochiladi.':'Havola eskirgan yoki yaroqsiz. Telegram’da «✏️ Tahrirlash» tugmasini qayta bosing.'); return; }
  App.sess=a.session;
  try{ history.replaceState(null,'',location.pathname); }catch(e){}
  const r=await api('load',{});
  if(!r.ok){ fail('Taqdimotni yuklab bo‘lmadi.'); return; }
  App.pres=r.pres; App.version=r.pres.version; App.slides=r.pres.slides; App.designs=r.designs; App.fonts=r.fonts; App.limits=r.limits;
  App.design=Object.assign({},r.pres.design); const dd=App.designs.find(d=>d.key===App.design.key); if(dd) App.design=Object.assign({},App.design,{accent:dd.accent,title:dd.title,skin:dd.skin});
  r.images.forEach(i=>App.images[i.id]=i);
  buildChrome(); initInteract(); applyScale(); refreshAll(true); renderPanel(true);
  $('#boot').remove(); setSaveState('saved');
  setInterval(()=>{ if(!document.hidden&&!App.expired) api('ping',{}); },240000);
  window.addEventListener('beforeunload',()=>{ if(App.dirty.size) doSave(); });
  document.addEventListener('visibilitychange',()=>{ if(document.hidden&&(App.dirty.size||App.orderDirty)) doSave(); });
}

function buildChrome(){
  const top=$('#top'); top.textContent='';
  const ib=(id,label,title,fn,cls)=>keepFocus(h('button',{id,type:'button',class:'ib '+(cls||''),title,onclick:fn},label));
  top.append(ib('bMenu',ic('menu'),'Menyu',e=>openMenu(e.currentTarget)),ib('bUndo',ic('undo'),'Bekor qilish (Ctrl+Z)',undo),ib('bRedo',ic('redo'),'Qaytarish (Ctrl+Y)',redo),
    h('div',{class:'ttl',id:'ttl',title:'Nomini o‘zgartirish',onclick:renameDialog},App.pres.title||'Taqdimot'),
    h('span',{id:'saveState',class:'st ok'},'✓ Saqlandi'),
    keepFocus(h('button',{id:'bSend',type:'button',class:'btn pri send',onclick:sendOut},ic('send'),h('span',null,'Yuborish'))));
  const tools=$('#tools'); tools.textContent='';
  const tb=(label,icon,fn,id)=>keepFocus(h('button',{type:'button',class:'tool',id,onclick:fn},h('span',{class:'ti'},icon),h('span',{class:'tl2'},label)));
  tools.append(tb('Matn',ic('text'),insertText),tb('Shakl',ic('shape'),e=>insertShape(e.currentTarget)),tb('Rasm',ic('image'),insertImage),tb('Chiziq',ic('line'),e=>insertLine(e.currentTarget)),tb('Jadval',ic('table'),insertTable),
    tb('Slayd',ic('plus'),e=>{ e.currentTarget.dataset.up='1'; openNewSlideMenu(e.currentTarget); }),h('span',{class:'tsep'}),
    tb('Ko‘p tanlash',ic('multi'),e=>{ App.multi=!App.multi; e.currentTarget.classList.toggle('on',App.multi); },'tMulti'),
    tb('Xususiyatlar',ic('sliders'),()=>{ document.body.classList.toggle('panel-open'); renderPanel(true); },'tProps'));
  document.querySelectorAll('#tools .tool').forEach(b=>b.querySelector('.tl2')&&(b.dataset.up='1'));
  const q=$('#qbar'); q.textContent='';
  const qb=(id,l,t,fn)=>keepFocus(h('button',{id,type:'button',title:t,onclick:fn},l));
  q.append(qb('qEdit',ic('pencil'),'Matnni tahrirlash',()=>{ const e=selEls()[0]; if(e) startEdit(e.id,false); }),qb('qDup',ic('copy'),'Nusxa',dupSel),qb('qLock',ic('lock'),'Qulflash',lockSel),qb('qFront',ic('front'),'Oldinga',()=>zOrder('front')),qb('qDel',ic('trash'),'O‘chirish',deleteSel),qb('qProps',ic('sliders'),'Xususiyatlar',openPanel));
  const sb=$('#stripBtns'); sb.textContent='';
  const sbt=(l,t,fn)=>keepFocus(h('button',{type:'button',title:t,onclick:fn},l));
  const mvBtn=(dir,t)=>keepFocus(h('button',{type:'button',title:t,onclick:()=>{ moveSlide(App.cur,App.cur+dir); revealThumb(); }},[ic(dir<0?'left':'right','hv'),ic(dir<0?'up':'down','vv')]));
  sb.append(mvBtn(-1,'Slaydni oldinga surish'),mvBtn(1,'Slaydni orqaga surish'),sbt(ic('plus'),'Yangi slayd',e=>{ e.currentTarget.dataset.up='1'; openNewSlideMenu(e.currentTarget); }),sbt(ic('copy'),'Slayd nusxasi',()=>dupSlide(App.cur)),sbt(ic('trash'),'Slaydni o‘chirish',()=>delSlide(App.cur)),sbt(ic('play'),'Namoyish',()=>startPresent()));
  const zoom=$('#zoombar'); zoom.textContent='';
  zoom.append(keepFocus(h('button',{type:'button',title:'Kichraytirish',onclick:()=>setZoom(App.zoom/1.2)},ic('minus'))),h('span',{id:'zoomLbl',title:'Asl o‘lcham',onclick:()=>setZoom(1)},'100%'),keepFocus(h('button',{type:'button',title:'Kattalashtirish',onclick:()=>setZoom(App.zoom*1.2)},ic('plus'))));
}

function openMenu(anchor){
  const it=(l,fn)=>h('button',{type:'button',onclick:()=>{ closePopover(); fn(); }},l);
  popover(anchor,h('div',{class:'menu'},
    it([ic('pencil'),'Taqdimot nomi'],renameDialog),it([ic('play'),'Namoyish'],()=>startPresent()),it([ic('search'),'Qidirish va almashtirish'],openFindReplace),
    it([ic(App.snap?'check':'magnet'),'Yopishtirish (snap)'+(App.snap?' — yoqilgan':'')],()=>{ App.snap=!App.snap; toast(App.snap?'Snap yoqildi':'Snap o‘chirildi'); }),
    it([ic('grid'),'To‘r (setka)'+(App.grid?' — yoqilgan':'')],()=>{ App.grid=!App.grid; renderStage(); }),
    it([ic('keys'),'Tezkor tugmalar'],showKeys),it([ic('send'),'Telegram’ga yuborish (PPTX)'],()=>sendOut('pptx')),it([ic('send'),'Telegram’ga PDF sifatida'],()=>sendOut('pdf')),it([ic('close'),'Yopish'],async()=>{ await doSave(true); tgClose(); })));
}
function showKeys(){
  modal({title:'Tezkor tugmalar',body:h('div',{class:'keys'},[['Ctrl+Z / Ctrl+Y','Bekor qilish / qaytarish'],['Ctrl+C / X / V','Nusxa / kesish / qo‘yish'],['Ctrl+D','Takrorlash'],['Ctrl+A','Hammasini tanlash'],['Delete','O‘chirish'],['Strelkalar','1 px siljitish (Shift — 10 px)'],['Shift + sudrash','To‘g‘ri chiziq / nisbat saqlanadi'],['Alt + sudrash','Yopishtirishsiz'],['Enter yoki 2×bosish','Matnni tahrirlash'],['Ctrl+B / I / U','Qalin / qiya / tagiga chizish'],['Ctrl+S','Hozir saqlash']].map(([k,v])=>h('div',{class:'krow'},h('kbd',null,k),h('span',null,v)))),buttons:[{t:'Yopish'}]});
}
function renameDialog(){
  const i=h('input',{type:'text',maxlength:200,value:App.pres.title||''});
  modal({title:'Taqdimot nomi',body:field('Nomi',i),buttons:[{t:'Bekor qilish'},{t:'Saqlash',cls:'pri',fn:()=>{ const v=i.value.trim(); if(v){ App.pres.title=v; $('#ttl').textContent=v; App.metaDirty=true; scheduleSave(); } }}]});
}
async function sendOut(fmt){
  fmt=(fmt==='pdf')?'pdf':'pptx';
  if(App.editing) endEdit(true);
  const btn=$('#bSend'); if(btn.disabled) return; btn.disabled=true; btn.classList.add('busy'); btn.lastChild.textContent=fmt==='pdf'?'PDF tayyorlanmoqda…':'Yuborilmoqda…';
  try{
    const ok=await doSave(true); if(!ok&&(App.dirty.size||App.orderDirty)){ toast('Avval saqlash kerak — qayta urining'); return; }
    const r=await api('export',{format:fmt});
    if(r.ok){ modal({title:'Yuborildi ✅',body:(fmt==='pdf'?'PDF fayl':'Tahrirlangan taqdimot')+' Telegram chatiga fayl sifatida yuborildi. Fayl tagidagi «✏️ Qayta tahrirlash» tugmasi orqali yana tahrirlashingiz mumkin.',buttons:[{t:'Tahrirlashni davom ettirish'},{t:'Yopish',cls:'pri',fn:()=>tgClose()}]}); }
    else toast(r.error==='rate'?'Juda tez-tez yuborildi — biroz kuting':(r.error==='no_pdf'?'Bu serverda PDF yaratish yoqilmagan':'Yuborib bo‘lmadi — qayta urining'));
  } finally { btn.disabled=false; btn.classList.remove('busy'); btn.lastChild.textContent='Yuborish'; }
}
boot();
