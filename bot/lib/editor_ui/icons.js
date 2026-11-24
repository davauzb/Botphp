'use strict';
/* Yagona ikonka to'plami (SVG, 24x24, chiziqli). */
const ICON_PATHS={
  menu:'M4 6h16M4 12h16M4 18h16', undo:'M9 14L4 9l5-5M4 9h10a6 6 0 010 12h-3', redo:'M15 14l5-5-5-5M20 9H10a6 6 0 000 12h3',
  send:'M22 2L11 13M22 2l-7 20-4-9-9-4 20-7z', text:'M5 7V4h14v3M9 20h6M12 4v16', shape:'M3 3h9v9H3zM17 13a4 4 0 100 8 4 4 0 000-8z',
  image:'M3 5h18v14H3zM3 16l5-5 4 4 3-3 6 6M8.5 9.5h.01', line:'M5 19L19 5', table:'M3 4h18v16H3zM3 10h18M3 15h18M9 4v16M15 4v16',
  plus:'M12 5v14M5 12h14', minus:'M5 12h14', multi:'M4 4h16v16H4zM8 12l3 3 5-6', sliders:'M4 7h9M17 7h3M4 17h3M11 17h9M15 4v6M9 14v6',
  pencil:'M4 20h4L19 9l-4-4L4 16v4zM13.5 6.5l4 4', copy:'M8 8h12v12H8zM4 16V4h12', lock:'M6 11h12v10H6zM8 11V7a4 4 0 018 0v4',
  unlock:'M6 11h12v10H6zM8 11V7a4 4 0 017.5-2', front:'M12 19V5M5 12l7-7 7 7', back:'M12 5v14M5 12l7 7 7-7', trash:'M4 7h16M10 11v6M14 11v6M6 7l1 13h10l1-13M9 7V4h6v3',
  play:'M7 4l13 8-13 8z', alL:'M4 6h16M4 10h10M4 14h16M4 18h10', alC:'M4 6h16M7 10h10M4 14h16M7 18h10', alR:'M4 6h16M10 10h10M4 14h16M10 18h10', alJ:'M4 6h16M4 10h16M4 14h16M4 18h16',
  vT:'M4 4h16M12 20V8M8 12l4-4 4 4', vM:'M4 12h16M12 3v6M9 6l3 3 3-3M12 21v-6M9 18l3-3 3 3', vB:'M4 20h16M12 4v12M8 12l4 4 4-4',
  ul:'M9 6h11M9 12h11M9 18h11M4 6h.01M4 12h.01M4 18h.01', ol:'M10 6h10M10 12h10M10 18h10M4 4.5l1.5-1V9M4 14.5c0-1 2-1 2 0 0 1-2 2-2 3h2', close:'M6 6l12 12M18 6L6 18',
  eye:'M2 12s4-7 10-7 10 7 10 7-4 7-10 7S2 12 2 12zM12 9a3 3 0 100 6 3 3 0 000-6z', eyeoff:'M3 3l18 18M10.6 6.1A9.8 9.8 0 0112 6c6 0 10 6 10 6a17 17 0 01-3.2 3.7M6.3 7.6A16.6 16.6 0 002 12s4 7 10 7a9.6 9.6 0 004.2-1M9.9 9.9a3 3 0 004.2 4.2',
  left:'M15 6l-6 6 6 6', right:'M9 6l6 6-6 6',
  up:'M6 15l6-6 6 6', down:'M6 9l6 6 6-6', flipH:'M12 3v18M8 7l-5 5 5 5V7zM16 7l5 5-5 5V7z', flipV:'M3 12h18M7 8l5-5 5 5H7zM7 16l5 5 5-5H7z', rotate:'M21 12a9 9 0 11-3-6.7M21 4v5h-5',
  slides:'M3 5h14v10H3zM7 19h14V9', check:'M5 12l5 5 9-10', search:'M11 4a7 7 0 100 14 7 7 0 000-14zM21 21l-5-5', keys:'M3 7h18v10H3zM7 11h.01M11 11h.01M15 11h.01M7 14h10',
  grid:'M4 4h16v16H4zM4 12h16M12 4v16', magnet:'M6 3v8a6 6 0 0012 0V3M6 7h4M14 7h4', fit:'M4 9V4h5M20 9V4h-5M4 15v5h5M20 15v5h-5', cloud:'M7 18a4 4 0 010-8 5 5 0 019.6-1A4.5 4.5 0 0117 18H7z',
  bold:'M7 5h6a3.5 3.5 0 010 7H7zM7 12h7a3.5 3.5 0 010 7H7z', italic:'M10 5h8M6 19h8M14 5l-4 14', under:'M7 4v7a5 5 0 0010 0V4M5 21h14', strike:'M5 12h14M16 7a4 4 0 00-4-2c-2.5 0-4 1.3-4 3 0 3.5 8 2 8 6 0 1.8-1.7 3-4 3a5 5 0 01-4.5-2',
};
function ic(name,cls){
  const s=document.createElementNS('http://www.w3.org/2000/svg','svg'); s.setAttribute('viewBox','0 0 24 24'); s.setAttribute('aria-hidden','true');
  const p=document.createElementNS('http://www.w3.org/2000/svg','path'); p.setAttribute('d',ICON_PATHS[name]||ICON_PATHS.plus); s.appendChild(p);
  const w=document.createElement('span'); w.className='ico'+(cls?' '+cls:''); w.appendChild(s); return w;
}
