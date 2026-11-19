'use strict';
/* Interfeys: vidjetlar, oynalar, xususiyatlar paneli, kiritish asboblari. */
function h(tag,props,...kids){
  const e=document.createElement(tag);
  if(props) for(const k in props){ const v=props[k];
    if(k==='class') e.className=v; else if(k==='style') Object.assign(e.style,v);
    else if(k.startsWith('on')) e.addEventListener(k.slice(2),v);
    else if(v===true) e.setAttribute(k,''); else if(v!==false&&v!=null) e.setAttribute(k,v); }
  for(const c of kids.flat(3)){ if(c==null||c===false) continue; e.append(c.nodeType?c:document.createTextNode(String(c))); }
  return e;
}
const keepFocus=el=>{ el.addEventListener('pointerdown',e=>e.preventDefault()); return el; };

/* ---------- toast / modal / popover ---------- */
let toastT=null;
function toast(msg){ const t=$('#toast'); t.textContent=msg; t.classList.add('show'); clearTimeout(toastT); toastT=setTimeout(()=>t.classList.remove('show'),2200); }
function modal(o){
  const back=h('div',{class:'modal'}); const box=h('div',{class:'mbox'});
  const close=()=>back.remove();
  box.append(h('div',{class:'mtitle'},o.title||''));
  const body=h('div',{class:'mbody'}); if(typeof o.body==='string') body.textContent=o.body; else if(o.body) body.append(o.body); box.append(body);
  const bar=h('div',{class:'mbtns'});
  (o.buttons||[{t:'Yopish'}]).forEach(b=>bar.append(h('button',{class:'btn '+(b.cls||''),type:'button',onclick:()=>{ if(b.fn){ const r=b.fn(); if(r===false) return; } if(!b.keep) close(); }},b.t)));
  box.append(bar); back.append(box);
  if(!o.locked) back.addEventListener('pointerdown',e=>{ if(e.target===back) close(); });
  document.body.append(back); const first=$('input,textarea,select',box); if(first&&o.focus!==false&&window.innerWidth>820) first.focus();
  return {close,box,body};
}
let POP=null;
function closePopover(){ if(POP){ POP.remove(); POP=null; document.removeEventListener('pointerdown',popOutside,true); } }
function popOutside(e){ if(POP&&!POP.contains(e.target)&&!(POP._anchor&&POP._anchor.contains(e.target))) closePopover(); }
function popover(anchor,content,opts){
  closePopover(); const p=h('div',{class:'popover'},content); p._anchor=anchor; document.body.append(p); POP=p;
  const r=anchor.getBoundingClientRect(); const pw=p.offsetWidth, ph=p.offsetHeight;
  let left=clamp(r.left+r.width/2-pw/2,6,window.innerWidth-pw-6); let top=r.bottom+6; if(top+ph>window.innerHeight-6||(opts&&opts.up)) top=Math.max(6,r.top-ph-6);
  p.style.left=left+'px'; p.style.top=top+'px'; setTimeout(()=>document.addEventListener('pointerdown',popOutside,true),0); return p;
}

/* ---------- vidjetlar ---------- */
function seg(items,value,on){
  const d=h('div',{class:'seg'}); items.forEach(it=>{ const b=keepFocus(h('button',{type:'button',class:it.v===value?'on':'',title:it.t||'',onclick:()=>on(it.v)},it.l)); d.append(b); }); return d;
}
function field(label,...ctrl){ return h('div',{class:'fld'},h('label',null,label),h('div',{class:'ctl'},ctrl)); }
function numField(label,val,o,on){
  const inp=h('input',{type:'number',inputmode:'decimal',value:val==null?'':round2(val),min:o.min,max:o.max,step:o.step||1});
  inp.addEventListener('change',()=>{ let v=parseFloat(inp.value); if(isNaN(v)) return; v=clamp(v,o.min??-1e9,o.max??1e9); on(v); });
  inp.addEventListener('keydown',e=>{ if(e.key==='Enter') inp.blur(); });
  return field(label,inp,o.unit?h('span',{class:'unit'},o.unit):null);
}
function rangeField(label,val,min,max,step,on,fmt){
  const out=h('span',{class:'rv'},fmt?fmt(val):val);
  const r=h('input',{type:'range',min,max,step,value:val});
  r.addEventListener('input',()=>{ const v=+r.value; out.textContent=fmt?fmt(v):v; on(v); });
  return field(label,r,out);
}
function selectField(label,opts,val,on,cls){
  const s=h('select',{class:cls||''},opts.map(o=>h('option',{value:o.v,selected:o.v===val},o.l)));
  s.addEventListener('change',()=>on(s.value)); return field(label,s);
}
function toggleField(label,val,on){ const c=h('input',{type:'checkbox',checked:!!val}); c.addEventListener('change',()=>on(c.checked)); return h('label',{class:'chk'},c,h('span',null,label)); }
function section(title,...kids){ return h('div',{class:'sec'},title?h('div',{class:'sech'},title):null,kids); }

const STD=(()=>{ const hues=[0,24,45,60,100,160,190,215,260,300]; const o=['FFFFFF','F2F2F2','D9D9D9','A6A6A6','7F7F7F','595959','404040','262626','000000'];
  const rows=[o]; [[.85,.5],[.6,.6],[.45,.55],[.3,.5]].forEach(([l,s])=>{ rows.push(hues.map(hu=>hsl2hex(hu,s*100+20,l*100+(l>.5?5:0)))); }); return rows; })();
function hsl2hex(hh,s,l){ s/=100;l/=100; const k=n=>(n+hh/30)%12, a=s*Math.min(l,1-l), f=n=>l-a*Math.max(-1,Math.min(k(n)-3,Math.min(9-k(n),1))); return rgb2hex(f(0)*255,f(8)*255,f(4)*255); }
function openColorPop(anchor,value,onChange,opts){
  const d=App.design||{accent:'4472C4',title:'1F4E79'}; const wrap=h('div',{class:'cpop'});
  const themeC=[d.accent,d.title,mixHex(d.accent,'FFFFFF',.35),mixHex(d.accent,'FFFFFF',.7),mixHex(d.title,'000000',.3)];
  const sw=c=>keepFocus(h('button',{type:'button',class:'sw2'+(value===c?' on':''),style:{background:'#'+c},title:'#'+c,onclick:()=>{ onChange(c); closePopover(); }}));
  wrap.append(h('div',{class:'cpl'},'Dizayn ranglari'),h('div',{class:'cprow'},themeC.map(sw)));
  STD.forEach(r=>wrap.append(h('div',{class:'cprow'},r.map(sw))));
  const hexIn=h('input',{type:'text',maxlength:7,placeholder:'#RRGGBB',value:value?('#'+value):''});
  hexIn.addEventListener('change',()=>{ const m=/^#?([0-9a-fA-F]{6})$/.exec(hexIn.value.trim()); if(m){ onChange(m[1].toUpperCase()); closePopover(); } else toast('Rang kodi noto‘g‘ri'); });
  const nat=h('input',{type:'color',value:'#'+(value||'4472C4')}); nat.addEventListener('input',()=>onChange(nat.value.slice(1).toUpperCase(),true));
  wrap.append(h('div',{class:'cprow hex'},hexIn,nat,opts.none?h('button',{type:'button',class:'btn sm',onclick:()=>{ onChange(null); closePopover(); }},'Rangsiz'):null));
  popover(anchor,wrap,{up:opts.up});
}
function colorBtn(value,onChange,opts){
  opts=opts||{}; const b=keepFocus(h('button',{class:'cbtn'+(value?'':' none'),type:'button',title:opts.title||'Rang'},h('span',{class:'sw',style:{background:value?('#'+value):'transparent'}})));
  b.addEventListener('click',()=>openColorPop(b,value,(c,live)=>{ b.firstChild.style.background=c?('#'+c):'transparent'; onChange(c,live); },opts)); return b;
}

/* ---------- element xususiyatlarini o'zgartirish ---------- */
function setProp(fn,key){
  const els=selEls().filter(e=>!e.lock); if(!els.length) return;
  commitSlide(sl=>{ els.forEach(e0=>{ const e=sl.els.find(x=>x.id===e0.id); if(e) fn(e); }); markFree(sl); },key);
}
const cm=v=>round2(v/360000), emu=v=>Math.round(v*360000);

/* ---------- panel ---------- */
const TABS={matn:'Matn',shakl:'Shakl',joy:'Joylash',rasm:'Rasm',jadval:'Jadval',slayd:'Slayd',dizayn:'Dizayn',qatlam:'Qatlamlar'};
function availableTabs(){
  const els=selEls(); const t=[];
  if(els.length){
    if(els.some(e=>e.t==='shape')) t.push('matn');
    if(els.some(e=>e.t==='shape'||e.t==='line')) t.push('shakl');
    if(els.length===1&&els[0].t==='image') t.push('rasm');
    if(els.length===1&&els[0].t==='table') t.push('jadval');
    t.push('joy');
  }
  t.push('slayd','dizayn','qatlam'); return t;
}
function panelHasFocus(){ const a=document.activeElement; return a&&$('#panel')&&$('#panel').contains(a)&&/^(INPUT|TEXTAREA|SELECT)$/.test(a.tagName); }
let TXC=null;
function renderPanel(force){
  const P=$('#panel'); if(!P) return;
  if(!force&&panelHasFocus()) return;
  const tabs=availableTabs(); if(!tabs.includes(App.tab)) App.tab=tabs[0];
  const bar=h('div',{class:'tabs'},tabs.map(k=>keepFocus(h('button',{type:'button',class:k===App.tab?'on':'',onclick:()=>{ App.tab=k; renderPanel(true); }},TABS[k]))));
  const body=h('div',{class:'pbody'}); TXC=null;
  try{ ({matn:tabMatn,shakl:tabShakl,joy:tabJoy,rasm:tabRasm,jadval:tabJadval,slayd:tabSlayd,dizayn:tabDizayn,qatlam:tabQatlam})[App.tab](body); }catch(err){ console.error(err); body.textContent='Panel xatosi'; }
  P.textContent=''; P.append(h('div',{class:'phead'},bar,keepFocus(h('button',{class:'pclose',type:'button',onclick:()=>{ document.body.classList.remove('panel-open'); },title:'Yopish'},ic('close')))),body);
}
function openPanel(){ document.body.classList.add('panel-open'); renderPanel(true); }

/* -- Matn -- */
const FONT_LIST=()=>(App.fonts&&App.fonts.length?App.fonts:Object.keys(FONT_STACK));
function tabMatn(B){
  const st=textState(); if(!st){ B.append(h('div',{class:'hint'},'Matn qo‘shish uchun pastdagi «Matn» tugmasini bosing yoki shaklni ikki marta bosing.')); return; }
  TXC={};
  const fsel=h('select',{class:'fsel'},FONT_LIST().map(f=>h('option',{value:f,selected:f===st.f,style:{fontFamily:cssFont(f)}},f)));
  fsel.addEventListener('change',()=>{ restoreRange(); fmtRun('f',fsel.value); }); TXC.font=fsel;
  const sz=h('input',{type:'number',class:'szin',min:4,max:400,step:1,value:Math.round(st.sz*2)/2}); TXC.size=sz;
  sz.addEventListener('change',()=>{ const v=clamp(parseFloat(sz.value)||18,4,400); restoreRange(); fmtRun('sz',v); });
  const step=d=>{ const v=clamp((parseFloat(sz.value)||18)+d,4,400); sz.value=v; restoreRange(); fmtRun('sz',v); };
  const tb=(k,label,title,fn)=>{ const b=keepFocus(h('button',{type:'button',class:'tbtn',title,onclick:fn},label)); TXC[k]=b; return b; };
  B.append(section('Shrift',h('div',{class:'row'},fsel),
    h('div',{class:'row'},tb('dec',ic('minus'),'Kichiklashtirish',()=>step(-1)),sz,tb('inc',ic('plus'),'Kattalashtirish',()=>step(1)),
      tb('b',ic('bold'),'Qalin (Ctrl+B)',()=>fmtRun('b')),tb('i',ic('italic'),'Qiya (Ctrl+I)',()=>fmtRun('i')),
      tb('u',ic('under'),'Tagiga chizilgan',()=>fmtRun('u')),tb('s',ic('strike'),'Ustidan chizilgan',()=>fmtRun('s')))));
  const cc=colorBtn(st.c,(c)=>{ restoreRange(); fmtRun('c',c); },{title:'Matn rangi'}); TXC.color=cc;
  B.append(section('Rang',h('div',{class:'row'},field('Matn rangi',cc))));
  B.append(section('Tekislash',h('div',{class:'row'},seg([{v:'l',l:ic('alL'),t:'Chapga'},{v:'ctr',l:ic('alC'),t:'Markazga'},{v:'r',l:ic('alR'),t:'O‘ngga'},{v:'just',l:ic('alJ'),t:'Ikki tomonga'}],st.al,v=>{ restoreRange(); fmtPara('al',v); renderPanel(true); })),
    h('div',{class:'row'},seg([{v:'t',l:ic('vT'),t:'Tepaga'},{v:'ctr',l:ic('vM'),t:'O‘rtaga'},{v:'b',l:ic('vB'),t:'Pastga'}],selEls().find(e=>e.tx)?.tx.anchor||'t',v=>{ fmtBox('anchor',v); renderPanel(true); })),
    h('div',{class:'row'},seg([{v:'none',l:ic('minus'),t:'Belgisiz'},{v:'dot',l:ic('ul'),t:'Nuqtali ro‘yxat'},{v:'num',l:ic('ol'),t:'Raqamli ro‘yxat'}],st.bu,v=>{ restoreRange(); fmtPara('bu',v); renderPanel(true); }))));
  B.append(section('Oraliqlar',
    rangeField('Qator oralig‘i',st.ls,80,250,5,v=>{ restoreRange(); fmtPara('ls',v); },v=>v+'%'),
    rangeField('Paragraf oldidan',st.sb,0,60,1,v=>{ restoreRange(); fmtPara('sb',v); },v=>v+' pt'),
    rangeField('Paragraf keyin',st.sa,0,60,1,v=>{ restoreRange(); fmtPara('sa',v); },v=>v+' pt'),
    rangeField('Harflar oralig‘i',st.sp||0,-5,30,1,v=>{ restoreRange(); fmtRun('sp',v*100); },v=>v+' pt')));
  const e0=selEls().find(e=>e.tx); const ins=e0?e0.tx.ins:[91440,45720,91440,45720];
  B.append(section('Matn chegarasi',rangeField('Ichki bo‘shliq',Math.round(ins[0]/EMU),0,80,1,v=>fmtBox('ins',[v*EMU,Math.round(v*EMU/2),v*EMU,Math.round(v*EMU/2)]),v=>v+' px')));
  updateTextControls();
}
function updateTextControls(){
  if(!TXC) return; const st=textState(); if(!st) return;
  const on=(b,v)=>b&&b.classList.toggle('on',!!v);
  on(TXC.b,st.b); on(TXC.i,st.i); on(TXC.u,st.u); on(TXC.s,st.s);
  if(TXC.size&&document.activeElement!==TXC.size) TXC.size.value=Math.round(st.sz*2)/2;
  if(TXC.font&&document.activeElement!==TXC.font) TXC.font.value=st.f;
  if(TXC.color&&TXC.color.firstChild) TXC.color.firstChild.style.background='#'+st.c;
}
let SAVED_RANGE=null;
document.addEventListener('selectionchange',()=>{ if(!App.editing) return; const layer=layerOf(App.editing.id); const s=getSelection(); if(layer&&s.rangeCount&&layer.contains(s.anchorNode)) SAVED_RANGE=s.getRangeAt(0).cloneRange(); });
function restoreRange(){ if(!App.editing||!SAVED_RANGE) return; const layer=layerOf(App.editing.id); if(!layer) return; layer.focus({preventScroll:true}); const s=getSelection(); s.removeAllRanges(); s.addRange(SAVED_RANGE); }

/* -- Shakl -- */
function shapePreview(g,size){
  const w=size,hh=Math.round(size*0.7); const svg=svgEl('svg',{width:size,height:hh,viewBox:`0 0 ${w} ${hh}`});
  const p=svgEl('path',{d:geomPaths(g,w-4,hh-4,{})[0].d,fill:'#4472C4',transform:'translate(2 2)','fill-rule':geomPaths(g,w-4,hh-4,{})[0].rule||'nonzero'}); svg.append(p); return svg;
}
function openShapePicker(anchor,onPick){
  const g=h('div',{class:'shgrid'}); SHAPE_PICKER.forEach(k=>{ const b=h('button',{type:'button',title:GEOM_META[k].n,onclick:()=>{ closePopover(); onPick(k); }}); b.append(shapePreview(k,44)); g.append(b); });
  popover(anchor,g,{up:anchor.dataset.up==='1'});
}
function tabShakl(B){
  const els=selEls().filter(e=>e.t==='shape'||e.t==='line'); const e=els[0]; if(!e){ B.append(h('div',{class:'hint'},'Shakl yoki chiziqni tanlang.')); return; }
  const isLine=e.t==='line';
  if(!isLine){
    const gb=h('button',{type:'button',class:'btn wide',onclick:ev=>openShapePicker(ev.currentTarget,g=>setProp(x=>{ if(x.t==='shape'){ x.g=g; x.adj={}; } }))},shapePreview(e.g,30),h('span',null,GEOM_META[e.g]?GEOM_META[e.g].n:e.g));
    B.append(section('Shakl turi',gb));
    const fk=e.fill?(e.fill.g?'g':'c'):'n';
    const fillBody=h('div',null);
    if(fk==='c') fillBody.append(field('Rang',colorBtn(e.fill.c,(c,live)=>setProp(x=>{ x.fill={c:c,a:x.fill&&x.fill.a!=null?x.fill.a:100}; },'fillc')),),rangeField('Shaffoflik',100-(e.fill.a??100),0,100,1,v=>setProp(x=>{ if(x.fill&&!x.fill.g) x.fill.a=100-v; },'filla'),v=>v+'%'));
    if(fk==='g') fillBody.append(field('1-rang',colorBtn(e.fill.g[0],c=>setProp(x=>{ if(x.fill&&x.fill.g) x.fill.g[0]=c||'FFFFFF'; },'g1'))),field('2-rang',colorBtn(e.fill.g[1],c=>setProp(x=>{ if(x.fill&&x.fill.g) x.fill.g[1]=c||'000000'; },'g2'))),
      rangeField('Burchak',e.fill.ang||0,0,359,5,v=>setProp(x=>{ if(x.fill&&x.fill.g) x.fill.ang=v; },'gang'),v=>v+'°'));
    B.append(section('To‘ldirish',seg([{v:'n',l:'Yo‘q'},{v:'c',l:'Rang'},{v:'g',l:'Gradient'}],fk,v=>{ setProp(x=>{
      if(v==='n') x.fill=null; else if(v==='c') x.fill={c:(x.fill&&x.fill.c)||(x.fill&&x.fill.g&&x.fill.g[0])||App.design.accent,a:100}; else x.fill={g:[(x.fill&&x.fill.c)||App.design.accent,App.design.title],ang:90}; }); renderPanel(true); }),fillBody));
  }
  const ln=e.ln; const lnBody=h('div',null);
  if(ln){
    lnBody.append(field('Rang',colorBtn(ln.c,c=>setProp(x=>{ if(x.ln&&c) x.ln.c=c; },'lnc'))),
      rangeField('Qalinlik',Math.round(ln.w/12700*2)/2,0.5,24,0.5,v=>setProp(x=>{ if(x.ln) x.ln.w=Math.round(v*12700); },'lnw'),v=>v+' pt'),
      selectField('Chiziq turi',[{v:'solid',l:'Uzluksiz'},{v:'dash',l:'Chiziqli'},{v:'sysDot',l:'Nuqtali'},{v:'dashDot',l:'Chiziq-nuqta'},{v:'lgDash',l:'Uzun chiziq'}],ln.dash||'solid',v=>setProp(x=>{ if(x.ln) x.ln.dash=v; })));
    if(isLine){ const ends=[{v:'none',l:'Yo‘q'},{v:'triangle',l:'Uchburchak'},{v:'arrow',l:'Strelka'},{v:'stealth',l:'O‘q'},{v:'oval',l:'Doira'},{v:'diamond',l:'Romb'}];
      lnBody.append(selectField('Boshi',ends,ln.head||'none',v=>setProp(x=>{ if(x.ln) x.ln.head=v; })),selectField('Oxiri',ends,ln.tail||'none',v=>setProp(x=>{ if(x.ln) x.ln.tail=v; }))); }
  }
  if(isLine) B.append(section('Chiziq',lnBody));
  else B.append(section('Kontur',seg([{v:0,l:'Yo‘q'},{v:1,l:'Chiziq'}],ln?1:0,v=>{ setProp(x=>{ x.ln=v?{c:(x.ln&&x.ln.c)||App.design.title,w:19050,dash:'solid',head:'none',tail:'none',a:100}:null; }); renderPanel(true); }),lnBody));
  const meta=GEOM_META[e.g]; if(meta&&meta.p.length&&!isLine){
    B.append(section('Shakl sozlamasi',meta.p.map(([k,label,min,max,def])=>rangeField(label,e.adj&&e.adj[k]!=null?e.adj[k]:def,min,max,Math.max(1,Math.round((max-min)/200)),v=>setProp(x=>{ if(x.t==='shape'){ x.adj=x.adj||{}; x.adj[k]=v; } },'adj'+k),v=>Math.round(v/(max-min)*100)+'%'))));
  }
  const sh=e.sh;
  B.append(section('Soya',toggleField('Soya qo‘shish',!!sh,on=>{ setProp(x=>{ x.sh=on?{c:'000000',b:63500,d:38100,ang:45,a:35}:null; }); renderPanel(true); }),
    sh?[field('Rang',colorBtn(sh.c,c=>setProp(x=>{ if(x.sh&&c) x.sh.c=c; },'shc'))),rangeField('Xiralik',Math.round(sh.b/12700),0,40,1,v=>setProp(x=>{ if(x.sh) x.sh.b=v*12700; },'shb'),v=>v+' pt'),
      rangeField('Masofa',Math.round(sh.d/12700),0,30,1,v=>setProp(x=>{ if(x.sh) x.sh.d=v*12700; },'shd'),v=>v+' pt'),rangeField('Yo‘nalish',sh.ang,0,359,5,v=>setProp(x=>{ if(x.sh) x.sh.ang=v; },'sha'),v=>v+'°'),
      rangeField('Shaffoflik',100-sh.a,0,100,5,v=>setProp(x=>{ if(x.sh) x.sh.a=100-v; },'shaa'),v=>v+'%')]:null));
}

/* -- Joylash -- */
function tabJoy(B){
  const els=selEls(); const e=els[0]; if(!e) return;
  const single=els.length===1;
  if(single){
    const ratio=e.h/(e.w||1);
    const keep=App.keepAspect??(e.t==='image');
    B.append(section('O‘lcham va o‘rin (sm)',
      h('div',{class:'grid2'},numField('X',cm(e.x),{step:0.1},v=>setProp(x=>{x.x=emu(v);},'px')),numField('Y',cm(e.y),{step:0.1},v=>setProp(x=>{x.y=emu(v);},'py')),
        numField('Eni',cm(e.w),{step:0.1,min:0.05},v=>setProp(x=>{ const r=x.h/(x.w||1); x.w=emu(v); if(App.keepAspect??(x.t==='image')) x.h=Math.round(x.w*r); if(x.t==='table'){ const k=x.w/Math.max(1,x.tbl.cols.reduce((a,b)=>a+b,0)); x.tbl.cols=x.tbl.cols.map(c=>Math.round(c*k)); } },'pw')),
        numField('Bo‘yi',cm(e.h),{step:0.1,min:0.05},v=>setProp(x=>{ const r=x.w/(x.h||1); x.h=emu(v); if(App.keepAspect??(x.t==='image')) x.w=Math.round(x.h*r); },'ph'))),
      e.t==='line'?null:numField('Burilish',e.r,{step:1,min:0,max:359,unit:'°'},v=>setProp(x=>{x.r=v%360;},'pr')),
      toggleField('Nisbatni saqlash',keep,on=>{ App.keepAspect=on; }),
      h('div',{class:'row'},keepFocus(h('button',{type:'button',class:'btn sm',onclick:()=>setProp(x=>{x.fh=!x.fh;})},[ic('flipH'),'Gorizontal'])),keepFocus(h('button',{type:'button',class:'btn sm',onclick:()=>setProp(x=>{x.fv=!x.fv;})},[ic('flipV'),'Vertikal'])),
        keepFocus(h('button',{type:'button',class:'btn sm',onclick:()=>setProp(x=>{x.r=(((x.r||0)+90)%360);})},[ic('rotate'),'90°'])))));
  }
  const tbn=(l,t,fn)=>keepFocus(h('button',{type:'button',class:'tbtn',title:t,onclick:fn},l));
  B.append(section(single?'Sahifaga nisbatan tekislash':'Tekislash',h('div',{class:'row wrap'},),
    els.length>2?h('div',{class:'row'},tbn('↔ teng','Gorizontal teng taqsimlash',()=>distributeSel('h')),tbn('↕ teng','Vertikal teng taqsimlash',()=>distributeSel('v'))):null));
  B.append(section('Qatlam tartibi',h('div',{class:'row wrap'},tbn([ic('front'),'Eng oldinga'],'Eng oldinga',()=>zOrder('front')),tbn([ic('up'),'Oldinga'],'Oldinga',()=>zOrder('up')),tbn([ic('down'),'Orqaga'],'Orqaga',()=>zOrder('down')),tbn([ic('back'),'Eng orqaga'],'Eng orqaga',()=>zOrder('back')))));
  B.append(section('Amallar',h('div',{class:'row wrap'},tbn([ic('copy'),'Nusxa'],'Nusxa (Ctrl+D)',dupSel),tbn([ic('lock'),'Qulflash'],'Qulflash',()=>{lockSel();}),tbn([ic('eyeoff'),'Yashirish'],'Yashirish',hideSel),tbn([ic('trash'),'O‘chirish'],'O‘chirish',deleteSel))));
  if(single) B.append(section('Qulaylik',field('Tavsif (alt)',(()=>{ const i=h('input',{type:'text',maxlength:200,value:e.alt||'',placeholder:'Rasm/shakl tavsifi'}); i.addEventListener('change',()=>setProp(x=>{x.alt=i.value;})); return i; })())));
}

/* -- Rasm -- */
function tabRasm(B){
  const e=selEls()[0]; if(!e||e.t!=='image') return;
  B.append(section('Rasm',h('div',{class:'row'},h('button',{type:'button',class:'btn',onclick:()=>chooseImage(img=>setProp(x=>{ x.img=img.id; }))},'🖼 Almashtirish')),
    selectField('Shakli',[{v:'rect',l:'To‘rtburchak'},{v:'roundRect',l:'Yumaloq burchak'},{v:'ellipse',l:'Oval / doira'}],e.g,v=>{ setProp(x=>{ x.g=v; x.adj={}; }); renderPanel(true); }),
    e.g==='roundRect'?rangeField('Burchak radiusi',e.adj&&e.adj.adj!=null?e.adj.adj:16667,0,50000,500,v=>setProp(x=>{ x.adj={adj:v}; },'iadj'),v=>Math.round(v/500)+'%'):null));
  const c=e.crop||{l:0,t:0,r:0,b:0};
  B.append(section('Qirqish (%)',[['l','Chapdan'],['r','O‘ngdan'],['t','Tepadan'],['b','Pastdan']].map(([k,l])=>rangeField(l,c[k],0,80,1,v=>setProp(x=>{ x.crop=x.crop||{l:0,t:0,r:0,b:0}; x.crop[k]=v; },'crop'+k),v=>v+'%')),
    h('button',{type:'button',class:'btn sm',onclick:()=>{ setProp(x=>{x.crop={l:0,t:0,r:0,b:0};}); renderPanel(true); }},'Qirqishni bekor qilish')));
  const ln=e.ln;
  B.append(section('Ramka',seg([{v:0,l:'Yo‘q'},{v:1,l:'Ramka'}],ln?1:0,v=>{ setProp(x=>{ x.ln=v?{c:App.design.accent,w:25400,dash:'solid',head:'none',tail:'none',a:100}:null; }); renderPanel(true); }),
    ln?[field('Rang',colorBtn(ln.c,c=>setProp(x=>{ if(x.ln&&c) x.ln.c=c; },'ilc'))),rangeField('Qalinlik',Math.round(ln.w/12700*2)/2,0.5,16,0.5,v=>setProp(x=>{ if(x.ln) x.ln.w=Math.round(v*12700); },'ilw'),v=>v+' pt')]:null));
  const sh=e.sh; B.append(section('Soya',toggleField('Soya qo‘shish',!!sh,on=>{ setProp(x=>{ x.sh=on?{c:'000000',b:63500,d:38100,ang:45,a:35}:null; }); renderPanel(true); })));
}

/* -- Jadval -- */
function tabJadval(B){
  const e=selEls()[0]; if(!e||e.t!=='table') return; const t=e.tbl;
  const mut=fn=>{ setProp(x=>{ if(x.t==='table'){ fn(x.tbl,x); x.h=x.tbl.rh*x.tbl.rows.length; x.w=x.tbl.cols.reduce((a,b)=>a+b,0); } }); renderPanel(true); };
  B.append(section('Tuzilma',h('div',{class:'row wrap'},
    h('button',{type:'button',class:'btn sm',onclick:()=>mut(tb=>{ if(tb.rows.length<30) tb.rows.push(tb.cols.map(()=>({t:'',b:false,c:null,f:null,al:'l'}))); })},'+ Qator'),
    h('button',{type:'button',class:'btn sm',onclick:()=>mut(tb=>{ if(tb.rows.length>1) tb.rows.pop(); })},'− Qator'),
    h('button',{type:'button',class:'btn sm',onclick:()=>mut((tb,x)=>{ if(tb.cols.length<12){ const w=Math.round(x.w/(tb.cols.length+1)); tb.cols=tb.cols.map(c=>Math.round(c*(x.w-w)/x.w)); tb.cols.push(w); tb.rows.forEach(r=>r.push({t:'',b:false,c:null,f:null,al:'l'})); } })},'+ Ustun'),
    h('button',{type:'button',class:'btn sm',onclick:()=>mut(tb=>{ if(tb.cols.length>1){ tb.cols.pop(); tb.rows.forEach(r=>r.pop()); } })},'− Ustun')),
    toggleField('Birinchi qator — sarlavha',t.hdr,on=>mut(tb=>{tb.hdr=on;})),toggleField('Navbatma-navbat qatorlar',t.band,on=>mut(tb=>{tb.band=on;})),
    field('Sarlavha rangi',colorBtn(t.hc,c=>mut(tb=>{ if(c){ tb.hc=c; tb.hf=onColor(c); } }))),field('Chegara rangi',colorBtn(t.bc,c=>mut(tb=>{ if(c) tb.bc=c; }))),field('Matn rangi',colorBtn(t.fc,c=>mut(tb=>{ if(c) tb.fc=c; }))),
    rangeField('Matn o‘lchami',t.sz,8,40,1,v=>setProp(x=>{ if(x.t==='table') x.tbl.sz=v; },'tsz'),v=>v+' pt'),
    rangeField('Qator balandligi',cm(t.rh),0.6,4,0.1,v=>setProp(x=>{ if(x.t==='table'){ x.tbl.rh=emu(v); x.h=x.tbl.rh*x.tbl.rows.length; } },'trh'),v=>v+' sm'),
    selectField('Shrift',FONT_LIST().map(f=>({v:f,l:f})),t.font,v=>mut(tb=>{tb.font=v;}))));
  const grid=h('div',{class:'tgrid',style:{gridTemplateColumns:`repeat(${t.cols.length},minmax(70px,1fr))`}});
  t.rows.forEach((row,ri)=>row.forEach((cell,ci)=>{ const i=h('input',{type:'text',value:cell.t,maxlength:500,class:(t.hdr&&ri===0)?'hdr':''});
    i.addEventListener('input',()=>setProp(x=>{ if(x.t==='table') x.tbl.rows[ri][ci].t=i.value; },'cell'+ri+'_'+ci)); grid.append(i); }));
  B.append(section('Kataklar',h('div',{class:'tscroll'},grid)));
}

/* -- Slayd -- */
function bgElement(sl){ return sl.els.find(e=>e.lock&&e.x<=0&&e.y<=0&&e.w>=SW&&e.h>=SH&&e.t==='shape'); }
function tabSlayd(B){
  const sl=curSlide(); const bg=bgElement(sl);
  B.append(section('Slayd '+(App.cur+1)+' / '+App.slides.length,
    field('Fon rangi',colorBtn(bg&&bg.fill&&bg.fill.c?bg.fill.c:'FFFFFF',(c)=>{ if(!c) return; commitSlide(s=>{ let b=bgElement(s); if(!b){ b=blankSlide().els[0]; s.els.unshift(b); } b.fill={c,a:100}; markFree(s); },'bg'); })),
    selectField('O‘tish effekti',[{v:'none',l:'Yo‘q'},{v:'fade',l:'So‘nish'},{v:'push',l:'Surish'},{v:'wipe',l:'Artish'},{v:'cover',l:'Qoplash'},{v:'split',l:'Bo‘linish'}],sl.tr||'none',v=>commitSlide(s=>{ s.tr=v; })),
    toggleField('Elementlar birin-ketin paydo bo‘lsin (animatsiya)',!!sl.an,v=>commitSlide(s=>{ s.an=v; }))));
  { const ta=h('textarea',{rows:5,maxlength:3000,placeholder:'Bu slaydda nima deyish kerakligi (faqat PowerPoint’da «Izohlar»da ko‘rinadi, slaydda chiqmaydi)'}); ta.value=sl.notes||'';
    ta.addEventListener('input',()=>{ const s2=curSlide(); if(!s2) return; s2.notes=ta.value; markDirty(s2.id); });
    B.append(section('Nutq matni (izohlar)',ta)); }
  B.append(section('Mazmun',h('div',{class:'hint'},sl.mode==='auto'?'Bu slayd avtomatik maketda. Matnni oddiy shaklda tahrirlab, maketni almashtirishingiz mumkin.':'Bu slayd erkin rejimda (qo‘lda tahrirlangan). Oddiy shaklda tahrirlasangiz, maket qayta quriladi va qo‘l bilan qilingan o‘zgarishlar yo‘qoladi.'),
    h('button',{type:'button',class:'btn wide pri',onclick:openContentEditor},'✎ Mazmunni oddiy shaklda tahrirlash')));
  const btn=(l,fn,cls)=>h('button',{type:'button',class:'btn sm '+(cls||''),onclick:fn},l);
  B.append(section('Slayd amallari',h('div',{class:'row wrap'},btn('＋ Yangi',e=>openNewSlideMenu(e.currentTarget)),btn('⧉ Nusxa',()=>dupSlide(App.cur)),btn('🗑 O‘chirish',()=>delSlide(App.cur)),btn('◀ Oldinga',()=>moveSlide(App.cur,App.cur-1)),btn('Orqaga ▶',()=>moveSlide(App.cur,App.cur+1)))));
}
function openNewSlideMenu(anchor){
  const kinds=[['blank','▭ Bo‘sh slayd'],['matn','☰ Matn (raqamli qatorlar)'],['tarif','❝ Ta’rif'],['kartalar','▦ Kartalar'],['bosqichlar','➜ Bosqichlar'],['xronologiya','⏱ Xronologiya'],['taqqoslash','⇄ Taqqoslash'],['xulosa','✔ Xulosa'],['reja','☷ Reja']];
  const d=h('div',{class:'menu'},kinds.map(([k,l])=>h('button',{type:'button',onclick:()=>{ closePopover(); addSlide(k); }},l)));
  popover(anchor,d,{up:anchor.dataset.up==='1'});
}

/* -- Dizayn -- */
function tabDizayn(B){
  let incl=App._inclFree||false;
  const grid=h('div',{class:'dgrid'});
  App.designs.forEach((d,i)=>{ const b=h('button',{type:'button',class:'dchip'+(App.design.key===d.key?' on':''),onclick:()=>applyDesign(d.key,incl)},
    h('span',{class:'dsw',style:{background:'#'+d.accent}}),h('span',{class:'dsw2',style:{background:'#'+d.title}}),h('span',{class:'dl'},d.label.replace(/^\d+\.\s*/,''))); grid.append(b); });
  B.append(section('Dizayn',h('div',{class:'hint'},'Dizaynni almashtirsangiz, avtomatik maketdagi slaydlar yangi ko‘rinishga o‘tadi. Qo‘lda tahrirlangan slaydlar o‘zgarmaydi (xohlasangiz, pastdagi belgini yoqing).'),
    toggleField('Qo‘lda tahrirlangan slaydlarni ham yangilash',incl,on=>{ App._inclFree=on; renderPanel(true); }),grid));
}
async function applyDesign(key,includeFree){
  if(key===App.design.key&&!includeFree) return;
  const targets=App.slides.map((s,i)=>({s,i})).filter(x=>includeFree||x.s.mode==='auto');
  if(includeFree&&!confirm('Qo‘lda tahrirlangan slaydlardagi o‘zgarishlar yo‘qoladi. Davom etasizmi?')) return;
  const d=App.designs.find(x=>x.key===key); toast('Dizayn qo‘llanmoqda…');
  const results=[];
  for(const t of targets){ const r=await api('regen',{sem:t.s.sem,n:t.i+1,total:App.slides.length,design:key}); if(!r.ok){ toast('Dizayn qo‘llanmadi'); return; } results.push([t.s.id,r]); }
  commitStruct(()=>{ App.design=Object.assign({},App.design,{key,accent:d.accent,title:d.title,skin:d.skin}); App.metaDirty=true;
    results.forEach(([id,r])=>{ const s=App.slides.find(x=>x.id===id); if(s){ s.sem=r.sem; s.els=r.els; s.mode='auto'; App.dirty.add(id); } }); });
  App.metaDirty=true; scheduleSave(); renderPanel(true); toast('Dizayn almashtirildi');
}

/* -- Qatlamlar -- */
function tabQatlam(B){
  const sl=curSlide(); const list=h('div',{class:'layers'});
  [...sl.els].reverse().forEach(e=>{
    const sel=App.sel.includes(e.id);
    const icon={shape:e.tx&&e.tx.paras.some(p=>p.runs.some(r=>r.t.trim()))?'T':'▭',image:'🖼',line:'╱',table:'▦'}[e.t];
    const row=h('div',{class:'lrow'+(sel?' on':'')+(e.hid?' hid':'')},
      h('span',{class:'lic'},icon),h('span',{class:'lnm',onclick:()=>{ if(!e.hid) setSel([e.id]); }},layerName(e)),
      keepFocus(h('button',{type:'button',title:'Yashirish',onclick:()=>commitSlide(s=>{ const x=s.els.find(q=>q.id===e.id); x.hid=!x.hid; markFree(s); })},ic(e.hid?'eyeoff':'eye'))),
      keepFocus(h('button',{type:'button',title:'Qulflash',onclick:()=>commitSlide(s=>{ const x=s.els.find(q=>q.id===e.id); x.lock=!x.lock; markFree(s); })},ic(e.lock?'lock':'unlock'))),
      keepFocus(h('button',{type:'button',title:'Yuqoriga',onclick:()=>{ App.sel=[e.id]; zOrder('up'); }},ic('up'))),
      keepFocus(h('button',{type:'button',title:'Pastga',onclick:()=>{ App.sel=[e.id]; zOrder('down'); }},ic('down'))));
    list.append(row);
  });
  B.append(section('Elementlar ('+sl.els.length+')',list));
}
function layerName(e){
  if(e.t==='shape'&&e.tx){ const t=e.tx.paras.map(p=>p.runs.map(r=>r.t).join('')).join(' ').trim(); if(t) return t.slice(0,32); }
  return e.n||({shape:'Shakl',image:'Rasm',line:'Chiziq',table:'Jadval'}[e.t]);
}

/* ---------- mazmunni oddiy shaklda tahrirlash ---------- */
const KIND_LABEL={matn:'Matn',tarif:'Ta’rif',kartalar:'Kartalar',bosqichlar:'Bosqichlar',xronologiya:'Xronologiya',taqqoslash:'Taqqoslash',xulosa:'Xulosa',reja:'Reja',title:'Muqova',thanks:'Yakuniy slayd'};
function openContentEditor(){
  const sl=curSlide(); const sem=clone(sl.sem||{kind:'matn',title:'',lead:'',points:[],items:[],bullets:[]});
  sem.points=sem.points||[]; sem.items=sem.items||[]; sem.bullets=sem.bullets||[];
  const box=h('div',{class:'cform'}); const aiBox=h('div',{class:'aibox'}); let aiMode='rewrite', aiNote='';
  const refresh=()=>{ box.textContent=''; build(); buildAi(); box.append(aiBox); };
  function build(){
    box.append(selectField('Maket turi',Object.keys(KIND_LABEL).map(k=>({v:k,l:KIND_LABEL[k]})),sem.kind,v=>{ sem.kind=v; refresh(); }));
    const inp=(label,key,multi)=>{ const i=h(multi?'textarea':'input',multi?{rows:multi,maxlength:700}:{type:'text',maxlength:200}); i.value=sem[key]||''; i.addEventListener('input',()=>{ sem[key]=i.value; }); return field(label,i); };
    box.append(inp('Sarlavha','title'));
    const k=sem.kind;
    if(!['reja','title','thanks'].includes(k)) box.append(inp(k==='tarif'?'Ta’rif matni':'Izoh (kirish jumla)','lead',3));
    if(k==='taqqoslash') box.append(h('div',{class:'grid2'},inp('Chap ustun nomi','left_title'),inp('O‘ng ustun nomi','right_title')));
    if(k==='reja'){ const t=h('textarea',{rows:6,maxlength:1200}); t.value=sem.items.join('\n'); t.addEventListener('input',()=>{ sem.items=t.value.split('\n').map(s=>s.trim()).filter(Boolean).slice(0,10); }); box.append(field('Reja bandlari (har qatorda bittadan)',t)); }
    if(k==='title'||k==='thanks'){ const t=h('textarea',{rows:3,maxlength:600}); t.value=sem.bullets.join('\n'); t.addEventListener('input',()=>{ sem.bullets=t.value.split('\n').map(s=>s.trim()).filter(Boolean).slice(0,4); }); box.append(field('Qo‘shimcha qatorlar',t)); }
    if(!['reja','title','thanks'].includes(k)){
      const list=h('div',{class:'plist'});
      sem.points.forEach((p,i)=>{
        const hd=h('input',{type:'text',maxlength:120,placeholder:'Sarlavha',value:p.head||''}); hd.addEventListener('input',()=>{p.head=hd.value;});
        const tx=h('textarea',{rows:2,maxlength:600,placeholder:'Tushuntirish'}); tx.value=p.text||''; tx.addEventListener('input',()=>{p.text=tx.value;});
        const row=h('div',{class:'prow'},h('div',{class:'pn'},i+1),h('div',{class:'pf'},hd,tx,
          k==='taqqoslash'?seg([{v:'chap',l:'Chap'},{v:'ong',l:'O‘ng'}],p.side==='left'?'chap':p.side==='right'?'ong':p.side||'chap',v=>{ p.side=v; refresh(); }):null),
          h('div',{class:'pb'},h('button',{type:'button',class:'tbtn',onclick:()=>{ if(i>0){ [sem.points[i-1],sem.points[i]]=[sem.points[i],sem.points[i-1]]; refresh(); } }},'▲'),
            h('button',{type:'button',class:'tbtn',onclick:()=>{ if(i<sem.points.length-1){ [sem.points[i+1],sem.points[i]]=[sem.points[i],sem.points[i+1]]; refresh(); } }},'▼'),
            h('button',{type:'button',class:'tbtn',onclick:()=>{ sem.points.splice(i,1); refresh(); }},'🗑')));
        list.append(row); });
      box.append(h('div',{class:'sech'},'Punktlar'),list,h('button',{type:'button',class:'btn sm',onclick:()=>{ if(sem.points.length<8){ sem.points.push({head:'',text:'',side:k==='taqqoslash'?'chap':''}); refresh(); } }},'＋ Punkt qo‘shish'));
    }
    if(['matn','tarif'].includes(k)){
      const cur=sem.image?h('span',{class:'hint'},'Rasm tanlangan'):h('span',{class:'hint'},'Rasm yo‘q');
      box.append(h('div',{class:'sech'},'Rasm'),h('div',{class:'row'},cur,h('button',{type:'button',class:'btn sm',onclick:()=>chooseImage(img=>{ sem.image=img.id; refresh(); })},'Tanlash'),sem.image?h('button',{type:'button',class:'btn sm',onclick:()=>{ sem.image=null; refresh(); }},'Olib tashlash'):null));
    }
  }
  build();
  /* ✨ AI bilan qayta yozish: natija shu oynadagi formada ko'rinadi — yoqmasa «Bekor qilish», yoqsa «Qo'llash» */
  const aiOk=['matn','tarif','kartalar','bosqichlar','xronologiya','taqqoslash','xulosa'];
  function buildAi(){
    aiBox.textContent=''; if(!aiOk.includes(sem.kind)||!sem.points.length) return;
    const MODES=[{v:'rewrite',l:'Qayta yozish'},{v:'shorter',l:'Qisqartirish'},{v:'longer',l:'Kengaytirish'},{v:'simpler',l:'Soddalashtirish'},{v:'formal',l:'Rasmiy uslub'},{v:'fix',l:'Xatolarni to‘g‘rilash'}];
    const ni=h('input',{type:'text',maxlength:200,placeholder:'Ixtiyoriy ko‘rsatma (masalan: «misollar qo‘shing»)'}); ni.value=aiNote; ni.addEventListener('input',()=>{ aiNote=ni.value; });
    const btn=h('button',{type:'button',class:'btn sm pri',onclick:async()=>{
      if(btn.disabled) return; btn.disabled=true; const old=btn.textContent; btn.textContent='AI yozmoqda…';
      try{
        const r=await api('airewrite',{sem,mode:aiMode,note:aiNote});
        if(r.ok){ Object.assign(sem,r.sem); refresh(); toast('Tayyor — ko‘rib chiqing, so‘ng «Qo‘llash» bosing'); }
        else toast({rate:'AI so‘rovlari limiti tugadi — keyinroq urining',busy:'AI hozir band — birozdan keyin urining',bad_ai:'AI javobi yaroqsiz — qayta urining',kind:'Bu slayd turi uchun AI mavjud emas',off:'AI yoqilmagan'}[r.error]||'AI javob bermadi — qayta urining');
      } finally { btn.disabled=false; btn.textContent=old; }
    }},'✨ AI bilan o‘zgartirish');
    aiBox.append(h('div',{class:'sech'},'✨ AI yordamchi'),seg(MODES,aiMode,v=>{ aiMode=v; buildAi(); }),field('Ko‘rsatma',ni),btn,h('div',{class:'hint'},'Natija avval shu oynada ko‘rinadi; «Qo‘llash» bosmaguncha slayd o‘zgarmaydi.'));
  }
  buildAi();
  box.append(aiBox);
  modal({title:'Mazmunni tahrirlash',body:box,buttons:[{t:'Bekor qilish'},{t:'Qo‘llash',cls:'pri',keep:true,fn:async function(){
    const r=await api('regen',{sem,n:App.cur+1,total:App.slides.length,design:App.design.key});
    if(!r.ok){ toast('Qo‘llab bo‘lmadi'); return false; }
    commitSlide(s=>{ s.sem=r.sem; s.els=r.els; s.mode='auto'; }); App.sel=[]; renderOverlay(); renderPanel(true); document.querySelector('.modal')?.remove(); toast('Yangilandi'); }}]});
}

/* ---------- rasm tanlash / yuklash ---------- */
function pickFile(cb){ const i=h('input',{type:'file',accept:'image/png,image/jpeg,image/gif,image/webp',style:{display:'none'}}); i.addEventListener('change',()=>{ if(i.files[0]) cb(i.files[0]); i.remove(); }); document.body.append(i); i.click(); }
async function uploadImage(file){
  if(!file) return null; if(file.size>8*1024*1024){ toast('Rasm 8 MB dan oshmasligi kerak'); return null; }
  const fd=new FormData(); fd.append('file',file,file.name||'rasm'); toast('Rasm yuklanmoqda…');
  const r=await api('upload',fd);
  if(!r.ok){ toast({bad_type:'Faqat JPG, PNG, GIF yoki WebP',quota:'Rasmlar soni chegaraga yetdi',too_big:'Rasm juda katta',bad_size:'Rasm o‘lchami noto‘g‘ri'}[r.error]||'Yuklab bo‘lmadi'); return null; }
  App.images[r.image.id]=r.image; return r.image;
}
async function uploadAndInsert(file){ const img=await uploadImage(file); if(img) addElement(mkImage(img)); }
function chooseImage(cb){
  const grid=h('div',{class:'igrid'}); const ids=Object.keys(App.images);
  const m=modal({title:'Rasm tanlang',body:h('div',null,grid),buttons:[{t:'Yopish'},{t:'⬆ Yangi rasm yuklash',cls:'pri',keep:true,fn:()=>{ pickFile(async f=>{ const img=await uploadImage(f); if(img){ m.close(); cb(img); } }); return false; }}]});
  if(!ids.length) grid.append(h('div',{class:'hint'},'Hali rasm yo‘q — yangi rasm yuklang.'));
  ids.forEach(id=>{ const im=h('img',{alt:'',draggable:false}); const b=h('button',{type:'button',class:'ithumb',onclick:()=>{ m.close(); cb(App.images[id]); }},im); grid.append(b); imgURL(id).then(u=>{ if(u) im.src=u; }); });
}

/* ---------- kiritish asboblari ---------- */
function insertText(){ addElement(mkText('Matn yozing',{sz:28}),true); }
function insertShape(anchor){ openShapePicker(anchor,g=>{ const s=mkShape(g); addElement(s); }); }
function insertLine(anchor){ const d=h('div',{class:'menu'},h('button',{type:'button',onclick:()=>{ closePopover(); addElement(mkLine(false)); }},'╱ Chiziq'),h('button',{type:'button',onclick:()=>{ closePopover(); addElement(mkLine(true)); }},'➝ Strelka'));
  popover(anchor,d,{up:true}); }
function insertImage(){ chooseImage(img=>addElement(mkImage(img))); }
function insertTable(){
  let r=4,c=3; const rr=numField('Qatorlar',r,{min:1,max:30},v=>{r=v;}), cc=numField('Ustunlar',c,{min:1,max:12},v=>{c=v;});
  modal({title:'Jadval qo‘shish',body:h('div',null,rr,cc),buttons:[{t:'Bekor qilish'},{t:'Qo‘shish',cls:'pri',fn:()=>{ addElement(mkTable(clamp(r,1,30),clamp(c,1,12))); }}]});
}

/* ---------- qidirish va almashtirish ---------- */
function openFindReplace(){
  let f='',rp='',cs=false; const info=h('div',{class:'hint'},'');
  const fi=h('input',{type:'text',placeholder:'Nimani',maxlength:200}), ri=h('input',{type:'text',placeholder:'Nimaga',maxlength:200});
  fi.addEventListener('input',()=>f=fi.value); ri.addEventListener('input',()=>rp=ri.value);
  const esc=s=>s.replace(/[.*+?^${}()|[\]\\]/g,'\\$&');
  const body=h('div',null,field('Qidirish',fi),field('Almashtirish',ri),toggleField('Katta-kichik harfni farqlash',false,on=>cs=on),info);
  modal({title:'Qidirish va almashtirish',body,buttons:[{t:'Yopish'},{t:'Hammasini almashtirish',cls:'pri',keep:true,fn:()=>{
    if(!f){ info.textContent='Qidiriladigan matnni kiriting'; return false; }
    const re=new RegExp(esc(f),cs?'g':'gi'); let n=0; const sub=(s)=>typeof s==='string'?s.replace(re,()=>{n++;return rp;}):s;
    const before=App.slides.map(s=>JSON.stringify(s));
    commitStruct(()=>{ App.slides.forEach(s=>{ s.els.forEach(e=>{ if(e.tx) e.tx.paras.forEach(p=>p.runs.forEach(r=>{ r.t=sub(r.t); })); if(e.tbl) e.tbl.rows.forEach(rw=>rw.forEach(c=>{ c.t=sub(c.t); })); });
      const m=s.sem; if(m){ m.title=sub(m.title); m.lead=sub(m.lead); m.left_title=sub(m.left_title); m.right_title=sub(m.right_title); (m.points||[]).forEach(p=>{p.head=sub(p.head);p.text=sub(p.text);}); m.items=(m.items||[]).map(sub); m.bullets=(m.bullets||[]).map(sub); }
      if(JSON.stringify(s)!==before[App.slides.indexOf(s)]) App.dirty.add(s.id); }); });
    info.textContent=n?`${n} ta joy almashtirildi`:'Hech narsa topilmadi'; return false; }}]});
}

/* ---------- namoyish ---------- */
function startPresent(from){
  let i=from??App.cur; const ov=h('div',{class:'present'}); const stage=h('div',{class:'pstage'}); ov.append(stage,h('div',{class:'pnav'},h('button',{type:'button',onclick:()=>go(i-1)},'◀'),h('span',{class:'pcnt'}),h('button',{type:'button',onclick:()=>go(i+1)},'▶'),h('button',{type:'button',onclick:close},'✕')));
  function draw(){ stage.textContent=''; const dom=buildSlideDOM(App.slides[i],{pfx:'p'}); const sc=Math.min(ov.clientWidth/SWpx,(ov.clientHeight)/SHpx); dom.style.transform=`scale(${sc})`; dom.style.transformOrigin='0 0'; stage.style.width=SWpx*sc+'px'; stage.style.height=SHpx*sc+'px'; stage.append(dom); $('.pcnt',ov).textContent=(i+1)+' / '+App.slides.length; }
  function go(n){ i=clamp(n,0,App.slides.length-1); draw(); }
  function close(){ document.removeEventListener('keydown',key,true); window.removeEventListener('resize',draw); ov.remove(); }
  function key(e){ if(e.key==='Escape') close(); else if(['ArrowRight','ArrowDown',' ','PageDown'].includes(e.key)) go(i+1); else if(['ArrowLeft','ArrowUp','PageUp'].includes(e.key)) go(i-1); else return; e.preventDefault(); e.stopPropagation(); }
  stage.addEventListener('click',e=>{ const r=stage.getBoundingClientRect(); go(e.clientX>r.left+r.width/3?i+1:i-1); });
  document.addEventListener('keydown',key,true); window.addEventListener('resize',draw); document.body.append(ov); draw();
}
