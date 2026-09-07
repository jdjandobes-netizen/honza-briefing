(() => {
  "use strict";
  let itinerary = null;
  const esc = (v) => String(v ?? "");
  const safe = (v) => { try { const u=new URL(v,location.href); return u.protocol==='https:'?u.href:null; } catch { return null; } };
  const node = (tag,cls,text) => { const n=document.createElement(tag); if(cls)n.className=cls; if(text!==undefined)n.textContent=esc(text); return n; };
  const stopNames = (ids) => (ids||[]).map(id => itinerary?.trip?.stops?.find(s=>s.id===id)?.name || id).filter(Boolean);

  const enhance = async () => {
    const host=document.querySelector('.japan-live-alerts'); if(!host) return;
    let data; try { const r=await fetch(`data/emergency-current.json?t=${Date.now()}`,{cache:'no-store'}); if(!r.ok)return; data=await r.json(); } catch { return; }
    const cards=[...host.querySelectorAll('.japan-live-alert')];
    const alerts=(data?.alerts||[]).filter(a=>['critical','warning','advisory'].includes(a.severity)).slice(0,8);
    cards.forEach((card,i)=>{
      const a=alerts[i]; if(!a||card.dataset.czechEnhanced===String(a.id||i))return;
      card.dataset.czechEnhanced=String(a.id||i);
      const h=card.querySelector('h3'); if(h&&a.titleCs)h.textContent=a.titleCs;
      const p=card.querySelector('p'); if(p)p.textContent=a.plainExplanationCs||a.translationCs||p.textContent;
      card.querySelectorAll('.emergency-czech-extra').forEach(x=>x.remove());
      const extra=node('div','emergency-czech-extra');
      const names=stopNames(a.itineraryImpact?.affectedStopIds);
      if(names.length){const hit=node('p','emergency-affected-stops');hit.append(node('strong',null,'Dotčená místa: '),document.createTextNode(names.join(', ')));extra.append(hit);}
      if(a.transportImpact){const tr=node('p','emergency-transport');tr.append(node('strong',null,'Doprava: '),document.createTextNode(a.transportImpact));extra.append(tr);}
      if(Array.isArray(a.recommendedActions)&&a.recommendedActions.length){extra.append(node('div','emergency-actions-title','Co teď udělat'));const ul=node('ul','emergency-actions');a.recommendedActions.forEach(x=>ul.append(node('li',null,x)));extra.append(ul);}
      if(a.translationCs||a.officialOriginal?.title||a.officialOriginal?.summary){const d=node('details','emergency-original');const s=node('summary',null,'Věrný překlad a původní znění');d.append(s);if(a.translationCs)d.append(node('p','emergency-translation',a.translationCs));if(a.officialOriginal?.title)d.append(node('p','emergency-japanese',a.officialOriginal.title));if(a.officialOriginal?.summary)d.append(node('p','emergency-japanese',a.officialOriginal.summary));extra.append(d);}
      const note=node('p','emergency-authority-note','Při skutečné mimořádné události mají přednost aktuální instrukce JMA, J-Alert, místních úřadů a personálu na místě.');extra.append(note);
      card.append(extra);
    });
    if(!alerts.length && !data?.generatedAt){host.hidden=false;host.replaceChildren(node('div','japan-live-stale','Nouzový kanál zatím nemá čerstvá data. Ověř JMA / Safety Tips přímo.'));}
  };

  document.addEventListener('DOMContentLoaded',async()=>{
    try{const r=await fetch(`data/japan-itinerary.json?t=${Date.now()}`,{cache:'no-store'});if(r.ok)itinerary=await r.json();}catch{}
    const obs=new MutationObserver(()=>setTimeout(enhance,0));obs.observe(document.body,{childList:true,subtree:true});
    enhance();setInterval(enhance,60_000);
  });
})();
