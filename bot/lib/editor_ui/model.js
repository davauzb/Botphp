'use strict';
/* Holat, tarix (undo/redo), avtosaqlash, API, element yaratish. */
const EMU=9525, SW=9144000, SH=6858000, SWpx=SW/EMU, SHpx=SH/EMU;
const PT=96/72;
const $=(s,r=document)=>r.querySelector(s);
const $$=(s,r=document)=>Array.from(r.querySelectorAll(s));
const uid=p=>p+Math.random().toString(36).slice(2,9)+Date.now().toString(36).slice(-3);

const App={
  tok:TOKEN, sess:null, pres:null, slides:[], design:null, designs:[], fonts:[], images:{}, limits:{},
  cur:0, sel:[], zoom:1, fitScale:1, snap:true, grid:false,
  hist:[], hp:0, dirty:new Set(), orderDirty:false, metaDirty:false, version:0,
  saving:false, saveTimer:null, saveErr:null, editing:null, clip:null, tab:'shakl', panelOpen:false,
  expired:false, lastPing:0,
};
const curSlide=()=>App.slides[App.cur];
const findEl=(id,sl=curSlide())=>sl&&sl.els.find(e=>e.id===id);
const selEls=()=>{const s=curSlide(); return s?App.sel.map(id=>s.els.find(e=>e.id===id)).filter(Boolean):[];};
const clone=o=>JSON.parse(JSON.stringify(o));

/* ---------- API ---------- */
async function api(action,body,extra){
  const h={'authorization':'Bearer '+App.sess};
  let init={method:'POST',headers:h};
  if(body instanceof FormData){init.body=body;} else {h['content-type']='application/json'; init.body=JSON.stringify(body||{});}
  let r;
  try{ r=await fetch('api.php?a='+action,init); }catch(e){ return {ok:false,error:'network',status:0}; }
  let j=null; try{ j=await r.json(); }catch(e){}
  if(r.status===404||r.status===401){ if(action!=='auth') sessionExpired(); return {ok:false,error:'expired',status:r.status}; }
  if(r.status===429) return {ok:false,error:'rate',status:429};
  return Object.assign({status:r.status},j||{ok:false,error:'server'});
}
function sessionExpired(){
  if(App.expired) return; App.expired=true;
  modal({title:'Sessiya tugadi',body:'Xavfsizlik uchun tahrirlash seansi yopildi. Telegram’dagi taqdimot ostidan «✏️ Tahrirlash» tugmasini qayta bosing. Saqlangan o‘zgarishlar yo‘qolmaydi.',
    buttons:[{t:'Yopish',cls:'pri',fn:()=>tgClose()}],locked:true});
}

/* ---------- tarix ---------- */
function pushHist(cmd){ App.hist.length=App.hp; App.hist.push(cmd); if(App.hist.length>80) App.hist.shift(); App.hp=App.hist.length; updateUndoUI(); }
function commitSlide(fn,key){
  const sl=curSlide(); if(!sl) return false;
  const before=JSON.stringify(sl); fn(sl); const after=JSON.stringify(sl);
  if(before===after) return false;
  const last=App.hist[App.hp-1];
  if(key&&last&&last.type==='slide'&&last.key===key&&last.id===sl.id&&Date.now()-last.t<1000&&App.hp===App.hist.length){ last.after=after; last.t=Date.now(); }
  else pushHist({type:'slide',id:sl.id,before,after,key,t:Date.now()});
  markDirty(sl.id); refreshAll(); return true;
}
function commitStruct(fn){
  const bo=App.slides.map(s=>s.id), bmap=new Map(App.slides.map(s=>[s.id,JSON.stringify(s)])), bcur=App.cur;
  fn();
  const ao=App.slides.map(s=>s.id), amap=new Map(App.slides.map(s=>[s.id,JSON.stringify(s)]));
  if(JSON.stringify(bo)===JSON.stringify(ao)&&[...amap].every(([k,v])=>bmap.get(k)===v)) return;
  const bs={},as={}; for(const [k,v] of bmap) if(!amap.has(k)) bs[k]=v; for(const [k,v] of amap) if(!bmap.has(k)) as[k]=v;
  pushHist({type:'struct',bo,ao,bs,as,bcur,acur:App.cur});
  App.orderDirty=true; for(const id of Object.keys(as)) App.dirty.add(id);
  scheduleSave(); refreshAll(true);
}
function applyHist(cmd,undo){
  if(cmd.type==='slide'){
    const i=App.slides.findIndex(s=>s.id===cmd.id); if(i<0) return;
    App.slides[i]=JSON.parse(undo?cmd.before:cmd.after); markDirty(cmd.id); App.cur=i;
    App.sel=App.sel.filter(id=>App.slides[i].els.some(e=>e.id===id));
  } else {
    const order=undo?cmd.bo:cmd.ao, extra=undo?cmd.bs:cmd.as; const have=new Map(App.slides.map(s=>[s.id,s]));
    App.slides=order.map(id=>have.get(id)||JSON.parse(extra[id])); App.orderDirty=true;
    for(const id of order) if(extra[id]) App.dirty.add(id);
    App.cur=Math.min(undo?cmd.bcur:cmd.acur,App.slides.length-1); App.sel=[];
  }
  scheduleSave(); refreshAll(true);
}
function undo(){ if(App.editing) return document.execCommand('undo'); if(App.hp<=0) return; App.hp--; applyHist(App.hist[App.hp],true); updateUndoUI(); }
function redo(){ if(App.editing) return document.execCommand('redo'); if(App.hp>=App.hist.length) return; applyHist(App.hist[App.hp],false); App.hp++; updateUndoUI(); }

/* ---------- avtosaqlash ---------- */
function markDirty(id){ App.dirty.add(id); scheduleSave(); }
function scheduleSave(){ clearTimeout(App.saveTimer); setSaveState('dirty'); App.saveTimer=setTimeout(()=>doSave(),1400); tgConfirm(true); }
async function doSave(force){
  clearTimeout(App.saveTimer);
  if(App.saving){ App.saveTimer=setTimeout(()=>doSave(force),600); return true; }
  if(!App.dirty.size&&!App.orderDirty&&!App.metaDirty) { setSaveState('saved'); return true; }
  for(const id of [...App.dirty]) if(!App.slides.some(s=>s.id===id)) App.dirty.delete(id);   // o'chirilgan slaydlar
  if(!App.dirty.size&&!App.orderDirty&&!App.metaDirty){ setSaveState('saved'); return true; }
  App.saving=true; setSaveState('saving');
  const ids=[...App.dirty];
  const payload={base_version:App.version,order:App.slides.map(s=>s.id),slides:{},title:App.pres.title,design:App.design.key};
  for(const id of ids) payload.slides[id]=App.slides.find(s=>s.id===id);
  const snapshot=new Map(ids.map(id=>[id,JSON.stringify(payload.slides[id])]));
  const r=await api('save',payload);
  App.saving=false;
  if(r.ok){
    App.version=r.version;
    for(const id of ids){ const cur=App.slides.find(s=>s.id===id); if(!cur||JSON.stringify(cur)===snapshot.get(id)) App.dirty.delete(id); }
    App.orderDirty=false; App.metaDirty=false;
    if(App.dirty.size) { scheduleSave(); } else { setSaveState('saved'); tgConfirm(false); }
    return true;
  }
  if(r.error==='conflict'){ setSaveState('error'); modal({title:'Boshqa joyda o‘zgartirilgan',body:'Taqdimot boshqa oynada o‘zgartirilgan. Sahifani qayta ochish uchun Telegram’da «✏️ Tahrirlash» tugmasini yana bosing.',buttons:[{t:'Yopish',cls:'pri',fn:()=>tgClose()}],locked:true}); return false; }
  if(r.error!=='expired'){ setSaveState('error'); App.saveTimer=setTimeout(()=>doSave(),5000); if(r.error==='too_large') toast('Taqdimot juda katta — ba’zi elementlarni olib tashlang'); }
  return false;
}
function setSaveState(s){
  const el=$('#saveState'); if(!el) return;
  const map={saved:['✓ Saqlandi','ok'],saving:['Saqlanmoqda…','busy'],dirty:['● O‘zgartirildi','busy'],error:['⚠ Saqlanmadi — qayta uriniladi','err']};
  el.textContent=map[s][0]; el.className='st '+map[s][1];
}

/* ---------- Telegram ---------- */
const TG=()=>window.Telegram&&window.Telegram.WebApp;
function tgClose(){ const t=TG(); if(t&&t.close) t.close(); else window.close(); }
function tgConfirm(on){ const t=TG(); try{ if(t){ on?t.enableClosingConfirmation():t.disableClosingConfirmation(); } }catch(e){} }
function getInitData(){
  const t=TG(); if(t&&t.initData) return t.initData;
  try{ const h=new URLSearchParams(location.hash.slice(1)); return h.get('tgWebAppData')||''; }catch(e){ return ''; }
}

/* ---------- ranglar / yordamchilar ---------- */
const hex2rgb=h=>[parseInt(h.slice(0,2),16),parseInt(h.slice(2,4),16),parseInt(h.slice(4,6),16)];
const rgb2hex=(r,g,b)=>[r,g,b].map(v=>Math.max(0,Math.min(255,Math.round(v))).toString(16).padStart(2,'0')).join('').toUpperCase();
function cssToHex(c){ const m=/rgba?\(\s*(\d+)[,\s]+(\d+)[,\s]+(\d+)/.exec(c||''); return m?rgb2hex(+m[1],+m[2],+m[3]):null; }
const lumOf=h=>{const [r,g,b]=hex2rgb(h);return (0.2126*r+0.7152*g+0.0722*b)/255;};
const onColor=h=>lumOf(h)>0.62?'1F1F1F':'FFFFFF';
function mixHex(a,b,t){ const A=hex2rgb(a),B=hex2rgb(b); return rgb2hex(A[0]+(B[0]-A[0])*t,A[1]+(B[1]-A[1])*t,A[2]+(B[2]-A[2])*t); }
const clamp=(v,a,b)=>Math.max(a,Math.min(b,v));
const round2=v=>Math.round(v*100)/100;

/* ---------- element yaratish ---------- */
function defRun(o){ return Object.assign({t:'',sz:18,b:false,i:false,u:false,s:false,c:'262626',f:'Calibri',sp:0},o||{}); }
function defPara(runs,o){ return Object.assign({al:'l',bu:'none',sa:0,sb:0,ls:100,runs:runs||[]},o||{}); }
function mkText(text,o){
  o=o||{}; const w=o.w||4200000,h=o.h||800000;
  return {id:uid('e'),t:'shape',n:'Matn',g:'rect',x:o.x??Math.round((SW-w)/2),y:o.y??Math.round((SH-h)/2),w,h,r:0,fh:false,fv:false,lock:false,hid:false,alt:'',adj:{},
    fill:null,ln:null,sh:null,tb:true,ph:'',
    tx:{anchor:'t',ins:[91440,45720,91440,45720],paras:String(text||'').split('\n').map(l=>defPara([defRun({t:l,sz:o.sz||24,c:o.c||'262626',b:!!o.b,f:o.f||'Calibri'})],{al:o.al||'l'}))}};
}
function mkShape(g,o){
  o=o||{}; const w=o.w||2400000,h=o.h||1600000; const acc=App.design?App.design.accent:'4472C4';
  return {id:uid('e'),t:'shape',n:GEOM_META[g]?GEOM_META[g].n:'Shakl',g,x:Math.round((SW-w)/2),y:Math.round((SH-h)/2),w,h,r:0,fh:false,fv:false,lock:false,hid:false,alt:'',adj:{},
    fill:{c:acc,a:100},ln:null,sh:null,tb:false,ph:'',
    tx:{anchor:'ctr',ins:[91440,45720,91440,45720],paras:[defPara([defRun({t:'',sz:20,c:onColor(acc),b:true})],{al:'ctr'})]}};
}
function mkLine(arrow){
  return {id:uid('e'),t:'line',n:arrow?'Strelka':'Chiziq',g:'line',x:Math.round(SW/2-1500000),y:Math.round(SH/2),w:3000000,h:0,r:0,fh:false,fv:false,lock:false,hid:false,alt:'',adj:{},
    fill:null,sh:null,ln:{c:App.design?App.design.title:'1F4E79',w:38100,dash:'solid',head:'none',tail:arrow?'triangle':'none',a:100}};
}
function mkImage(img){
  const maxW=SW*0.6,maxH=SH*0.6; const ar=img.w/img.h; let w=maxW,h=w/ar; if(h>maxH){h=maxH;w=h*ar;}
  return {id:uid('e'),t:'image',n:'Rasm',g:'rect',x:Math.round((SW-w)/2),y:Math.round((SH-h)/2),w:Math.round(w),h:Math.round(h),r:0,fh:false,fv:false,lock:false,hid:false,alt:'',adj:{},
    img:img.id,ln:null,sh:null,crop:{l:0,t:0,r:0,b:0}};
}
function mkTable(rows,cols){
  const acc=App.design?App.design.accent:'4472C4'; const w=7200000, cw=Math.floor(w/cols);
  const cell=(t)=>({t:t||'',b:false,c:null,f:null,al:'l'});
  const R=[]; for(let r=0;r<rows;r++){ const row=[]; for(let c=0;c<cols;c++) row.push(cell(r===0?('Ustun '+(c+1)):'')); R.push(row); }
  return {id:uid('e'),t:'table',n:'Jadval',x:Math.round((SW-w)/2),y:Math.round(SH/2-rows*200000),w:cw*cols,h:400000*rows,r:0,fh:false,fv:false,lock:false,hid:false,alt:'',
    tbl:{rows:R,cols:Array(cols).fill(cw),rh:400000,hdr:true,hc:acc,hf:onColor(acc),bc:'BFBFBF',fc:'262626',sz:14,font:'Calibri',band:false}};
}
function blankSlide(){
  const bg={id:uid('e'),t:'shape',n:'Fon',g:'rect',x:0,y:0,w:SW,h:SH,r:0,fh:false,fv:false,lock:true,hid:false,alt:'',adj:{},fill:{c:'FFFFFF',a:100},ln:null,sh:null,tb:false,ph:'',tx:null};
  return {id:uid('s'),mode:'free',tr:'none',sem:{kind:'matn',title:'',lead:'',points:[],left_title:'',right_title:'',items:[],bullets:[],image:null},els:[bg]};
}
