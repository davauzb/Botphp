'use strict';
/* Matn: model <-> DOM, tahrirlash (contenteditable), formatlash. */
const FONT_STACK={
  'Calibri':'Calibri,Carlito,"Segoe UI",Arial,sans-serif','Arial':'Arial,Helvetica,"Liberation Sans",sans-serif','Georgia':'Georgia,"Times New Roman",serif',
  'Verdana':'Verdana,"DejaVu Sans",Geneva,sans-serif','Tahoma':'Tahoma,Verdana,Geneva,sans-serif','Trebuchet MS':'"Trebuchet MS",Tahoma,sans-serif',
  'Cambria':'Cambria,Georgia,serif','Times New Roman':'"Times New Roman",Times,"Liberation Serif",serif','Courier New':'"Courier New",Courier,monospace',
  'Segoe UI':'"Segoe UI",Calibri,Arial,sans-serif','Palatino Linotype':'"Palatino Linotype",Palatino,Georgia,serif','Impact':'Impact,"Arial Narrow",sans-serif',
  'Comic Sans MS':'"Comic Sans MS","Comic Sans",cursive','Century Gothic':'"Century Gothic",Futura,Arial,sans-serif','Garamond':'Garamond,"Times New Roman",serif'};
const cssFont=n=>FONT_STACK[n]||FONT_STACK.Calibri;
function fontFromCss(fam){
  const first=(fam||'').split(',')[0].replace(/["']/g,'').trim().toLowerCase();
  const all=(App.fonts&&App.fonts.length?App.fonts:Object.keys(FONT_STACK));
  return all.find(f=>f.toLowerCase()===first)||'Calibri';
}

function stylePara(div,p,numLabel){
  div.dataset.al=p.al; div.dataset.bu=p.bu; div.dataset.sa=p.sa; div.dataset.sb=p.sb; div.dataset.ls=p.ls;
  div.style.textAlign=p.al==='ctr'?'center':p.al==='r'?'right':p.al==='just'?'justify':'left';
  div.style.marginTop=(p.sb*PT)+'px'; div.style.marginBottom=(p.sa*PT)+'px'; div.style.lineHeight=(p.ls/100*1.2).toFixed(3);
  const sz=(p.runs[0]&&p.runs[0].sz)||18;
  if(p.bu!=='none'){ const m=sz*PT*(p.bu==='dot'?1.5:1.8); div.style.paddingLeft=m+'px'; div.style.textIndent=(-m)+'px'; div.style.setProperty('--m',m+'px'); div.dataset.b=p.bu==='dot'?'•':(numLabel||'1.'); div.classList.add('bu'); }
  else { div.style.paddingLeft=''; div.style.textIndent=''; delete div.dataset.b; div.classList.remove('bu'); }
}
function styleRun(sp,r){
  sp.style.fontFamily=cssFont(r.f); sp.style.fontSize=(r.sz*PT)+'px'; sp.style.color='#'+r.c;
  sp.style.fontWeight=r.b?'700':'400'; sp.style.fontStyle=r.i?'italic':'normal';
  const d=[]; if(r.u) d.push('underline'); if(r.s) d.push('line-through'); sp.style.textDecoration=d.join(' ')||'none';
  if(r.sp) sp.style.letterSpacing=(r.sp/100*PT)+'px';
}
function styleLayer(layer,tx){
  layer.style.padding=tx.ins.map(v=>(v/EMU)+'px').join(' ');
  layer.style.justifyContent=tx.anchor==='ctr'?'center':tx.anchor==='b'?'flex-end':'flex-start';
}
function buildTextDOM(layer,tx){
  layer.textContent=''; styleLayer(layer,tx);
  const tc=document.createElement('div'); tc.className='tc'; layer.appendChild(tc);   // tahrirlanadigan blok (flex emas)
  let n=0;
  tx.paras.forEach(p=>{
    const div=document.createElement('div'); div.className='p'; n=p.bu==='num'?n+1:0; stylePara(div,p,n+'.');
    const has=p.runs.some(r=>r.t!=='');
    p.runs.forEach(r=>{ if(r.t===''&&has) return; const sp=document.createElement('span'); styleRun(sp,r); sp.textContent=r.t; div.appendChild(sp); });
    if(!has){ div.textContent=''; const r=p.runs[0]||defRun(); const sp=document.createElement('span'); styleRun(sp,r); sp.appendChild(document.createElement('br')); div.appendChild(sp); }
    tc.appendChild(div);
  });
}

/* ---------- DOM -> model ---------- */
/* Tanlov (kursor) holatini matn belgilari bo'yicha saqlash va tiklash — DOM tugunlari almashganda kursor sakramasligi uchun */
function caretOffsets(layer){
  const s=getSelection(); if(!s.rangeCount||!layer.contains(s.anchorNode)) return null;
  const off=(node,o)=>{ const r=document.createRange(); r.selectNodeContents(layer); r.setEnd(node,o); return r.toString().length; };
  const r=s.getRangeAt(0); return {a:off(r.startContainer,r.startOffset),b:off(r.endContainer,r.endOffset)};
}
function restoreCaret(layer,c){
  if(!c) return; const w=document.createTreeWalker(layer,NodeFilter.SHOW_TEXT); let n,acc=0,sn=null,so=0,en=null,eo=0;
  while((n=w.nextNode())){ const l=n.nodeValue.length;
    if(!sn&&c.a<=acc+l){ sn=n; so=c.a-acc; } if(!en&&c.b<=acc+l){ en=n; eo=c.b-acc; break; } acc+=l; }
  if(!sn||!en) return; const r=document.createRange(); r.setStart(sn,so); r.setEnd(en,eo); const s=getSelection(); s.removeAllRanges(); s.addRange(r);
}
function normalizeFonts(layer){
  if(!layer.querySelector('font')&&!layer.querySelector('span[style*="xx-large"]')) return;
  const caret=caretOffsets(layer);
  normalizeFontsRaw(layer); restoreCaret(layer,caret);
}
function normalizeFontsRaw(layer){
  layer.querySelectorAll('font').forEach(ft=>{
    const sp=document.createElement('span');
    if(ft.hasAttribute('size')) sp.style.fontSize=(App.editSizePt?App.editSizePt*PT:ft.getAttribute('size')==='7'?24:18)+'px';
    if(ft.hasAttribute('face')) sp.style.fontFamily=cssFont(fontFromCss(ft.getAttribute('face')));
    if(ft.hasAttribute('color')) sp.style.color=ft.getAttribute('color');
    if(ft.style.cssText) sp.style.cssText+=ft.style.cssText;
    while(ft.firstChild) sp.appendChild(ft.firstChild);
    ft.replaceWith(sp);
  });
  layer.querySelectorAll('span[style*="xxx-large"],span[style*="xx-large"]').forEach(sp=>{ if(App.editSizePt) sp.style.fontSize=(App.editSizePt*PT)+'px'; });
}
function decoOf(node,layer){
  let u=false,s=false; for(let a=node.parentElement;a&&a!==layer.parentElement;a=a.parentElement){
    const l=getComputedStyle(a).textDecorationLine||''; if(l.includes('underline')) u=true; if(l.includes('line-through')) s=true; if(a===layer) break; }
  return {u,s};
}
function runOf(node,layer){
  const cs=getComputedStyle(node.parentElement); const d=decoOf(node,layer);
  const px=parseFloat(cs.fontSize)||24; let sz=Math.round(px/PT*2)/2; sz=clamp(sz,4,400);
  const ls=parseFloat(cs.letterSpacing); 
  return {sz,b:parseInt(cs.fontWeight)>=600||cs.fontWeight==='bold',i:cs.fontStyle==='italic',u:d.u,s:d.s,c:cssToHex(cs.color)||'262626',f:fontFromCss(cs.fontFamily),sp:isNaN(ls)?0:Math.round(ls/PT*100)};
}
const sameStyle=(a,b)=>a.sz===b.sz&&a.b===b.b&&a.i===b.i&&a.u===b.u&&a.s===b.s&&a.c===b.c&&a.f===b.f&&a.sp===b.sp;
function flattenLayer(layer){
  // 1) to'g'ridan-to'g'ri qatlamdagi matn/inline tugunlarni paragrafga o'rash
  let loose=null;
  [...layer.childNodes].forEach(ch=>{
    if(ch.nodeType===1&&(ch.tagName==='DIV'||ch.tagName==='P')){ loose=null; return; }
    if(ch.nodeType===3&&ch.nodeValue.trim()===''&&!loose) { ch.remove(); return; }
    if(!loose){ loose=document.createElement('div'); loose.className='p'; ch.before(loose); }
    loose.appendChild(ch);
  });
  // 2) ichma-ich bloklarni tekislash
  for(let guard=0;guard<50;guard++){
    let changed=false;
    for(const d of [...layer.children]){
      const nested=[...d.children].filter(c=>c.tagName==='DIV'||c.tagName==='P');
      if(!nested.length) continue;
      changed=true; let ref=d;
      nested.forEach(n=>{ if(!n.dataset.al) Object.assign(n.dataset,d.dataset); ref.after(n); ref=n; });
      if(d.textContent.replace(/[\u200B\uFEFF]/g,'')===''&&!d.querySelector('br')) d.remove();
    }
    if(!changed) break;
  }
}
function paraFromBlock(div,layer){
  const p={al:div.dataset.al||'l',bu:div.dataset.bu||'none',sa:+(div.dataset.sa||0),sb:+(div.dataset.sb||0),ls:+(div.dataset.ls||100),runs:[]};
  const items=[];
  (function dfs(n){ n.childNodes.forEach(ch=>{
    if(ch.nodeType===3){ const t=ch.nodeValue.replace(/[\u200B\uFEFF]/g,''); if(t!=='') items.push({t,node:ch}); }
    else if(ch.nodeType===1){ if(ch.tagName==='BR') items.push({br:true,node:ch}); else dfs(ch); }
  }); })(div);
  while(items.length&&items[items.length-1].br) items.pop();       // oxirgi <br> — bo'sh qator belgisi
  const add=(style,text)=>{ const last=p.runs[p.runs.length-1]; if(last&&sameStyle(last,style)) last.t+=text; else p.runs.push(Object.assign({t:text},style)); };
  let lastStyle=null;
  items.forEach((it,i)=>{
    if(it.br){ const st=lastStyle||(items.slice(i).find(x=>!x.br)?runOf(items.slice(i).find(x=>!x.br).node,layer):null)||runOf({parentElement:div,nodeType:3},layer); add(st,'\n'); }
    else { lastStyle=runOf(it.node,layer); add(lastStyle,it.t); }
  });
  if(!p.runs.length){ const base=div.firstElementChild?runOf({parentElement:div.querySelector('span')||div,nodeType:3},layer):runOf({parentElement:div,nodeType:3},layer); p.runs.push(Object.assign({t:''},base)); }
  return p;
}
function parseTextDOM(layer){
  normalizeFonts(layer); flattenLayer(layer);
  const paras=[...layer.children].filter(d=>d.tagName==='DIV'||d.tagName==='P').map(d=>paraFromBlock(d,layer));
  if(!paras.length) paras.push({al:'l',bu:'none',sa:0,sb:0,ls:100,runs:[defRun()]});
  return paras;
}

/* ---------- tahrirlash rejimi ---------- */
function layerOf(id){ return $(`#stage .el[data-id="${id}"] .tl .tc`); }
function startEdit(id,selectAll){
  const e=findEl(id); if(!e||e.t!=='shape'||e.lock) return;
  if(App.editing) endEdit(true);
  if(!e.tx){ commitSlide(()=>{ e.tx={anchor:'ctr',ins:[91440,45720,91440,45720],paras:[defPara([defRun({sz:20})])]}; }); }
  const sl=curSlide(); const ee=findEl(id,sl);
  let layer=layerOf(id);
  if(!layer){ renderStage(); layer=layerOf(id); if(!layer) return; }
  App.sel=[id]; App.editing={id,slideId:sl.id,before:JSON.stringify(ee.tx.paras)}; App.editSizePt=null;
  renderOverlay();
  const host=layer.closest('.el'); host.classList.add('editing'); layer.contentEditable='true'; layer.spellcheck=false; layer.setAttribute('autocapitalize','off');
  try{ document.execCommand('defaultParagraphSeparator',false,'div'); document.execCommand('styleWithCSS',false,false); }catch(x){}
  layer.focus({preventScroll:true});
  const rng=document.createRange(); rng.selectNodeContents(layer); if(!selectAll) rng.collapse(false);
  const s=getSelection(); s.removeAllRanges(); s.addRange(rng);
  layer.addEventListener('input',onEditInput); layer.addEventListener('beforeinput',onEditBefore); layer.addEventListener('paste',onEditPaste); layer.addEventListener('keydown',onEditKey);
  document.body.classList.add('is-editing'); renderPanel(); syncTextToolbar();
}
function onEditInput(ev){ normalizeFonts(ev.currentTarget); }
/* Ko'p paragrafli tanlov ustiga yozilganda Chrome belgini noto'g'ri joyga qo'yishi mumkin — avval tanlovni o'zimiz o'chiramiz */
function onEditBefore(ev){
  if(!ev.inputType||!ev.inputType.startsWith('insert')||ev.inputType==='insertParagraph'||ev.inputType==='insertLineBreak') return;
  const s=getSelection(); if(!s.rangeCount||s.isCollapsed) return;
  const layer=ev.currentTarget; const r=s.getRangeAt(0);
  const a=r.startContainer.parentElement&&r.startContainer.parentElement.closest('.p'), b=r.endContainer.parentElement&&r.endContainer.parentElement.closest('.p');
  if(a!==b||!a){ document.execCommand('delete'); }
}
function onEditPaste(ev){
  ev.preventDefault(); const t=(ev.clipboardData||window.clipboardData).getData('text/plain').replace(/\r/g,'').slice(0,20000);
  const lines=t.split('\n'); lines.forEach((ln,i)=>{ if(i>0) document.execCommand('insertParagraph'); if(ln) document.execCommand('insertText',false,ln); });
}
function onEditKey(ev){
  if(ev.key==='Escape'){ ev.preventDefault(); endEdit(true); }
  ev.stopPropagation();
}
function endEdit(commit){
  const ed=App.editing; if(!ed) return; App.editing=null;
  const layer=layerOf(ed.id); document.body.classList.remove('is-editing');
  if(layer){
    layer.removeEventListener('input',onEditInput); layer.removeEventListener('beforeinput',onEditBefore); layer.removeEventListener('paste',onEditPaste); layer.removeEventListener('keydown',onEditKey);
    const paras=commit?parseTextDOM(layer):null;
    layer.contentEditable='false'; layer.closest('.el').classList.remove('editing');
    if(paras){
      const idx=App.slides.findIndex(s=>s.id===ed.slideId); if(idx>=0) App.cur=idx;
      const empty=paras.every(p=>p.runs.every(r=>r.t.trim()===''));
      const el=findEl(ed.id);
      if(el&&empty&&el.tb&&ed.before.indexOf('"t":"')>=0&&JSON.parse(ed.before).every(p=>p.runs.every(r=>r.t.trim()===''))){
        commitSlide(sl=>{ sl.els=sl.els.filter(x=>x.id!==ed.id); sl.mode='free'; }); App.sel=[];
      } else if(JSON.stringify(paras)!==ed.before){
        commitSlide(sl=>{ const x=sl.els.find(q=>q.id===ed.id); if(x){ x.tx.paras=paras; sl.mode='free'; } });
      }
    }
  }
  getSelection().removeAllRanges(); refreshAll(); 
}

/* ---------- formatlash ---------- */
function selectedParaDivs(){
  const layer=App.editing&&layerOf(App.editing.id); if(!layer) return [];
  const sel=getSelection(); if(!sel.rangeCount) return [];
  const r=sel.getRangeAt(0); const divs=[...layer.children].filter(d=>d.tagName==='DIV'&&r.intersectsNode(d));
  return divs.length?divs:[...layer.children].filter(d=>d.tagName==='DIV').slice(0,1);
}
function paraFromDiv(d){ return {al:d.dataset.al||'l',bu:d.dataset.bu||'none',sa:+(d.dataset.sa||0),sb:+(d.dataset.sb||0),ls:+(d.dataset.ls||100),runs:[{sz:parseFloat(getComputedStyle(d).fontSize)/PT}]}; }

/** Run darajasidagi xususiyat: prop in b,i,u,s,c,sz,f,sp */
function fmtRun(prop,val){
  if(App.editing){
    const layer=layerOf(App.editing.id); if(!layer) return; layer.focus({preventScroll:true});
    try{
      if(prop==='b') document.execCommand('bold'); else if(prop==='i') document.execCommand('italic');
      else if(prop==='u') document.execCommand('underline'); else if(prop==='s') document.execCommand('strikeThrough');
      else if(prop==='c'){ document.execCommand('styleWithCSS',false,true); document.execCommand('foreColor',false,'#'+val); document.execCommand('styleWithCSS',false,false); }
      else if(prop==='sz'){ App.editSizePt=val; document.execCommand('fontSize',false,'7'); }
      else if(prop==='f'){ document.execCommand('fontName',false,val); }
      else if(prop==='sp'){ applyToSelectionSpans('letterSpacing',(val/100*PT)+'px'); }
    }catch(x){}
    normalizeFonts(layer); syncTextToolbar(); return;
  }
  const els=selEls().filter(e=>e.t==='shape'&&e.tx&&!e.lock); if(!els.length) return;
  const all=[]; els.forEach(e=>e.tx.paras.forEach(p=>p.runs.forEach(r=>all.push(r))));
  commitSlide(sl=>{
    const targets=els.map(e=>sl.els.find(x=>x.id===e.id)).filter(Boolean); const runs=[]; targets.forEach(e=>e.tx.paras.forEach(p=>p.runs.forEach(r=>runs.push(r))));
    let v=val; if(['b','i','u','s'].includes(prop)) v=!runs.every(r=>r[prop]);
    runs.forEach(r=>{ r[prop]=v; }); sl.mode='free';
  },'run'+prop);
}
function applyToSelectionSpans(cssProp,value){
  const sel=getSelection(); if(!sel.rangeCount||sel.isCollapsed) return; document.execCommand('fontSize',false,'7');
  const layer=layerOf(App.editing.id); layer.querySelectorAll('font[size="7"]').forEach(ft=>{ const sp=document.createElement('span'); sp.style[cssProp]=value; sp.style.fontSize=(App.editSizePt?App.editSizePt*PT:ft.parentElement?parseFloat(getComputedStyle(ft.parentElement).fontSize):24)+'px'; while(ft.firstChild) sp.appendChild(ft.firstChild); ft.replaceWith(sp); });
}
/** Paragraf darajasidagi xususiyat: al,bu,sa,sb,ls */
function fmtPara(prop,val){
  if(App.editing){
    const divs=selectedParaDivs(); divs.forEach(d=>{ const p=paraFromDiv(d); p[prop]=val; stylePara(d,p); }); syncTextToolbar(); return;
  }
  const els=selEls().filter(e=>e.t==='shape'&&e.tx&&!e.lock); if(!els.length) return;
  commitSlide(sl=>{ els.forEach(e0=>{ const e=sl.els.find(x=>x.id===e0.id); if(e) e.tx.paras.forEach(p=>{ p[prop]=val; }); }); sl.mode='free'; },'para'+prop);
}
/** Quti darajasidagi xususiyat: anchor, ins */
function fmtBox(prop,val){
  const els=selEls().filter(e=>e.t==='shape'&&!e.lock); if(!els.length) return;
  if(App.editing){ const e=findEl(App.editing.id); const layer=layerOf(App.editing.id); if(e&&layer){ e.tx[prop]=val; styleLayer(layer.parentElement,e.tx); } return; }
  commitSlide(sl=>{ els.forEach(e0=>{ const e=sl.els.find(x=>x.id===e0.id); if(e){ if(!e.tx) e.tx={anchor:'t',ins:[91440,45720,91440,45720],paras:[defPara([defRun()])]}; e.tx[prop]=val; } }); sl.mode='free'; },'box'+prop);
}

/** Joriy matn holati (toolbar uchun) */
function textState(){
  if(App.editing){
    const layer=layerOf(App.editing.id); const sel=getSelection();
    let node=sel.anchorNode; if(node&&node.nodeType===3) node=node.parentElement; if(!node||!layer||!layer.contains(node)) node=layer&&layer.querySelector('span')||layer;
    if(!node) return null; const cs=getComputedStyle(node); const r=runOf({parentElement:node,nodeType:3},layer);
    const pd=node.closest&&node.closest('div.p'); return Object.assign({},r,{al:pd?.dataset.al||'l',bu:pd?.dataset.bu||'none',sa:+(pd?.dataset.sa||0),sb:+(pd?.dataset.sb||0),ls:+(pd?.dataset.ls||100)});
  }
  const e=selEls().find(x=>x.t==='shape'&&x.tx); if(!e) return null; const p=e.tx.paras[0]; const r=(p.runs[0])||defRun();
  return Object.assign({},r,{al:p.al,bu:p.bu,sa:p.sa,sb:p.sb,ls:p.ls});
}
function syncTextToolbar(){ if(typeof renderPanel==='function'&&App.tab==='matn') updateTextControls(); }
document.addEventListener('selectionchange',()=>{ if(App.editing) syncTextToolbar(); });
