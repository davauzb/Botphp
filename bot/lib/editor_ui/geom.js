'use strict';
/* Shakl geometriyasi (OOXML preset formulalariga mos). Barcha o'lchamlar px. */
const GEOM_META = {
  rect:{n:'To‘rtburchak',p:[]},
  roundRect:{n:'Yumaloq to‘rtburchak',p:[['adj','Burchak radiusi',0,50000,16667]]},
  ellipse:{n:'Doira / oval',p:[]},
  triangle:{n:'Uchburchak',p:[['adj','Cho‘qqi o‘rni',0,100000,50000]]},
  rtTriangle:{n:'To‘g‘ri burchakli uchburchak',p:[]},
  diamond:{n:'Romb',p:[]},
  parallelogram:{n:'Parallelogramm',p:[['adj','Og‘ish',0,100000,25000]]},
  trapezoid:{n:'Trapetsiya',p:[['adj','Og‘ish',0,100000,25000]]},
  hexagon:{n:'Olti burchak',p:[['adj','Burchak',0,50000,25000]]},
  octagon:{n:'Sakkiz burchak',p:[['adj','Kesish',0,50000,29289]]},
  star5:{n:'Yulduz',p:[['adj','Ichki radius',0,50000,19098]]},
  plus:{n:'Plyus',p:[['adj','Qalinlik',0,50000,25000]]},
  rightArrow:{n:'Strelka →',p:[['adj1','Tana qalinligi',0,100000,50000],['adj2','Uch uzunligi',0,100000,50000]]},
  leftArrow:{n:'Strelka ←',p:[['adj1','Tana qalinligi',0,100000,50000],['adj2','Uch uzunligi',0,100000,50000]]},
  upArrow:{n:'Strelka ↑',p:[['adj1','Tana qalinligi',0,100000,50000],['adj2','Uch uzunligi',0,100000,50000]]},
  downArrow:{n:'Strelka ↓',p:[['adj1','Tana qalinligi',0,100000,50000],['adj2','Uch uzunligi',0,100000,50000]]},
  chevron:{n:'Chevron',p:[['adj','Burchak',0,100000,50000]]},
  homePlate:{n:'Besh burchakli strelka',p:[['adj','Uch uzunligi',0,100000,50000]]},
  snip1Rect:{n:'Bitta burchak kesilgan',p:[['adj','Kesish',0,50000,16667]]},
  snip2SameRect:{n:'Tepa burchaklar kesilgan',p:[['adj1','Tepa kesish',0,50000,16667],['adj2','Past kesish',0,50000,0]]},
  snip2DiagRect:{n:'Diagonal kesilgan',p:[['adj1','Chap-tepa/o‘ng-past',0,50000,0],['adj2','O‘ng-tepa/chap-past',0,50000,16667]]},
  round2SameRect:{n:'Tepa burchaklar yumaloq',p:[['adj1','Tepa radius',0,50000,16667],['adj2','Past radius',0,50000,0]]},
  foldedCorner:{n:'Buklangan burchak',p:[['adj','Bukla o‘lchami',0,50000,16667]]},
  wave:{n:'To‘lqin',p:[['adj1','Balandlik',0,20000,12500],['adj2','Siljish',-10000,10000,0]]},
  flowChartOffpageConnector:{n:'Tilmoch',p:[]},
  donut:{n:'Halqa',p:[['adj','Qalinlik',0,50000,25000]]},
  line:{n:'Chiziq',p:[]},
};
const SHAPE_PICKER = ['rect','roundRect','ellipse','triangle','rtTriangle','diamond','parallelogram','trapezoid','hexagon','octagon','star5','plus',
  'rightArrow','leftArrow','upArrow','downArrow','chevron','homePlate','snip1Rect','snip2SameRect','snip2DiagRect','round2SameRect','foldedCorner','wave','flowChartOffpageConnector','donut'];

function geomDef(g,k){ const m=GEOM_META[g]; if(!m) return 0; const p=m.p.find(x=>x[0]===k); return p?p[4]:0; }
function ga(adj,g,k){ return (adj && adj[k]!=null) ? +adj[k] : geomDef(g,k); }
const clampN=(v,a,b)=>Math.max(a,Math.min(b,v));
const f=n=>Math.round(n*100)/100;
const poly=pts=>'M'+pts.map(p=>f(p[0])+' '+f(p[1])).join('L')+'Z';

/** Qaytadi: [{d, kind:'main'|'dark', rule}] */
function geomPaths(g,w,h,adj){
  const ss=Math.min(w,h), hc=w/2, vc=h/2;
  const A=k=>ga(adj,g,k);
  switch(g){
    case 'roundRect':{ const r=ss*clampN(A('adj'),0,50000)/100000;
      return [{d:`M${f(r)} 0H${f(w-r)}A${f(r)} ${f(r)} 0 0 1 ${f(w)} ${f(r)}V${f(h-r)}A${f(r)} ${f(r)} 0 0 1 ${f(w-r)} ${f(h)}H${f(r)}A${f(r)} ${f(r)} 0 0 1 0 ${f(h-r)}V${f(r)}A${f(r)} ${f(r)} 0 0 1 ${f(r)} 0Z`}]; }
    case 'ellipse': return [{d:`M0 ${f(vc)}A${f(hc)} ${f(vc)} 0 1 0 ${f(w)} ${f(vc)}A${f(hc)} ${f(vc)} 0 1 0 0 ${f(vc)}Z`}];
    case 'triangle': return [{d:poly([[0,h],[w*clampN(A('adj'),0,100000)/100000,0],[w,h]])}];
    case 'rtTriangle': return [{d:poly([[0,h],[0,0],[w,h]])}];
    case 'diamond': return [{d:poly([[0,vc],[hc,0],[w,vc],[hc,h]])}];
    case 'parallelogram':{ const mx=ss?100000*w/ss:100000; const x2=ss*clampN(A('adj'),0,mx)/100000; return [{d:poly([[0,h],[x2,0],[w,0],[w-x2,h]])}]; }
    case 'trapezoid':{ const mx=ss?50000*w/ss:50000; const x2=ss*clampN(A('adj'),0,mx)/100000; return [{d:poly([[0,h],[x2,0],[w-x2,0],[w,h]])}]; }
    case 'hexagon':{ const mx=ss?50000*w/ss:50000; const x1=ss*clampN(A('adj'),0,mx)/100000; return [{d:poly([[0,vc],[x1,0],[w-x1,0],[w,vc],[w-x1,h],[x1,h]])}]; }
    case 'octagon':{ const x1=ss*clampN(A('adj'),0,50000)/100000; return [{d:poly([[0,x1],[x1,0],[w-x1,0],[w,x1],[w,h-x1],[w-x1,h],[x1,h],[0,h-x1]])}]; }
    case 'star5':{
      const ratio=0.382*clampN(A('adj'),1,50000)/19098; const pts=[];
      for(let i=0;i<10;i++){ const ang=-Math.PI/2+i*Math.PI/5; const r=i%2?ratio:1; pts.push([Math.cos(ang)*r,Math.sin(ang)*r]); }
      const xs=pts.map(p=>p[0]), ys=pts.map(p=>p[1]); const x0=Math.min(...xs),x1=Math.max(...xs),y0=Math.min(...ys),y1=Math.max(...ys);
      return [{d:poly(pts.map(p=>[(p[0]-x0)/(x1-x0)*w,(p[1]-y0)/(y1-y0)*h]))}]; }
    case 'plus':{ const x1=ss*clampN(A('adj'),0,50000)/100000; return [{d:poly([[x1,0],[w-x1,0],[w-x1,x1],[w,x1],[w,h-x1],[w-x1,h-x1],[w-x1,h],[x1,h],[x1,h-x1],[0,h-x1],[0,x1],[x1,x1]])}]; }
    case 'rightArrow':case 'leftArrow':{
      const mx2=ss?100000*w/ss:100000; const a1=clampN(A('adj1'),0,100000), a2=clampN(A('adj2'),0,mx2);
      const dx1=ss*a2/100000, dy1=h*a1/200000, y1=vc-dy1, y2=vc+dy1;
      if(g==='rightArrow'){ const x1=w-dx1; return [{d:poly([[0,y1],[x1,y1],[x1,0],[w,vc],[x1,h],[x1,y2],[0,y2]])}]; }
      return [{d:poly([[w,y1],[dx1,y1],[dx1,0],[0,vc],[dx1,h],[dx1,y2],[w,y2]])}]; }
    case 'upArrow':case 'downArrow':{
      const mx2=ss?100000*h/ss:100000; const a1=clampN(A('adj1'),0,100000), a2=clampN(A('adj2'),0,mx2);
      const dy2=ss*a2/100000, dx1=w*a1/200000, x1=hc-dx1, x2=hc+dx1;
      if(g==='upArrow') return [{d:poly([[0,dy2],[hc,0],[w,dy2],[x2,dy2],[x2,h],[x1,h],[x1,dy2]])}];
      return [{d:poly([[0,h-dy2],[hc,h],[w,h-dy2],[x2,h-dy2],[x2,0],[x1,0],[x1,h-dy2]])}]; }
    case 'chevron':{ const mx=ss?100000*w/ss:100000; const x1=ss*clampN(A('adj'),0,mx)/100000; return [{d:poly([[0,0],[w-x1,0],[w,vc],[w-x1,h],[0,h],[x1,vc]])}]; }
    case 'homePlate':{ const mx=ss?100000*w/ss:100000; const dx=ss*clampN(A('adj'),0,mx)/100000; return [{d:poly([[0,0],[w-dx,0],[w,vc],[w-dx,h],[0,h]])}]; }
    case 'snip1Rect':{ const dx=ss*clampN(A('adj'),0,50000)/100000; return [{d:poly([[0,0],[w-dx,0],[w,dx],[w,h],[0,h]])}]; }
    case 'snip2SameRect':{ const d1=ss*clampN(A('adj1'),0,50000)/100000, d2=ss*clampN(A('adj2'),0,50000)/100000;
      return [{d:poly([[d1,0],[w-d1,0],[w,d1],[w,h-d2],[w-d2,h],[d2,h],[0,h-d2],[0,d1]])}]; }
    case 'snip2DiagRect':{ const a=ss*clampN(A('adj1'),0,50000)/100000, b=ss*clampN(A('adj2'),0,50000)/100000;
      return [{d:poly([[a,0],[w-b,0],[w,b],[w,h-a],[w-a,h],[b,h],[0,h-b],[0,a]])}]; }
    case 'round2SameRect':{ const r1=ss*clampN(A('adj1'),0,50000)/100000, r2=ss*clampN(A('adj2'),0,50000)/100000;
      return [{d:`M${f(r1)} 0H${f(w-r1)}A${f(r1)} ${f(r1)} 0 0 1 ${f(w)} ${f(r1)}V${f(h-r2)}A${f(r2)} ${f(r2)} 0 0 1 ${f(w-r2)} ${f(h)}H${f(r2)}A${f(r2)} ${f(r2)} 0 0 1 0 ${f(h-r2)}V${f(r1)}A${f(r1)} ${f(r1)} 0 0 1 ${f(r1)} 0Z`}]; }
    case 'foldedCorner':{ const d=ss*clampN(A('adj'),0,50000)/100000;
      return [{d:poly([[0,0],[w,0],[w,h-d],[w-d,h],[0,h]])},{d:poly([[w-d,h],[w-d*0.15,h-d*0.85],[w,h-d]]),kind:'dark'}]; }
    case 'wave':{ const dy=h*clampN(A('adj1'),0,20000)/100000*2; const sh=clampN(A('adj2'),-10000,10000)/10000;
      const t=dy, b=h-dy; const sx=w*sh*0.1;
      return [{d:`M0 ${f(t)}C${f(w*.2+sx)} ${f(t-dy)} ${f(w*.4+sx)} ${f(t-dy)} ${f(w*.5)} ${f(t)}S${f(w*.8-sx)} ${f(t+dy)} ${f(w)} ${f(t)}V${f(b)}C${f(w*.8-sx)} ${f(b+dy)} ${f(w*.6-sx)} ${f(b+dy)} ${f(w*.5)} ${f(b)}S${f(w*.2+sx)} ${f(b-dy)} 0 ${f(b)}Z`}]; }
    case 'flowChartOffpageConnector': return [{d:poly([[0,0],[w,0],[w,h*0.8],[hc,h],[0,h*0.8]])}];
    case 'donut':{ const dr=ss*clampN(A('adj'),0,50000)/100000; const rx=hc-dr, ry=vc-dr;
      return [{rule:'evenodd',d:`M0 ${f(vc)}A${f(hc)} ${f(vc)} 0 1 0 ${f(w)} ${f(vc)}A${f(hc)} ${f(vc)} 0 1 0 0 ${f(vc)}ZM${f(dr)} ${f(vc)}A${f(Math.max(0,rx))} ${f(Math.max(0,ry))} 0 1 1 ${f(w-dr)} ${f(vc)}A${f(Math.max(0,rx))} ${f(Math.max(0,ry))} 0 1 1 ${f(dr)} ${f(vc)}Z`}]; }
    case 'line': return [{d:`M0 0L${f(w)} ${f(h)}`}];
    default: return [{d:`M0 0H${f(w)}V${f(h)}H0Z`}];
  }
}
