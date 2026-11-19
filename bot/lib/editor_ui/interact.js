'use strict';
/* Sahna bilan o'zaro ta'sir: tanlash, ko'chirish, o'lcham, burilish, snap, klaviatura, almashish buferi, slayd amallari. */
const PTRS=new Map();
let DR=null; let lastTap={id:null,t:0};
const SNAP_PX=7;

function setSel(ids){ App.sel=ids; renderOverlay(); if(typeof renderPanel==='function') renderPanel(); }
function markFree(sl){ if(sl.mode!=='free') sl.mode='free'; }

/* ---------- elementlar bilan amallar ---------- */
function addElement(el,editAfter){
  commitSlide(sl=>{ sl.els.push(el); markFree(sl); });
  App.sel=[el.id]; renderOverlay(); renderPanel();
  if(editAfter) setTimeout(()=>startEdit(el.id,true),30);
}
function deleteSel(){
  const ids=App.sel.filter(id=>{const e=findEl(id);return e&&!e.lock;}); if(!ids.length) return;
  commitSlide(sl=>{ sl.els=sl.els.filter(e=>!ids.includes(e.id)); markFree(sl); }); App.sel=[]; renderOverlay(); renderPanel();
}
function copySel(){ const els=selEls().filter(e=>!e.lock); if(!els.length) return false; App.clip={els:clone(els),n:0}; toast('Nusxa olindi'); return true; }
function pasteClip(){
  if(!App.clip||!App.clip.els.length) return; App.clip.n++; const off=App.clip.n*190500; const ne=[];
  commitSlide(sl=>{ App.clip.els.forEach(e0=>{ const e=clone(e0); e.id=uid('e'); e.x+=off; e.y+=off; if(e.ph) e.ph=''; sl.els.push(e); ne.push(e.id); }); markFree(sl); });
  App.sel=ne; renderOverlay(); renderPanel();
}
function dupSel(){ const els=selEls().filter(e=>!e.lock); if(!els.length) return; const ne=[];
  commitSlide(sl=>{ els.forEach(e0=>{ const e=clone(e0); e.id=uid('e'); e.x+=190500; e.y+=190500; if(e.ph) e.ph=''; sl.els.push(e); ne.push(e.id); }); markFree(sl); });
  App.sel=ne; renderOverlay(); renderPanel(); }
function cutSel(){ if(copySel()) deleteSel(); }
function selectAll(){ setSel(curSlide().els.filter(e=>!e.lock&&!e.hid).map(e=>e.id)); }
function nudge(dx,dy){ const els=selEls().filter(e=>!e.lock); if(!els.length) return;
  commitSlide(sl=>{ els.forEach(e0=>{const e=sl.els.find(x=>x.id===e0.id); if(e){ e.x+=dx*EMU; e.y+=dy*EMU; }}); markFree(sl); },'nudge'); }
function lockSel(){ const els=selEls(); if(!els.length) return; const to=!els.every(e=>e.lock);
  commitSlide(sl=>{ els.forEach(e0=>{const e=sl.els.find(x=>x.id===e0.id); if(e) e.lock=to;}); markFree(sl); }); renderPanel(); }
function hideSel(){ const els=selEls(); if(!els.length) return; const to=!els.every(e=>e.hid);
  commitSlide(sl=>{ els.forEach(e0=>{const e=sl.els.find(x=>x.id===e0.id); if(e) e.hid=to;}); markFree(sl); }); }
function zOrder(where){
  const ids=selEls().map(e=>e.id); if(!ids.length) return;
  commitSlide(sl=>{
    const els=sl.els; const picked=els.filter(e=>ids.includes(e.id)); let rest=els.filter(e=>!ids.includes(e.id));
    if(where==='front') sl.els=rest.concat(picked);
    else if(where==='back'){ const locked=rest.filter(e=>e.lock&&e.x<=0&&e.y<=0&&e.w>=SW&&e.h>=SH); const others=rest.filter(e=>!locked.includes(e)); sl.els=locked.concat(picked,others); }
    else { const arr=els.slice(); const dir=where==='up'?1:-1; const order=dir>0?[...arr.keys()].reverse():[...arr.keys()];
      for(const i of order){ if(!ids.includes(arr[i].id)) continue; const j=i+dir; if(j<0||j>=arr.length||ids.includes(arr[j].id)) continue; [arr[i],arr[j]]=[arr[j],arr[i]]; } sl.els=arr; }
    markFree(sl);
  });
}
function alignSel(kind){
  const els=selEls().filter(e=>!e.lock); if(!els.length) return;
  commitSlide(sl=>{
    const T=els.map(e0=>sl.els.find(x=>x.id===e0.id));
    let box; if(T.length===1) box={x0:0,y0:0,x1:SWpx,y1:SHpx}; else { box={x0:1e9,y0:1e9,x1:-1e9,y1:-1e9}; T.forEach(e=>{const b=elBBox(e); box.x0=Math.min(box.x0,b.x0);box.y0=Math.min(box.y0,b.y0);box.x1=Math.max(box.x1,b.x1);box.y1=Math.max(box.y1,b.y1);}); }
    T.forEach(e=>{ const b=elBBox(e); let dx=0,dy=0;
      if(kind==='l') dx=box.x0-b.x0; if(kind==='cx') dx=(box.x0+box.x1)/2-(b.x0+b.x1)/2; if(kind==='r') dx=box.x1-b.x1;
      if(kind==='t') dy=box.y0-b.y0; if(kind==='cy') dy=(box.y0+box.y1)/2-(b.y0+b.y1)/2; if(kind==='b') dy=box.y1-b.y1;
      e.x+=Math.round(dx*EMU); e.y+=Math.round(dy*EMU); });
    markFree(sl);
  });
}
function distributeSel(axis){
  const els=selEls().filter(e=>!e.lock); if(els.length<3) { toast('Kamida 3 ta element tanlang'); return; }
  commitSlide(sl=>{
    const T=els.map(e0=>sl.els.find(x=>x.id===e0.id)); const key=axis==='h'?'x':'y', sz=axis==='h'?'w':'h';
    T.sort((a,b)=>a[key]-b[key]); const first=T[0][key], last=T[T.length-1][key]+T[T.length-1][sz]; const tot=T.reduce((s,e)=>s+e[sz],0); const gap=(last-first-tot)/(T.length-1);
    let pos=first; T.forEach(e=>{ e[key]=Math.round(pos); pos+=e[sz]+gap; }); markFree(sl);
  });
}

/* ---------- slayd amallari ---------- */
function gotoSlide(i){ if(App.editing) endEdit(true); App.cur=clamp(i,0,App.slides.length-1); App.sel=[]; refreshAll(); }
function newSem(kind){
  const P=(h,t,s)=>({head:h,text:t,side:s||''});
  switch(kind){
    case 'matn': return {kind,title:'Yangi slayd',lead:'Slaydning asosiy fikrini shu yerga yozing.',points:[P('Birinchi fikr','Bu yerga tushuntirish yozing.'),P('Ikkinchi fikr','Bu yerga tushuntirish yozing.'),P('Uchinchi fikr','Bu yerga tushuntirish yozing.')]};
    case 'tarif': return {kind,title:'Ta’rif',lead:'Tushunchaning to‘liq ta’rifi shu yerga yoziladi.',points:[P('Belgi 1','Qisqa izoh.'),P('Belgi 2','Qisqa izoh.'),P('Belgi 3','Qisqa izoh.')]};
    case 'kartalar': return {kind,title:'Yo‘nalishlar',lead:'',points:[P('Yo‘nalish 1','Izoh.'),P('Yo‘nalish 2','Izoh.'),P('Yo‘nalish 3','Izoh.'),P('Yo‘nalish 4','Izoh.')]};
    case 'bosqichlar': return {kind,title:'Bosqichlar',lead:'Jarayon ketma-ket qadamlardan iborat.',points:[P('1-qadam','Izoh.'),P('2-qadam','Izoh.'),P('3-qadam','Izoh.'),P('4-qadam','Izoh.')]};
    case 'xronologiya': return {kind,title:'Xronologiya',lead:'',points:[P('1-davr','Izoh.'),P('2-davr','Izoh.'),P('3-davr','Izoh.'),P('4-davr','Izoh.')]};
    case 'taqqoslash': return {kind,title:'Taqqoslash',lead:'',left_title:'Afzalliklari',right_title:'Kamchiliklari',points:[P('A1','Izoh.','chap'),P('A2','Izoh.','chap'),P('A3','Izoh.','chap'),P('B1','Izoh.','ong'),P('B2','Izoh.','ong'),P('B3','Izoh.','ong')]};
    case 'xulosa': return {kind,title:'Xulosa',lead:'Yakuniy fikr.',points:[P('Xulosa 1','Izoh.'),P('Xulosa 2','Izoh.'),P('Xulosa 3','Izoh.')]};
    case 'reja': return {kind,title:'Reja',items:App.slides.map(s=>s.sem&&s.sem.title).filter(Boolean).slice(0,8)};
    default: return {kind:'matn',title:'',points:[]};
  }
}
async function addSlide(kind,at){
  if(App.slides.length>=(App.limits.slides||120)){ toast('Slaydlar soni chegaraga yetdi'); return; }
  const idx=at==null?App.cur+1:at; let sl;
  if(kind==='blank'){ sl=blankSlide(); const bg=App.slides[App.cur]&&App.slides[App.cur].els.find(e=>e.lock&&e.x<=0&&e.w>=SW); if(bg&&bg.fill&&bg.fill.c) sl.els[0].fill=clone(bg.fill); }
  else {
    const sem=Object.assign({kind:'matn',title:'',lead:'',points:[],left_title:'',right_title:'',items:[],bullets:[],image:null},newSem(kind));
    toast('Slayd tayyorlanmoqda…'); const r=await api('regen',{sem,n:idx+1,total:App.slides.length+1,design:App.design.key});
    if(!r.ok){ toast('Slayd yaratilmadi'); return; }
    sl={id:uid('s'),mode:'auto',tr:'none',sem:r.sem,els:r.els};
  }
  commitStruct(()=>{ App.slides.splice(idx,0,sl); App.cur=idx; App.sel=[]; });
}
function dupSlide(i){
  if(App.slides.length>=(App.limits.slides||120)) return toast('Slaydlar soni chegaraga yetdi');
  const c=clone(App.slides[i]); c.id=uid('s'); c.els.forEach(e=>{e.id=uid('e');});
  commitStruct(()=>{ App.slides.splice(i+1,0,c); App.cur=i+1; App.sel=[]; });
}
function delSlide(i){
  if(App.slides.length<=1) return toast('Oxirgi slaydni o‘chirib bo‘lmaydi');
  commitStruct(()=>{ App.slides.splice(i,1); App.cur=Math.min(App.cur>i?App.cur-1:App.cur,App.slides.length-1); App.sel=[]; });
}
function moveSlide(i,j){
  if(i===j||j<0||j>=App.slides.length) return;
  commitStruct(()=>{ const [s]=App.slides.splice(i,1); App.slides.splice(j,0,s); App.cur=j; });
}

/* ---------- snap ---------- */
function snapTargets(exclude){
  const xs=[0,SWpx/2,SWpx], ys=[0,SHpx/2,SHpx];
  curSlide().els.forEach(e=>{ if(exclude.has(e.id)||e.hid||(e.lock&&e.w>=SW)) return; const b=elBBox(e); xs.push(b.x0,(b.x0+b.x1)/2,b.x1); ys.push(b.y0,(b.y0+b.y1)/2,b.y1); });
  return {xs,ys};
}
function snapBox(b,T,thr){
  let bestX=null,bestY=null;
  for(const k of [b.x0,(b.x0+b.x1)/2,b.x1]) for(const t of T.xs){ const d=t-k; if(Math.abs(d)<=thr&&(bestX===null||Math.abs(d)<Math.abs(bestX.d))) bestX={d,at:t}; }
  for(const k of [b.y0,(b.y0+b.y1)/2,b.y1]) for(const t of T.ys){ const d=t-k; if(Math.abs(d)<=thr&&(bestY===null||Math.abs(d)<Math.abs(bestY.d))) bestY={d,at:t}; }
  return {x:bestX,y:bestY};
}
function showGuides(g){
  $$('#ovl .guide').forEach(n=>n.remove()); if(!g) return;
  const o=$('#ovl'); if(g.x){ const d=document.createElement('div'); d.className='guide v'; d.style.left=g.x.at+'px'; o.appendChild(d); }
  if(g.y){ const d=document.createElement('div'); d.className='guide h'; d.style.top=g.y.at+'px'; o.appendChild(d); }
}

/* ---------- pointer ---------- */
function elAt(ev){ const n=ev.target&&ev.target.closest?ev.target.closest('#stage .el'):null; return n?n.dataset.id:null; }
function onDown(ev){
  if(ev.pointerType==='mouse'&&ev.button!==0) return;
  if(ev.target.closest('#qbar,.popover,.modal')) return;
  PTRS.set(ev.pointerId,{x:ev.clientX,y:ev.clientY});
  if(PTRS.size===2){ startPinch(); return; }
  if(App.editing){ const id=elAt(ev); if(id===App.editing.id) return; endEdit(true); }
  const hd=ev.target.closest('#ovl .hd');
  const wrap=$('#wrap'); 
  if(hd){ beginHandle(ev,hd); return; }
  const id=elAt(ev); const multi=ev.shiftKey||ev.ctrlKey||ev.metaKey||App.multi;
  if(id){
    const e=findEl(id); 
    if(multi){ App.sel=App.sel.includes(id)?App.sel.filter(x=>x!==id):[...App.sel,id]; renderOverlay(); renderPanel(); if(!App.sel.includes(id)) return; }
    else if(!App.sel.includes(id)){ App.sel=[id]; renderOverlay(); renderPanel(); }
    beginMove(ev,id);
  } else {
    if(!multi&&App.sel.length){ App.sel=[]; renderOverlay(); renderPanel(); }
    beginMarquee(ev,multi);
  }
}
function beginMove(ev,id){
  const els=selEls().filter(e=>!e.lock); if(!els.length) { DR={mode:'tap',id,x:ev.clientX,y:ev.clientY}; return; }
  const sl=curSlide();
  DR={mode:'move',pending:true,sx:ev.clientX,sy:ev.clientY,id,before:JSON.stringify(sl),orig:els.map(e=>({id:e.id,x:e.x,y:e.y})),T:snapTargets(new Set(els.map(e=>e.id))),moved:false};
}
function beginMarquee(ev,add){
  const p=stagePoint(ev); DR={mode:'marq',sx:p.x,sy:p.y,add,base:add?[...App.sel]:[],moved:false,cx:ev.clientX,cy:ev.clientY};
}
function beginHandle(ev,hd){
  const h=hd.dataset.h; const sl=curSlide(); const before=JSON.stringify(sl);
  if(h[0]==='g'){
    const els=selEls().filter(e=>!e.lock); let x0=1e9,y0=1e9,x1=-1e9,y1=-1e9; els.forEach(e=>{const b=elBBox(e); x0=Math.min(x0,b.x0);y0=Math.min(y0,b.y0);x1=Math.max(x1,b.x1);y1=Math.max(y1,b.y1);});
    DR={mode:'gresize',h:h.slice(1),before,box:{x0,y0,x1,y1},orig:els.map(e=>clone(e)),p0:stagePoint(ev)}; App.dragging=true; return;
  }
  const e=selEls()[0]; if(!e) return;
  if(h==='rot'){ const cx=(e.x+e.w/2)/EMU, cy=(e.y+e.h/2)/EMU; DR={mode:'rotate',id:e.id,before,cx,cy}; }
  else if(h==='p1'||h==='p2'){ DR={mode:'lineend',id:e.id,end:h,before}; }
  else DR={mode:'resize',h,id:e.id,before,o:clone(e),p0:stagePoint(ev),keep:e.t==='image'||e.t==='table'};
  App.dragging=true;
}
function onMove(ev){
  if(!PTRS.has(ev.pointerId)) return; PTRS.set(ev.pointerId,{x:ev.clientX,y:ev.clientY});
  if(PTRS.size>=2){ doPinch(); ev.preventDefault(); return; }
  if(!DR) return;
  const sl=curSlide();
  if(DR.mode==='move'){
    const dx=(ev.clientX-DR.sx)/App.scale, dy=(ev.clientY-DR.sy)/App.scale;
    if(DR.pending){ if(Math.hypot(dx,dy)*App.scale<4) return; DR.pending=false; DR.moved=true; App.dragging=true; $('#qbar').classList.remove('show'); }
    let ddx=dx, ddy=dy;
    if(ev.shiftKey){ if(Math.abs(dx)>Math.abs(dy)) ddy=0; else ddx=0; }
    // bbox
    let b={x0:1e9,y0:1e9,x1:-1e9,y1:-1e9};
    DR.orig.forEach(o=>{ const e=sl.els.find(x=>x.id===o.id); const tmp=Object.assign({},e,{x:o.x+ddx*EMU,y:o.y+ddy*EMU}); const bb=elBBox(tmp); b.x0=Math.min(b.x0,bb.x0);b.y0=Math.min(b.y0,bb.y0);b.x1=Math.max(b.x1,bb.x1);b.y1=Math.max(b.y1,bb.y1); });
    let g=null;
    if(App.snap&&!ev.altKey){ g=snapBox(b,DR.T,SNAP_PX/App.scale); if(g.x) ddx+=g.x.d; if(g.y) ddy+=g.y.d; }
    else if(App.grid){ ddx=Math.round(ddx/20)*20; ddy=Math.round(ddy/20)*20; }
    DR.orig.forEach(o=>{ const e=sl.els.find(x=>x.id===o.id); e.x=Math.round(o.x+ddx*EMU); e.y=Math.round(o.y+ddy*EMU); });
    liveUpdate(DR.orig.map(o=>o.id)); showGuides(g); ev.preventDefault();
  } else if(DR.mode==='marq'){
    const p=stagePoint(ev); if(!DR.moved&&Math.hypot(ev.clientX-DR.cx,ev.clientY-DR.cy)<5) return; DR.moved=true;
    const x0=Math.min(p.x,DR.sx),y0=Math.min(p.y,DR.sy),x1=Math.max(p.x,DR.sx),y1=Math.max(p.y,DR.sy);
    let m=$('#ovl .marq'); if(!m){ m=document.createElement('div'); m.className='marq'; $('#ovl').appendChild(m); }
    Object.assign(m.style,{left:x0+'px',top:y0+'px',width:(x1-x0)+'px',height:(y1-y0)+'px'});
    DR.rect={x0,y0,x1,y1}; ev.preventDefault();
  } else if(DR.mode==='resize'){ doResize(ev); ev.preventDefault(); }
  else if(DR.mode==='gresize'){ doGroupResize(ev); ev.preventDefault(); }
  else if(DR.mode==='rotate'){
    const p=stagePoint(ev); const e=findEl(DR.id); let a=Math.atan2(p.y-DR.cy,p.x-DR.cx)*180/Math.PI+90; a=((a%360)+360)%360;
    const snapA=Math.round(a/15)*15; if(ev.shiftKey||Math.abs(a-snapA)<3) a=snapA%360; e.r=round2(a); liveUpdate([e.id]); showAngle(e.r,p); ev.preventDefault();
  } else if(DR.mode==='lineend'){
    const p=stagePoint(ev); const e=findEl(DR.id);
    const s=[e.x/EMU+(e.fh?e.w/EMU:0), e.y/EMU+(e.fv?e.h/EMU:0)], t=[e.x/EMU+(e.fh?0:e.w/EMU), e.y/EMU+(e.fv?0:e.h/EMU)];
    let P=[p.x,p.y]; const other=DR.end==='p1'?t:s;
    if(ev.shiftKey){ const ang=Math.round(Math.atan2(P[1]-other[1],P[0]-other[0])/(Math.PI/4))*(Math.PI/4), len=Math.hypot(P[0]-other[0],P[1]-other[1]); P=[other[0]+Math.cos(ang)*len,other[1]+Math.sin(ang)*len]; }
    const S=DR.end==='p1'?P:s, E=DR.end==='p1'?t:P;
    e.x=Math.round(Math.min(S[0],E[0])*EMU); e.y=Math.round(Math.min(S[1],E[1])*EMU); e.w=Math.round(Math.abs(S[0]-E[0])*EMU); e.h=Math.round(Math.abs(S[1]-E[1])*EMU); e.fh=S[0]>E[0]; e.fv=S[1]>E[1];
    liveUpdate([e.id],true); ev.preventDefault();
  }
}
function liveUpdate(ids,rebuild){
  const sl=curSlide();
  ids.forEach(id=>{ const e=sl.els.find(x=>x.id===id); const n=$(`#stage .el[data-id="${id}"]`); if(!e||!n) return;
    if(rebuild||e.t==='shape'||e.t==='line'){ const nn=buildEl(e,{pfx:'m'}); n.replaceWith(nn); }
    else { n.style.left=(e.x/EMU)+'px'; n.style.top=(e.y/EMU)+'px'; n.style.width=(e.w/EMU)+'px'; n.style.height=(e.h/EMU)+'px'; n.style.transform=e.r?`rotate(${e.r}deg)`:''; if(e.t==='image'){ const nn=buildEl(e,{pfx:'m'}); n.replaceWith(nn);} }
  });
  const keepG=$$('#ovl .guide').map(g=>g); renderOverlay(); keepG.forEach(g=>$('#ovl').appendChild(g));
}
function showAngle(a,p){ let t=$('#ovl .angle'); if(!t){ t=document.createElement('div'); t.className='angle'; $('#ovl').appendChild(t);} t.textContent=Math.round(a)+'°'; t.style.left=(p.x+16)+'px'; t.style.top=(p.y-28)+'px'; }
function doResize(ev){
  const e=findEl(DR.id), o=DR.o, h=DR.h; const p=stagePoint(ev);
  const r=(o.r||0)*Math.PI/180, c=Math.cos(r), s=Math.sin(r);
  const dx=p.x-DR.p0.x, dy=p.y-DR.p0.y; const dxl=dx*c+dy*s, dyl=-dx*s+dy*c;
  const sx=h.includes('e')?1:h.includes('w')?-1:0, sy=h.includes('s')?1:h.includes('n')?-1:0;
  const w0=o.w/EMU, h0=o.h/EMU; let nw=w0+sx*dxl, nh=h0+sy*dyl; const min=6;
  const keep=(DR.keep||ev.shiftKey)&&sx&&sy;
  if(keep){ const k=Math.max(nw/w0,nh/h0); nw=w0*k; nh=h0*k; }
  const fromC=ev.altKey;
  nw=Math.max(min,nw); nh=Math.max(min,nh); if(!sx) nw=w0; if(!sy) nh=h0;
  // snap (faqat burilmagan elementlar)
  if(App.snap&&!o.r&&!keep&&!fromC){
    const T=snapTargets(new Set([e.id])); const L=o.x/EMU, Tp=o.y/EMU;
    if(sx>0){ const edge=L+nw; for(const t of T.xs) if(Math.abs(t-edge)<SNAP_PX/App.scale){ nw=t-L; break; } }
    if(sx<0){ const edge=L+w0-nw; for(const t of T.xs) if(Math.abs(t-edge)<SNAP_PX/App.scale){ nw=L+w0-t; break; } }
    if(sy>0){ const edge=Tp+nh; for(const t of T.ys) if(Math.abs(t-edge)<SNAP_PX/App.scale){ nh=t-Tp; break; } }
    if(sy<0){ const edge=Tp+h0-nh; for(const t of T.ys) if(Math.abs(t-edge)<SNAP_PX/App.scale){ nh=Tp+h0-t; break; } }
  }
  const cx0=o.x/EMU+w0/2, cy0=o.y/EMU+h0/2;
  let cx,cy;
  if(fromC){ cx=cx0; cy=cy0; nw=w0+2*(nw-w0); nh=h0+2*(nh-h0); nw=Math.max(min,nw); nh=Math.max(min,nh); }
  else {
    const ax=-sx*w0/2, ay=-sy*h0/2; const agx=cx0+ax*c-ay*s, agy=cy0+ax*s+ay*c;
    const ox=sx*nw/2, oy=sy*nh/2; cx=agx+ox*c-oy*s; cy=agy+ox*s+oy*c;
  }
  e.w=Math.round(nw*EMU); e.h=Math.round(nh*EMU); e.x=Math.round((cx-nw/2)*EMU); e.y=Math.round((cy-nh/2)*EMU);
  if(e.t==='table'){ const k=e.w/o.w; e.tbl.cols=o.tbl.cols.map(cw=>Math.round(cw*k)); e.tbl.rh=Math.round(o.tbl.rh*(e.h/o.h)); }
  liveUpdate([e.id],e.t==='table');
}
function doGroupResize(ev){
  const sl=curSlide(), B=DR.box, h=DR.h; const p=stagePoint(ev);
  const w0=B.x1-B.x0, h0=B.y1-B.y0; const sx=h.includes('e')?1:h.includes('w')?-1:0, sy=h.includes('s')?1:h.includes('n')?-1:0;
  let kx=1,ky=1; if(sx) kx=Math.max(0.05,(sx>0?(p.x-B.x0):(B.x1-p.x))/w0); if(sy) ky=Math.max(0.05,(sy>0?(p.y-B.y0):(B.y1-p.y))/h0);
  if(ev.shiftKey&&sx&&sy){ kx=ky=Math.max(kx,ky); }
  const ax=sx>0?B.x0:B.x1, ay=sy>0?B.y0:B.y1;
  DR.orig.forEach(o=>{ const e=sl.els.find(x=>x.id===o.id); if(!e) return;
    const cx=(o.x+o.w/2)/EMU, cy=(o.y+o.h/2)/EMU; const ncx=ax+(cx-ax)*(sx?kx:1), ncy=ay+(cy-ay)*(sy?ky:1);
    const nw=o.w*(sx?kx:1), nh=o.h*(sy?ky:1); e.w=Math.max(1000,Math.round(nw)); e.h=Math.max(1000,Math.round(nh)); e.x=Math.round(ncx*EMU-e.w/2); e.y=Math.round(ncy*EMU-e.h/2); });
  liveUpdate(DR.orig.map(o=>o.id),true);
}
function onUp(ev){
  const had=PTRS.delete(ev.pointerId);
  if(pinch&&PTRS.size<2){ pinch=null; DR=null; return; }
  if(!DR||!had) return; const d=DR; DR=null; App.dragging=false;
  const sl=curSlide();
  if(d.mode==='move'||d.mode==='tap'){
    if(d.moved){ finishMutation(d.before); }
    else {
      const id=d.id, now=Date.now();
      if(lastTap.id===id&&now-lastTap.t<380){ lastTap={id:null,t:0}; const e=findEl(id); if(e&&e.t==='table') { App.tab='jadval'; openPanel(); } else startEdit(id,false); }
      else lastTap={id,t:now};
    }
    showGuides(null); renderOverlay();
  } else if(d.mode==='marq'){
    const m=$('#ovl .marq'); if(m) m.remove();
    if(d.moved&&d.rect){ const r=d.rect; const ids=sl.els.filter(e=>!e.lock&&!e.hid).filter(e=>{const b=elBBox(e); return b.x1>=r.x0&&b.x0<=r.x1&&b.y1>=r.y0&&b.y0<=r.y1;}).map(e=>e.id);
      App.sel=d.add?[...new Set([...d.base,...ids])]:ids; }
    renderOverlay(); renderPanel();
  } else if(['resize','gresize','rotate','lineend'].includes(d.mode)){
    $('#ovl .angle')&&$('#ovl .angle').remove(); finishMutation(d.before); renderOverlay(); renderPanel();
  }
}
function finishMutation(beforeJSON){
  const sl=curSlide(); const after=JSON.stringify(sl);
  if(after===beforeJSON) { refreshAll(); return; }
  markFree(sl); const fin=JSON.stringify(sl);
  pushHist({type:'slide',id:sl.id,before:beforeJSON,after:fin,t:Date.now()}); markDirty(sl.id); refreshAll();
}
/* ---------- pinch zoom ---------- */
let pinch=null;
function startPinch(){ const a=[...PTRS.values()]; DR=null; App.dragging=false; showGuides(null); pinch={d0:Math.hypot(a[0].x-a[1].x,a[0].y-a[1].y)||1,z0:App.zoom,mx:(a[0].x+a[1].x)/2,my:(a[0].y+a[1].y)/2}; }
function doPinch(){ if(!pinch) return; const a=[...PTRS.values()]; if(a.length<2) return; const d=Math.hypot(a[0].x-a[1].x,a[0].y-a[1].y); const mx=(a[0].x+a[1].x)/2,my=(a[0].y+a[1].y)/2;
  const wrap=$('#wrap'); wrap.scrollLeft-=mx-pinch.mx; wrap.scrollTop-=my-pinch.my; pinch.mx=mx; pinch.my=my; setZoom(clamp(pinch.z0*d/pinch.d0,0.5,4)); }
function setZoom(z){ App.zoom=clamp(z,0.5,4); applyScale(); renderOverlay(); }

/* ---------- klaviatura ---------- */
function isTyping(t){ return t&&(t.isContentEditable||/^(INPUT|TEXTAREA|SELECT)$/.test(t.tagName)); }
function onKey(ev){
  if(App.editing||isTyping(ev.target)||document.querySelector('.modal')) { if(ev.key==='Escape'&&!App.editing) closePopover(); return; }
  const mod=ev.ctrlKey||ev.metaKey, k=ev.key.toLowerCase();
  if(mod&&k==='z'){ ev.preventDefault(); ev.shiftKey?redo():undo(); }
  else if(mod&&k==='y'){ ev.preventDefault(); redo(); }
  else if(mod&&k==='c'){ if(copySel()) ev.preventDefault(); }
  else if(mod&&k==='x'){ ev.preventDefault(); cutSel(); }
  else if(mod&&k==='v'){ if(App.clip){ ev.preventDefault(); pasteClip(); } }
  else if(mod&&k==='d'){ ev.preventDefault(); dupSel(); }
  else if(mod&&k==='a'){ ev.preventDefault(); selectAll(); }
  else if(mod&&k==='b'){ ev.preventDefault(); fmtRun('b'); }
  else if(mod&&k==='i'){ ev.preventDefault(); fmtRun('i'); }
  else if(mod&&k==='u'){ ev.preventDefault(); fmtRun('u'); }
  else if(mod&&k==='s'){ ev.preventDefault(); doSave(true); toast('Saqlanmoqda…'); }
  else if(k==='delete'||k==='backspace'){ if(App.sel.length){ ev.preventDefault(); deleteSel(); } }
  else if(k==='escape'){ closePopover(); if(App.sel.length) setSel([]); }
  else if(k==='enter'){ const e=selEls()[0]; if(e&&e.t==='shape'&&App.sel.length===1){ ev.preventDefault(); startEdit(e.id,false); } }
  else if(k.startsWith('arrow')){
    const st=ev.shiftKey?10:1; const d={arrowleft:[-st,0],arrowright:[st,0],arrowup:[0,-st],arrowdown:[0,st]}[k];
    if(App.sel.length){ ev.preventDefault(); nudge(d[0],d[1]); } else if(k==='arrowleft'||k==='arrowup') gotoSlide(App.cur-1); else gotoSlide(App.cur+1);
  }
}

/* ---------- slayd lentasi (tanlash va tartiblash) ---------- */
function initStrip(){
  const list=$('#stripList'); let st=null, raf=0; const last={x:0,y:0};
  const horiz=()=>getComputedStyle(list).flexDirection==='row';
  const marks=()=>$$('#stripList .thumb').forEach(c=>c.classList.remove('drop','dropR','dragging'));
  // Sudrash paytida: slayd barmoq bilan birga yuradi, tushish joyi belgilanadi, chetga yaqinlashganda ro'yxat o'zi suriladi
  const update=()=>{
    raf=0; if(!st||!st.drag) return;
    const H=horiz(), items=[...list.children];
    const d=H?(last.x-st.x)+(list.scrollLeft-st.sl):(last.y-st.y)+(list.scrollTop-st.stp);
    st.th.style.transform=(H?'translateX(':'translateY(')+d+'px) scale(1.06)';
    let tgt=st.tgt!=null?st.tgt:st.i;
    items.forEach((c,i)=>{ if(c===st.th) return; const r=c.getBoundingClientRect();
      if(H?(last.x>r.left&&last.x<r.right):(last.y>r.top&&last.y<r.bottom)) tgt=i; });
    st.tgt=tgt;
    items.forEach((c,i)=>{ c.classList.toggle('drop',i===tgt&&tgt<st.i); c.classList.toggle('dropR',i===tgt&&tgt>st.i); });
    const lr=list.getBoundingClientRect(), edge=56; let v=0;
    if(H){ if(last.x<lr.left+edge) v=-(1-(last.x-lr.left)/edge); else if(last.x>lr.right-edge) v=1-(lr.right-last.x)/edge; }
    else { if(last.y<lr.top+edge) v=-(1-(last.y-lr.top)/edge); else if(last.y>lr.bottom-edge) v=1-(lr.bottom-last.y)/edge; }
    v=Math.max(-1,Math.min(1,v));
    if(v){ if(H) list.scrollLeft+=v*16; else list.scrollTop+=v*16; raf=requestAnimationFrame(update); }
  };
  const begin=()=>{ if(!st||st.drag) return; st.drag=true; st.th.classList.add('dragging'); navigator.vibrate&&navigator.vibrate(12); update(); };
  const finish=(commit)=>{
    if(!st) return; clearTimeout(st.timer); if(raf){ cancelAnimationFrame(raf); raf=0; }
    const s=st; st=null; s.th.style.transform=''; marks();
    if(s.drag){ if(commit&&s.tgt!=null&&s.tgt!==s.i){ moveSlide(s.i,s.tgt); revealThumb(); } }
    else if(commit) gotoSlide(s.i);
  };
  list.addEventListener('pointerdown',ev=>{
    const th=ev.target.closest('.thumb'); if(!th) return;
    last.x=ev.clientX; last.y=ev.clientY;
    st={id:th.dataset.id,i:+th.dataset.i,x:ev.clientX,y:ev.clientY,sl:list.scrollLeft,stp:list.scrollTop,drag:false,timer:null,th,touch:ev.pointerType!=='mouse',tgt:null};
    if(st.touch) st.timer=setTimeout(begin,300);   // telefonda: slaydni 0,3 soniya bosib turing, keyin sudrang
  });
  window.addEventListener('pointermove',ev=>{
    if(!st) return; last.x=ev.clientX; last.y=ev.clientY; const dist=Math.hypot(ev.clientX-st.x,ev.clientY-st.y);
    if(!st.drag){
      if(!st.touch&&dist>6) begin();
      else if(st.touch&&dist>10){ clearTimeout(st.timer); st=null; return; }   // ro'yxatni oddiy surish (skroll)
    }
    if(st&&st.drag){ ev.preventDefault(); if(!raf) update(); }
  },{passive:false});
  // Sudrash boshlangach brauzer skrolli yoki sahifa surilishi barmoq harakatini "o'g'irlab" bekor qilmasligi uchun
  list.addEventListener('touchmove',ev=>{ if(st&&st.drag&&ev.cancelable) ev.preventDefault(); },{passive:false});
  window.addEventListener('pointerup',()=>finish(true));
  window.addEventListener('pointercancel',()=>finish(false));
  list.addEventListener('contextmenu',e=>e.preventDefault());
}
function revealThumb(){ setTimeout(()=>{ const t=document.querySelector('#stripList .thumb.on'); if(t&&t.scrollIntoView) t.scrollIntoView({block:'nearest',inline:'nearest',behavior:'smooth'}); },40); }

function initInteract(){
  const wrap=$('#wrap');
  wrap.addEventListener('pointerdown',onDown);
  window.addEventListener('pointermove',onMove,{passive:false});
  window.addEventListener('pointerup',onUp); window.addEventListener('pointercancel',onUp);
  wrap.addEventListener('wheel',ev=>{ if(ev.ctrlKey||ev.metaKey){ ev.preventDefault(); setZoom(App.zoom*(ev.deltaY<0?1.1:0.9)); } },{passive:false});
  wrap.addEventListener('dblclick',ev=>{ const id=elAt(ev); if(id&&!App.editing){ const e=findEl(id); if(e&&e.t==='shape') startEdit(id,false); } });
  document.addEventListener('keydown',onKey);
  document.addEventListener('paste',ev=>{
    if(App.editing||isTyping(ev.target)) return; const items=[...(ev.clipboardData?.items||[])]; const img=items.find(i=>i.type.startsWith('image/'));
    if(img){ ev.preventDefault(); uploadAndInsert(img.getAsFile()); }
  });
  initStrip();
  new ResizeObserver(()=>{ applyScale(); renderOverlay(); }).observe(wrap);
}
