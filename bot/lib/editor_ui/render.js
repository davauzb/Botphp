'use strict';
/* Slaydni DOM/SVG ga chizish, miniaturalar, tanlov qatlami (overlay). */
const NS='http://www.w3.org/2000/svg';
function svgEl(tag,attrs){ const e=document.createElementNS(NS,tag); for(const k in attrs) e.setAttribute(k,attrs[k]); return e; }
const IMGCACHE=new Map();
function imgURL(id){
  if(!IMGCACHE.has(id)){
    IMGCACHE.set(id,fetch('api.php?a=img&id='+encodeURIComponent(id),{headers:{authorization:'Bearer '+App.sess}})
      .then(r=>{ if(!r.ok) throw 0; return r.blob(); }).then(b=>URL.createObjectURL(b)).catch(()=>{IMGCACHE.delete(id);return null;}));
  }
  return IMGCACHE.get(id);
}
function rgbaC(hex,a){ const c=hex2rgb(hex); return `rgba(${c[0]},${c[1]},${c[2]},${(a==null?100:a)/100})`; }
const DASH={solid:'',dash:'8 4',sysDot:'2 3',dashDot:'8 3 2 3',lgDash:'14 5'};
function shadowCSS(sh){
  if(!sh) return '';
  const ang=sh.ang*Math.PI/180, d=sh.d/EMU; return `drop-shadow(${f(Math.cos(ang)*d)}px ${f(Math.sin(ang)*d)}px ${f(sh.b/EMU/2)}px ${rgbaC(sh.c,sh.a)})`;
}

function markerDef(id,type,color){
  const m=svgEl('marker',{id,viewBox:'0 0 10 10',refX:'5',refY:'5',markerWidth:'4',markerHeight:'4',orient:'auto-start-reverse',markerUnits:'strokeWidth'});
  let p;
  if(type==='oval') p=svgEl('circle',{cx:5,cy:5,r:4});
  else if(type==='diamond') p=svgEl('path',{d:'M5 0L10 5L5 10L0 5Z'});
  else if(type==='stealth') p=svgEl('path',{d:'M0 0L10 5L0 10L3 5Z'});
  else if(type==='arrow') p=svgEl('path',{d:'M0 0L10 5L0 10',fill:'none','stroke-width':'1.6',stroke:'#'+color});
  else p=svgEl('path',{d:'M0 0L10 5L0 10Z'});
  if(type!=='arrow') p.setAttribute('fill','#'+color);
  m.appendChild(p); return m;
}

function buildSvg(e,w,h,ctx){
  const svg=svgEl('svg',{width:w,height:h,viewBox:`0 0 ${w} ${h}`}); svg.classList.add('gfx'); svg.style.overflow='visible';
  const defs=svgEl('defs',{}); svg.appendChild(defs);
  const pfx=(ctx.pfx||'')+e.id;
  let fill='rgba(0,0,0,0)';
  if(e.t!=='line'&&e.fill){
    if(e.fill.g){ const lg=svgEl('linearGradient',{id:pfx+'g',gradientTransform:`rotate(${(e.fill.ang||90)-90} .5 .5)`,x1:'0',y1:'0',x2:'1',y2:'0'});
      // OOXML ang: 0 = chapdan o'ngga, 90 = tepadan pastga
      lg.setAttribute('x1','0');lg.setAttribute('y1','0');lg.setAttribute('x2','1');lg.setAttribute('y2','0');
      lg.setAttribute('gradientTransform',`rotate(${e.fill.ang||0} .5 .5)`);
      const ga=e.fill.ga||[100,100]; const s1=svgEl('stop',{offset:'0','stop-color':'#'+e.fill.g[0],'stop-opacity':String(ga[0]/100)}), s2=svgEl('stop',{offset:'1','stop-color':'#'+e.fill.g[1],'stop-opacity':String(ga[1]/100)}); lg.append(s1,s2); defs.appendChild(lg); fill=`url(#${pfx}g)`; }
    else fill='#'+e.fill.c;
  }
  const paths=geomPaths(e.g,w,h,e.adj);
  const strokeW=e.ln?Math.max(0,e.ln.w/EMU):0;
  for(const p of paths){
    const pa=svgEl('path',{d:p.d});
    if(p.kind==='dark'){ pa.setAttribute('fill','rgba(0,0,0,.22)'); pa.setAttribute('stroke','none'); svg.appendChild(pa); continue; }
    if(e.t==='line'){ pa.setAttribute('fill','none'); } else { pa.setAttribute('fill',fill); if(e.fill&&e.fill.a!=null&&e.fill.a<100&&!e.fill.g) pa.setAttribute('fill-opacity',e.fill.a/100); }
    if(p.rule) pa.setAttribute('fill-rule',p.rule);
    if(e.ln&&strokeW>0){
      pa.setAttribute('stroke','#'+e.ln.c); pa.setAttribute('stroke-width',strokeW); pa.setAttribute('stroke-linejoin','round');
      if(e.ln.a<100) pa.setAttribute('stroke-opacity',e.ln.a/100);
      if(DASH[e.ln.dash]) pa.setAttribute('stroke-dasharray',DASH[e.ln.dash].split(' ').map(n=>n*Math.max(1,strokeW/2)).join(' '));
      if(e.t==='line'){
        if(e.ln.head&&e.ln.head!=='none'){ defs.appendChild(markerDef(pfx+'h',e.ln.head,e.ln.c)); pa.setAttribute('marker-start',`url(#${pfx}h)`); }
        if(e.ln.tail&&e.ln.tail!=='none'){ defs.appendChild(markerDef(pfx+'t',e.ln.tail,e.ln.c)); pa.setAttribute('marker-end',`url(#${pfx}t)`); }
      }
    } else pa.setAttribute('stroke','none');
    svg.appendChild(pa);
  }
  if(e.t==='line'&&!ctx.thumb){ const hit=svgEl('path',{d:paths[0].d,fill:'none',stroke:'rgba(0,0,0,0)','stroke-width':Math.max(18,strokeW+10)}); hit.style.pointerEvents='stroke'; svg.appendChild(hit); }
  const tr=[]; if(e.fh) tr.push('scaleX(-1)'); if(e.fv) tr.push('scaleY(-1)'); if(tr.length) svg.style.transform=tr.join(' ');
  const fl=shadowCSS(e.sh); if(fl) svg.style.filter=fl;
  return svg;
}

function buildImage(e,w,h,ctx){
  const wrap=document.createElement('div'); wrap.className='gfx imgw';
  const inner=document.createElement('div'); inner.className='imgin';
  if(e.g==='ellipse') inner.style.borderRadius='50%';
  else if(e.g==='roundRect') inner.style.borderRadius=(Math.min(w,h)*clamp(ga(e.adj,'roundRect','adj'),0,50000)/100000)+'px';
  const bw=e.ln?e.ln.w/EMU:0;
  if(e.ln&&bw>0) inner.style.boxShadow=`0 0 0 ${bw/2}px ${rgbaC(e.ln.c,e.ln.a)}, inset 0 0 0 ${bw/2}px ${rgbaC(e.ln.c,e.ln.a)}`;
  const im=document.createElement('img'); im.draggable=false; im.alt=e.alt||'';
  const c=e.crop||{l:0,t:0,r:0,b:0}; const cw=100-c.l-c.r, ch=100-c.t-c.b;
  im.style.width=(10000/cw)+'%'; im.style.height=(10000/ch)+'%'; im.style.left=(-c.l*100/cw)+'%'; im.style.top=(-c.t*100/ch)+'%';
  inner.appendChild(im); wrap.appendChild(inner);
  const tr=[]; if(e.fh) tr.push('scaleX(-1)'); if(e.fv) tr.push('scaleY(-1)'); if(tr.length) wrap.style.transform=tr.join(' ');
  const fl=shadowCSS(e.sh); if(fl) wrap.style.filter=fl;
  imgURL(e.img).then(u=>{ if(u) im.src=u; else wrap.classList.add('broken'); });
  return wrap;
}

function buildTable(e){
  const t=e.tbl; const tb=document.createElement('table'); tb.className='gfx tbl'; tb.style.fontFamily=cssFont(t.font);
  const cg=document.createElement('colgroup'); t.cols.forEach(w=>{const c=document.createElement('col'); c.style.width=(w/EMU)+'px'; cg.appendChild(c);}); tb.appendChild(cg);
  t.rows.forEach((row,ri)=>{
    const tr=document.createElement('tr'); tr.style.height=(t.rh/EMU)+'px'; const hdr=t.hdr&&ri===0;
    row.forEach(cell=>{
      const td=document.createElement('td'); td.textContent=cell.t; 
      const bg=hdr?t.hc:(cell.f||((t.band&&ri%2===0)?'F2F2F2':null)); if(bg) td.style.background='#'+bg;
      td.style.color='#'+(hdr?t.hf:(cell.c||t.fc)); td.style.fontSize=(t.sz*PT)+'px'; td.style.fontWeight=(hdr||cell.b)?'700':'400';
      td.style.textAlign=cell.al==='ctr'?'center':cell.al==='r'?'right':'left'; td.style.border='1px solid #'+t.bc; tr.appendChild(td);
    }); tb.appendChild(tr);
  });
  return tb;
}

function buildEl(e,ctx){
  const d=document.createElement('div'); d.className='el'+(e.lock?' lk':'')+(e.t==='line'?' ln':''); d.dataset.id=e.id;
  const w=e.w/EMU,h=e.h/EMU;
  d.style.left=(e.x/EMU)+'px'; d.style.top=(e.y/EMU)+'px'; d.style.width=w+'px'; d.style.height=h+'px';
  if(e.r) d.style.transform=`rotate(${e.r}deg)`;
  if(e.hid) d.style.display='none';
  if(e.t==='image') d.appendChild(buildImage(e,w,h,ctx));
  else if(e.t==='table') d.appendChild(buildTable(e));
  else { d.appendChild(buildSvg(e,w,h,ctx)); if(e.t==='shape'&&e.tx){ const tl=document.createElement('div'); tl.className='tl'; buildTextDOM(tl,e.tx); d.appendChild(tl);} }
  return d;
}

function buildSlideDOM(slide,ctx){
  ctx=ctx||{}; const s=document.createElement('div'); s.className='slide'; s.style.width=SWpx+'px'; s.style.height=SHpx+'px';
  for(const e of slide.els) s.appendChild(buildEl(e,ctx));
  return s;
}

/* ---------- asosiy sahna ---------- */
function renderStage(){
  const st=$('#stage'); const sl=curSlide(); st.textContent='';
  if(!sl) return;
  const dom=buildSlideDOM(sl,{pfx:'m'});
  while(dom.firstChild) st.appendChild(dom.firstChild);
  st.classList.toggle('grid',App.grid);
  applyScale();
}
function applyScale(){
  const wrap=$('#wrap'); if(!wrap) return;
  const pad=window.innerWidth<820?12:28;
  const aw=wrap.clientWidth-pad*2, ah=wrap.clientHeight-pad*2;
  App.fitScale=Math.max(0.1,Math.min(aw/SWpx,ah/SHpx));
  const s=App.fitScale*App.zoom; App.scale=s;
  const box=$('#stageBox'); box.style.width=(SWpx*s)+'px'; box.style.height=(SHpx*s)+'px';
  const m=Math.max(pad,0); box.style.margin=(App.zoom>1?m:Math.max(m,(wrap.clientHeight-SHpx*s)/2))+'px auto';
  const t=`scale(${s})`; $('#stage').style.transform=t; $('#ovl').style.transform=t;
  document.documentElement.style.setProperty('--inv',String(1/s));
  const z=$('#zoomLbl'); if(z) z.textContent=Math.round(s*100)+'%';
}
function stagePoint(ev){ const r=$('#stage').getBoundingClientRect(); return {x:(ev.clientX-r.left)/App.scale,y:(ev.clientY-r.top)/App.scale}; }

/* ---------- miniaturalar ---------- */
let thumbTimer=null; const thumbDirty=new Set();
function renderStrip(full){
  const list=$('#stripList'); const ids=App.slides.map(s=>s.id);
  if(full||list.children.length!==ids.length||[...list.children].some((c,i)=>c.dataset.id!==ids[i])){
    list.textContent='';
    App.slides.forEach((sl,i)=>{
      const it=document.createElement('div'); it.className='thumb'; it.dataset.id=sl.id; it.dataset.i=i;
      const box=document.createElement('div'); box.className='tbox'; const num=document.createElement('span'); num.className='tnum'; num.textContent=i+1;
      it.append(box,num); list.appendChild(it); fillThumb(it,sl);
    });
  }
  [...list.children].forEach((c,i)=>{ c.classList.toggle('on',i===App.cur); c.dataset.i=i; c.querySelector('.tnum').textContent=i+1; });
  const on=list.children[App.cur]; if(on&&on.scrollIntoView) on.scrollIntoView({block:'nearest',inline:'nearest'});
}
function fillThumb(it,sl){
  const box=it.querySelector('.tbox'); box.textContent='';
  const dom=buildSlideDOM(sl,{thumb:true,pfx:'t'+sl.id}); dom.style.transform='scale(var(--ts))'; dom.style.transformOrigin='0 0'; box.appendChild(dom);
}
function scheduleThumb(id){ thumbDirty.add(id); clearTimeout(thumbTimer); thumbTimer=setTimeout(()=>{ for(const i of thumbDirty){ const it=$(`#stripList .thumb[data-id="${i}"]`); const sl=App.slides.find(s=>s.id===i); if(it&&sl) fillThumb(it,sl);} thumbDirty.clear(); },350); }

/* ---------- tanlov qatlami ---------- */
function elCorners(e){
  const cx=(e.x+e.w/2)/EMU,cy=(e.y+e.h/2)/EMU,w=e.w/EMU,h=e.h/EMU,r=(e.r||0)*Math.PI/180,c=Math.cos(r),s=Math.sin(r);
  return [[-w/2,-h/2],[w/2,-h/2],[w/2,h/2],[-w/2,h/2]].map(([x,y])=>[cx+x*c-y*s,cy+x*s+y*c]);
}
function elBBox(e){ const c=elCorners(e); const xs=c.map(p=>p[0]),ys=c.map(p=>p[1]); return {x0:Math.min(...xs),y0:Math.min(...ys),x1:Math.max(...xs),y1:Math.max(...ys)}; }
const HANDLES=['nw','n','ne','e','se','s','sw','w'];
function renderOverlay(){
  const o=$('#ovl'); o.textContent='';
  if(App.editing) return;
  const els=selEls().filter(e=>!e.hid);
  els.forEach(e=>{
    const b=document.createElement('div'); b.className='sel'+(e.lock?' locked':'')+(e.t==='line'?' isline':''); b.dataset.id=e.id;
    b.style.left=(e.x/EMU)+'px'; b.style.top=(e.y/EMU)+'px'; b.style.width=(e.w/EMU)+'px'; b.style.height=(e.h/EMU)+'px';
    if(e.r) b.style.transform=`rotate(${e.r}deg)`;
    if(els.length===1&&!e.lock){
      if(e.t==='line'){
        const w=e.w/EMU,h=e.h/EMU; const p1=[e.fh?w:0,e.fv?h:0], p2=[e.fh?0:w,e.fv?0:h];
        [['p1',p1],['p2',p2]].forEach(([k,p])=>{ const hd=document.createElement('div'); hd.className='hd end'; hd.dataset.h=k; hd.style.left=p[0]+'px'; hd.style.top=p[1]+'px'; b.appendChild(hd); });
      } else {
        HANDLES.forEach(k=>{ const hd=document.createElement('div'); hd.className='hd '+k; hd.dataset.h=k; b.appendChild(hd); });
        const rot=document.createElement('div'); rot.className='hd rot'; rot.dataset.h='rot'; b.appendChild(rot);
      }
    }
    if(e.lock){ const lk=document.createElement('div'); lk.className='lkicon'; lk.textContent='🔒'; b.appendChild(lk); }
    o.appendChild(b);
  });
  if(els.length>1){
    let x0=1e9,y0=1e9,x1=-1e9,y1=-1e9; els.forEach(e=>{const b=elBBox(e); x0=Math.min(x0,b.x0);y0=Math.min(y0,b.y0);x1=Math.max(x1,b.x1);y1=Math.max(y1,b.y1);});
    const g=document.createElement('div'); g.className='sel group'; g.style.left=x0+'px'; g.style.top=y0+'px'; g.style.width=(x1-x0)+'px'; g.style.height=(y1-y0)+'px';
    HANDLES.forEach(k=>{ const hd=document.createElement('div'); hd.className='hd '+k; hd.dataset.h='g'+k; g.appendChild(hd); });
    o.appendChild(g);
  }
  positionQbar();
}
function positionQbar(){
  const q=$('#qbar'); if(!q) return; const els=selEls().filter(e=>!e.hid);
  if(!els.length||App.editing||App.dragging){ q.classList.remove('show'); return; }
  let x0=1e9,y0=1e9,x1=-1e9,y1=-1e9; els.forEach(e=>{const b=elBBox(e); x0=Math.min(x0,b.x0);y0=Math.min(y0,b.y0);x1=Math.max(x1,b.x1);y1=Math.max(y1,b.y1);});
  const sr=$('#stage').getBoundingClientRect(), wr=$('#work').getBoundingClientRect();
  const cx=sr.left+((x0+x1)/2)*App.scale-wr.left; let top=sr.top+y0*App.scale-wr.top-92; if(top<4) top=sr.top+y1*App.scale-wr.top+16;
  top=Math.min(top,wr.height-52);
  q.classList.add('show'); const half=(q.offsetWidth||240)/2+6; q.style.top=top+'px'; q.style.left=clamp(cx,half,Math.max(half,wr.width-half))+'px';
  const one=els.length===1?els[0]:null; const tx=$('#qEdit'); if(tx) tx.style.display=(one&&one.t==='shape')?'':'none';
  const lk=$('#qLock'); if(lk){ const want=els.every(e=>e.lock)?'unlock':'lock'; if(lk.dataset.i!==want){ lk.dataset.i=want; lk.replaceChildren(ic(want)); } }
}

/* ---------- hammasini yangilash ---------- */
function refreshAll(structural){
  const sl=curSlide(); if(!sl) return;
  renderStage(); renderOverlay(); renderStrip(!!structural);
  if(!structural) scheduleThumb(sl.id);
  if(typeof renderPanel==='function') renderPanel();
  updateUndoUI();
}
function updateUndoUI(){ const u=$('#bUndo'),r=$('#bRedo'); if(u) u.disabled=App.hp<=0; if(r) r.disabled=App.hp>=App.hist.length; }
