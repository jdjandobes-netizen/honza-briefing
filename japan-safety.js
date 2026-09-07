(() => {
  const originalFetch = window.fetch.bind(window);
  let latestEdition = null;
  let itinerary = null;
  let emergencyHost = null;
  let observer = null;

  const escapeText = (v) => String(v ?? "");
  const archivePattern = /data\/archive\/\d{4}-\d{2}-\d{2}-(morning|afternoon)\.json/;
  const safeHttps = (value) => {
    try { const u = new URL(value, location.href); return u.protocol === "https:" ? u.href : null; }
    catch { return null; }
  };
  const impactLabel = (status) => ({affected:"ITINERÁŘ DOTČEN",watch:"ITINERÁŘ SLEDOVAT",not_affected:"ITINERÁŘ NEDOTČEN"}[status] || "ITINERÁŘ");

  window.fetch = async (input, init) => {
    const response = await originalFetch(input, init);
    const requestUrl = typeof input === "string" ? input : input?.url || "";
    if (response.ok && archivePattern.test(requestUrl)) {
      try { latestEdition = await response.clone().json(); queueEnhance(); } catch {}
    }
    return response;
  };

  const el = (tag, cls, text) => {
    const n = document.createElement(tag); if (cls) n.className = cls;
    if (text !== undefined) n.textContent = escapeText(text); return n;
  };

  const inferFromItinerary = (item) => {
    const text = `${item?.title || ""} ${item?.summary || item?.text || ""}`.toLowerCase();
    const stops = itinerary?.trip?.stops || [];
    const direct = stops.find((s) => text.includes(String(s.name || "").toLowerCase()) || text.includes(String(s.prefecture || "").toLowerCase()));
    if (direct) return { location: direct, impact: { status: "watch", text: "Zmíněná oblast leží na plánované trase; sleduj vývoj vzhledem k termínu pobytu a přejezdu.", affectedStopIds: [direct.id] } };
    if (/t[oó]hoku|東北/.test(text)) {
      const ids = stops.filter((s) => !["tsuchiura","utsunomiya","narita-navrat"].includes(s.id)).map((s) => s.id);
      const loc = stops.find((s) => s.id === "aizuwakamatsu") || stops[0];
      return { location: loc, impact: { status: "watch", text: "Jde o širší Tóhoku; část plánované trasy je v dotčeném regionu, konkrétní dopad je nutné upřesňovat podle prefektury a termínu.", affectedStopIds: ids } };
    }
    if (/izu|伊豆/.test(text)) return { location: { name: "Izu Islands", mapUrl: "https://www.google.com/maps/search/?api=1&query=Izu%20Islands%20Japan" }, impact: { status: "not_affected", text: "Izu ostrovy nejsou součástí plánované Tóhoku trasy.", affectedStopIds: [] } };
    if (/tokio|tokyo|kant[oó]|関東/.test(text)) return { location: { name: "Tokyo / Kanto", mapUrl: "https://www.google.com/maps/search/?api=1&query=Tokyo%20Japan" }, impact: { status: "watch", text: "Kantó je relevantní pro začátek/konec cesty a příjezdové koridory; sleduj dopravní dopad před odletem a příjezdem.", affectedStopIds: ["tsuchiura","utsunomiya","narita-navrat"] } };
    return null;
  };

  const mapForItem = (item) => {
    if (item?.location?.mapUrl) return item.location.mapUrl;
    if (Array.isArray(item?.locations)) return item.locations.find((x) => x?.mapUrl)?.mapUrl || null;
    return inferFromItinerary(item)?.location?.mapUrl || null;
  };

  const addItemMeta = (story, item) => {
    if (!story || story.querySelector(":scope > .japan-meta")) return;
    const inferred = inferFromItinerary(item);
    const impact = item?.itineraryImpact || inferred?.impact;
    const mapUrl = safeHttps(mapForItem(item));
    if (!impact && !mapUrl) return;
    const box = el("div", "japan-meta");
    if (impact?.status) {
      const chip = el("span", "japan-impact", impactLabel(impact.status));
      chip.dataset.impact = impact.status;
      box.append(chip);
    }
    if (mapUrl) {
      const inferredName = inferred?.location?.name;
      const a = el("a", "japan-map-link", `📍 ${item.location?.name || item.locations?.[0]?.name || inferredName || "Mapa"}`);
      a.href = mapUrl; a.target = "_blank"; a.rel = "noopener noreferrer"; box.append(a);
    }
    if (impact?.text) box.append(el("div", "japan-impact-detail", impact.text));
    story.append(box);
  };

  const renderWeather = (travel) => {
    const block = el("div", "japan-travel-dashboard");
    block.dataset.japanTravel = "1";
    block.append(el("h3", null, "Počasí na celé trase"));
    block.append(el("p", "japan-dashboard-intro", "Všechny zastávky z itineráře. Vzdálenější termíny jsou záměrně vedené jako týdenní nebo sezónní trend, ne jako falešně přesná denní předpověď."));
    const routePins = el("div", "japan-route-pins");
    for (const stop of travel?.routeStops || itinerary?.trip?.stops || []) {
      const url = safeHttps(stop.mapUrl);
      if (!url) continue;
      const a = el("a", null, `📍 ${stop.name}`); a.href=url; a.target="_blank"; a.rel="noopener noreferrer"; routePins.append(a);
    }
    if (routePins.childElementCount) block.append(routePins);
    const grid = el("div", "japan-weather-grid");
    for (const stop of travel?.weatherStops || []) {
      const card = el("article", "japan-weather-card");
      const head = el("header");
      const left = el("div"); left.append(el("strong", null, stop.name)); left.append(el("div", "japan-weather-stay", stop.stay || stop.validFor || ""));
      head.append(left);
      const url=safeHttps(stop.mapUrl);
      if (url) { const a=el("a","japan-map-link","📍"); a.href=url;a.target="_blank";a.rel="noopener noreferrer";head.append(a); }
      card.append(head);
      card.append(el("div", "japan-weather-type", `${stop.forecastType || "výhled"}${stop.confidence ? ` · ${stop.confidence}` : ""}`));
      card.append(el("p", "japan-weather-summary", stop.summary || "Aktuální výhled není k dispozici."));
      const details=[stop.temperature,stop.precipitation,stop.wind].filter(Boolean).join(" · "); if(details) card.append(el("div", "japan-weather-source", details));
      if (Array.isArray(stop.hazards) && stop.hazards.length) card.append(el("div", "japan-weather-hazards", `Pozor: ${stop.hazards.join(" · ")}`));
      const src=safeHttps(stop.source?.url); if(src){const a=el("a","japan-weather-source",stop.source?.name||"Zdroj");a.href=src;a.target="_blank";a.rel="noopener noreferrer";card.append(a);}
      grid.append(card);
    }
    if (grid.childElementCount) block.append(grid);
    return block;
  };

  const renderEmergencyGuide = (guide) => {
    if (!guide) return null;
    const box = el("div", "japan-emergency-guide"); box.dataset.japanEmergencyGuide="1";
    box.append(el("h3", null, "Nouzová karta pro Japonsko"));
    const grid=el("div","japan-emergency-grid");
    const nums=guide.numbers || {};
    const n1=el("div","japan-emergency-box"); n1.append(el("strong",null,"Nouzové linky")); n1.append(el("p",null,`Policie ${nums.police||"110"} · Hasiči / sanitka ${nums.fireAmbulance||"119"} · Pobřežní stráž ${nums.coastGuard||"118"} · JNTO ${nums.visitorHotline||"050-3816-2787"}`)); grid.append(n1);
    const n2=el("div","japan-emergency-box"); n2.append(el("strong",null,"Tsunami")); n2.append(el("p",null,guide.tsunami || "Při tsunami warning/advisory nebo červeno-bílé tsunami vlajce opusť pobřeží a jdi okamžitě do vyšší polohy či určené evakuační budovy.")); grid.append(n2);
    const n3=el("div","japan-emergency-box"); n3.append(el("strong",null,"Evakuační úrovně")); n3.append(el("p",null,guide.alertLevels || "Level 3: evakuují lidé potřebující více času. Level 4: evakuují všichni z nebezpečné oblasti. Level 5: okamžitě zajisti život.")); grid.append(n3);
    const n4=el("div","japan-emergency-box"); n4.append(el("strong",null,"Evakuační místa")); n4.append(el("p",null,guide.evacuation || "Použij oficiální GSI mapu a vyber místo určené pro konkrétní typ nebezpečí."));
    for(const src of guide.sources||[]){const u=safeHttps(src.url);if(!u)continue;const a=el("a","japan-map-link",src.name);a.href=u;a.target="_blank";a.rel="noopener noreferrer";n4.append(document.createTextNode(" "),a);} grid.append(n4);
    box.append(grid); return box;
  };

  const enhanceJapan = () => {
    const section = document.querySelector("#japonsko");
    const j = latestEdition?.sections?.find((s) => s?.id === "japonsko");
    if (!section || !j) return;
    [...section.querySelectorAll(".story-list .story")].forEach((node,i)=>addItemMeta(node,j.items?.[i]));
    [...section.querySelectorAll(".minor-list li")].forEach((node,i)=>addItemMeta(node,j.minor?.[i]));
    const travel = j.travel || (itinerary ? { routeStops: itinerary.trip?.stops || [], weatherStops: [] } : null);
    if (travel && !section.querySelector("[data-japan-travel]")) section.append(renderWeather(travel));
    const er = itinerary?.emergencyResources || {};
    const fallbackGuide = itinerary ? {
      numbers: { police: er.police, fireAmbulance: er.fireAmbulance, coastGuard: er.coastGuard, visitorHotline: er.visitorHotline },
      tsunami: "Při tsunami warning/advisory, siréně nebo červeno-bílé kostkované tsunami vlajce na pobřeží okamžitě opusť pobřeží a jdi do vyšší polohy nebo určené tsunami evacuation building.",
      alertLevels: "Level 2: ověř plán. Level 3: evakuují lidé potřebující více času. Level 4: všichni z nebezpečné oblasti evakuují. Level 5: bezprostřední ohrožení — okamžitě zajisti život.",
      evacuation: "V GSI mapě vyber designated emergency evacuation site určené pro konkrétní typ nebezpečí; při skutečné události má přednost aktuální pokyn obce/JMA.",
      sources: [
        { name: "GSI evakuační místa", url: er.gsiEvacuation },
        { name: "JMA multilingual", url: er.jmaMultilingual },
        { name: "JNTO Safety Tips", url: er.safetyTips }
      ]
    } : null;
    const guide=j.travel?.emergencyGuide || fallbackGuide; if(guide && !section.querySelector("[data-japan-emergency-guide]")){const g=renderEmergencyGuide(guide);if(g)section.append(g);}
  };

  const queueEnhance=()=>setTimeout(enhanceJapan,0);
  const loadItinerary = async () => {
    try { const r=await originalFetch(`data/japan-itinerary.json?t=${Date.now()}`,{cache:"no-store"}); if(r.ok) itinerary=await r.json(); }
    catch(e){ console.warn("Japan itinerary unavailable",e); }
  };
  const renderLive = (data) => {
    if (!emergencyHost) {
      emergencyHost=el("aside","japan-live-alerts"); emergencyHost.setAttribute("aria-live","assertive");
      const anchor=document.querySelector(".section-nav") || document.querySelector(".site-header"); anchor?.insertAdjacentElement("afterend",emergencyHost);
    }
    emergencyHost.replaceChildren();
    const generated=Date.parse(data?.generatedAt || ""); const stale=!Number.isFinite(generated) || Date.now()-generated>20*60*1000;
    if(stale){const s=el("div","japan-live-stale","⚠ Nouzový kanál není čerstvý. Ověř JMA / Safety Tips přímo."); emergencyHost.append(s);}
    const alerts=(data?.alerts||[]).filter(a=>["critical","warning","advisory"].includes(a.severity));
    for(const alert of alerts.slice(0,8)){
      const card=el("article","japan-live-alert"); card.dataset.severity=alert.severity;
      const meta=el("div","japan-live-meta"); meta.append(el("span","emergency-badge",alert.severity));
      if(alert.itineraryImpact?.status) { const chip=el("span","japan-impact",impactLabel(alert.itineraryImpact.status));chip.dataset.impact=alert.itineraryImpact.status;meta.append(chip); }
      card.append(meta,el("h3",null,alert.title),el("p",null,alert.summary||alert.itineraryImpact?.text||""));
      const u=safeHttps(alert.source?.url); if(u){const a=el("a","japan-map-link",`${alert.source?.name||"Oficiální zdroj"} ↗`);a.href=u;a.target="_blank";a.rel="noopener noreferrer";card.append(a);}
      const m=safeHttps(alert.location?.mapUrl); if(m){const a=el("a","japan-map-link",` 📍 ${alert.location?.name||"Mapa"}`);a.href=m;a.target="_blank";a.rel="noopener noreferrer";card.append(a);}
      emergencyHost.append(card);
    }
    emergencyHost.hidden = emergencyHost.childElementCount===0;
  };
  const pollEmergency = async () => {
    try { const r=await originalFetch(`data/emergency-current.json?t=${Date.now()}`,{cache:"no-store"}); if(r.ok) renderLive(await r.json()); }
    catch(e){ console.warn("Emergency channel unavailable",e); }
  };
  document.addEventListener("DOMContentLoaded", async () => {
    await loadItinerary();
    observer=new MutationObserver(queueEnhance); const app=document.querySelector("#app"); if(app) observer.observe(app,{childList:true,subtree:true});
    queueEnhance(); pollEmergency(); setInterval(pollEmergency,60_000);
  });
})();
