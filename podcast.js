(() => {
  "use strict";
  const SERVICE_ORIGIN = "https://briefing.nacestach.online";
  const API = "api/podcast.php";
  let activeId = null, panel = null, session = null, settings = null, poll = null, epoch = 0, dialog = null;
  let lastState = "", previousStatus = null, csrf = "", firstMount = true;
  const node = (tag, className, text) => { const n = document.createElement(tag); if (className) n.className = className; if (text) n.textContent = text; return n; };
  const local = () => ["127.0.0.1", "localhost"].includes(location.hostname);
  const onService = () => location.origin === SERVICE_ORIGIN || local();
  const validId = id => /^\d{4}-\d{2}-\d{2}-(morning|afternoon)$/.test(id || "");
  const label = id => id?.replace(/-(morning|afternoon)$/, (_, e) => e === "morning" ? " · ráno" : " · odpoledne") || "";
  const button = (text, action, cls = "podcast-button") => { const b = node("button", cls, text); b.type = "button"; b.addEventListener("click", action); return b; };
  async function api(action, body, id = activeId) {
    const url = new URL(API, location.href); url.searchParams.set("action", action); if (id) url.searchParams.set("id", id);
    const response = await fetch(url, {method: body ? "POST" : "GET", credentials: "same-origin", cache: "no-store",
      headers: body ? {"Content-Type": "application/json", "X-CSRF-Token": csrf} : {}, body: body ? JSON.stringify(body) : undefined});
    let result; try { result = await response.json(); } catch { throw new Error("Podcastová služba není na této adrese dostupná."); }
    if (!response.ok) { if (response.status === 401) session = null; throw new Error(result.error || "Požadavek se nepodařil."); }
    if (result.csrf) csrf = result.csrf;
    return result;
  }
  function message(text) { if (panel) panel.querySelector(".podcast-status").textContent = text; }
  function status(job) {
    if (!panel) return;
    const state = JSON.stringify(job);
    if (state === lastState) return;
    lastState = state;
    const content = panel.querySelector(".podcast-content"); content.replaceChildren();
    if (!job) {
      const prepare = panel.querySelector(".podcast-prepare"); prepare.disabled=false; prepare.textContent="Připrav podcast"; previousStatus=null;
      message(`Podcast · ${label(activeId)}. Celé vydání po kapitolách; uložený zvuk přehraješ znovu bez generování.`); return;
    }
    if (job.status === "ready") {
      message(`Uložený podcast · ${label(job.id)} · ${Math.ceil(job.duration / 60)} min`);
      const audio = node("audio", "podcast-audio"); audio.controls = true; audio.preload = "metadata"; audio.src = job.audioUrl;
      const positionKey = `briefing-podcast-position:${job.id}`;
      audio.addEventListener("loadedmetadata", () => { try { const saved = Number(localStorage.getItem(positionKey)); if (saved > 0 && saved < audio.duration - 5) audio.currentTime = saved; } catch {} });
      let lastSave = 0;
      audio.addEventListener("timeupdate", () => { if (Date.now() - lastSave < 3000) return; lastSave = Date.now(); try { localStorage.setItem(positionKey, String(audio.currentTime)); } catch {} });
      audio.addEventListener("ended", () => { try { localStorage.removeItem(positionKey); } catch {} });
      audio.addEventListener("error", () => message("Zvuk se nepodařilo načíst. Zkontroluj přihlášení a připojení."));
      const chapters = node("select", "podcast-select"); chapters.setAttribute("aria-label", "Přejít na kapitolu podcastu");
      chapters.append(new Option("Vybrat kapitolu…", ""));
      for (const c of job.chapters) chapters.append(new Option(`${Math.floor(c.start/60)}:${String(Math.floor(c.start%60)).padStart(2,"0")} · ${c.title}`, String(c.start)));
      chapters.addEventListener("change", () => { if (chapters.value !== "") audio.currentTime = Number(chapters.value); });
      const speed = node("select", "podcast-select"); speed.setAttribute("aria-label", "Rychlost podcastu");
      for (const rate of [.8,1,1.15,1.3,1.5,2]) speed.append(new Option(`${rate}×`,String(rate),rate===1,rate===1));
      speed.addEventListener("change", () => { audio.playbackRate = Number(speed.value); });
      const controls = node("div", "podcast-play-controls"); controls.append(chapters,speed);
      const download = node("a", "podcast-download", "Stáhnout MP3"); download.href = job.audioUrl + "&download=1";
      content.append(audio,controls,download);
      if (previousStatus && ["queued","running"].includes(previousStatus)) {
        const toast = node("div", "podcast-toast", "Podcast je připravený k poslechu."); toast.setAttribute("role","alert"); document.body.append(toast); setTimeout(() => toast.remove(),8000);
      }
    } else if (["queued","running"].includes(job.status)) {
      message(`Připravuji podcast · ${job.completed} z ${job.total} částí. Můžeš stránku zavřít; server pokračuje.`);
      const progress = node("progress"); progress.max = job.total; progress.value = job.completed; progress.setAttribute("aria-label","Průběh přípravy podcastu"); content.append(progress);
    } else {
      message(job.error || "Příprava čeká na pokračování.");
      content.append(button("Pokračovat v přípravě", async () => {
        const id = activeId;
        const uncertain = job.status === "needs_retry";
        if (uncertain && !window.confirm("Přerušená část mohla být zaplacena. Chceš ji zkusit vytvořit znovu s možností dalšího účtování? Hotové části se znovu negenerují.")) return;
        try { await api("retry",{id,acknowledgeCharge:uncertain},id); await refresh(); } catch(e) { message(e.message); }
      }));
    }
    const prepare = panel.querySelector(".podcast-prepare");
    prepare.disabled = ["queued","running","ready"].includes(job.status);
    prepare.textContent = job.status === "ready" ? "Podcast je uložený" : ["queued","running"].includes(job.status) ? "Připravuji…" : "Připrav podcast";
    previousStatus = job.status;
  }
  async function refresh() {
    if (!onService() || !activeId) return;
    const id = activeId, version = epoch;
    try {
      if (!session) session = await api("session",undefined,null);
      if (version !== epoch) return;
      if (!session.authenticated) { message("Pro přípravu a poslech podcastu se přihlas v nastavení."); return; }
      const result = await api("status",undefined,id);
      if (version !== epoch || id !== activeId) return;
      status(result.job);
      clearTimeout(poll);
      if (["queued","running"].includes(result.job?.status)) poll = setTimeout(refresh,5000);
    } catch(e) { if (version === epoch) { message(e.message); clearTimeout(poll); poll=setTimeout(refresh,15000); } }
  }
  async function prepare() {
    if (!onService()) { location.href = `${SERVICE_ORIGIN}/?podcast=${encodeURIComponent(activeId)}`; return; }
    const id = activeId;
    const b = panel.querySelector(".podcast-prepare"); b.disabled = true;
    try {
      if (!session) session = await api("session",undefined,null);
      if (!session.authenticated) { await openSettings(); return; }
      settings = await api("settings",undefined,null);
      if (!settings.hasKey) { await openSettings(); return; }
      if (id !== activeId) return;
      const result = await api("prepare",{id},id);
      if (id === activeId) { status(result.job); await refresh(); }
    } catch(e) { message(e.message); }
    finally { if (b.isConnected && !["queued","running","ready"].includes(previousStatus)) b.disabled = false; }
  }
  function field(form, title, type, name) {
    const label = node("label","podcast-field",title); const input = node("input"); input.type=type; input.name=name;
    input.autocomplete = name === "password" ? "current-password" : "off"; label.append(input); form.append(label); return input;
  }
  async function openSettings() {
    if (!onService()) { location.href = SERVICE_ORIGIN + "/"; return; }
    if (dialog) dialog.remove();
    dialog = node("dialog","podcast-dialog"); dialog.setAttribute("aria-labelledby","podcast-settings-title");
    const title = node("h2",null,"Nastavení podcastu"); title.id = "podcast-settings-title";
    dialog.append(title,button("Zavřít",()=>dialog.close(),"podcast-close"));
    const error = node("p","podcast-form-message"); error.setAttribute("role","status"); dialog.append(error); document.body.append(dialog); dialog.showModal();
    try {
      session = await api("session",undefined,null);
      if (!session.authenticated) {
        const form = node("form");
        form.append(node("p",null,session.setupRequired ? "Zvol si soukromé heslo pro nastavení a podcasty (nejméně 12 znaků)." : "Přihlas se svým heslem. API klíč se zadává až uvnitř nastavení."));
        const password = field(form,"Heslo","password","password"); password.required=true; password.minLength=12; password.maxLength=200;
        password.autocomplete = session.setupRequired ? "new-password" : "current-password";
        let token = new URLSearchParams(location.hash.slice(1)).get("setup") || "";
        if (token) history.replaceState(null,"",location.pathname+location.search);
        let setupInput = null;
        if (session.setupRequired && !token) { setupInput = field(form,"Jednorázový kód prvotního nastavení","password","setupToken"); setupInput.required=true; }
        const submit = node("button","podcast-button",session.setupRequired ? "Uložit heslo" : "Přihlásit se"); submit.type="submit"; form.append(submit);
        form.addEventListener("submit",async e=>{
          e.preventDefault(); submit.disabled=true;
          try { session=await api(session.setupRequired?"setup":"login",{password:password.value,setupToken:token || setupInput?.value || ""},null); password.value=""; token=""; await openSettings(); await refresh(); }
          catch(e) { error.textContent=e.message; submit.disabled=false; }
        }); dialog.append(form); return;
      }
      settings=await api("settings",undefined,null);
      const form=node("form");
      form.append(node("p",null,settings.hasKey ? "API klíč je uložený šifrovaně na serveru. Pole nech prázdné, pokud ho nechceš změnit." : "Vlož vlastní Gemini API klíč. Uloží se šifrovaně na serveru a prohlížeč ho nedostává zpět."));
      const key=field(form,"Gemini API klíč","password","apiKey"); key.spellcheck=false; key.maxLength=200;
      const label=node("label","podcast-field","Hlas dalších podcastů"); const voice=node("select","podcast-select");
      const names={Charon:"Charon · informativní",Kore:"Kore · pevný",Sulafat:"Sulafat · vřelý",Iapetus:"Iapetus · jasný",Schedar:"Schedar · vyrovnaný"};
      for(const v of settings.voices) voice.append(new Option(names[v]||v,v,v===settings.voice,v===settings.voice)); label.append(voice); form.append(label);
      form.append(node("p","podcast-small","Změna hlasu se projeví u nových podcastů. Jednou uložená vydání se nepřepisují ani znovu neúčtují."));
      const submit=node("button","podcast-button","Uložit nastavení"); submit.type="submit"; form.append(submit);
      form.addEventListener("submit",async e=>{ e.preventDefault(); submit.disabled=true; try { settings=await api("settings",{apiKey:key.value,voice:voice.value},null); key.value=""; error.textContent="Nastavení je uložené. Podcast spustíš tlačítkem Připrav podcast."; } catch(e) {error.textContent=e.message;} finally {submit.disabled=false;} });
      dialog.append(form);
      if (!settings.workerOnline) dialog.append(node("p","podcast-form-message","Generátor zatím nehlásí běh. Příprava bude dostupná po spuštění serverové úlohy."));
      const push=button("Zapnout upozornění na dokončení",async()=>{
        try {
          if (!("Notification" in window) || !("PushManager" in window) || !settings.pushPublicKey) throw new Error("Upozornění v tomto prohlížeči nebo na serveru zatím nejsou dostupná.");
          const permission=await Notification.requestPermission(); if(permission!=="granted") throw new Error("Upozornění nejsou povolená. Změň oprávnění webu v prohlížeči.");
          const reg=await navigator.serviceWorker.ready;
          const encoded=settings.pushPublicKey.replace(/-/g,"+").replace(/_/g,"/");
          const raw=atob(encoded+"=".repeat((4-encoded.length%4)%4));
          const subscription=await reg.pushManager.getSubscription() || await reg.pushManager.subscribe({userVisibleOnly:true,applicationServerKey:Uint8Array.from(raw,c=>c.charCodeAt(0))});
          await api("subscribe",{subscription:subscription.toJSON()},null); error.textContent="Upozornění pro toto zařízení je zapnuté.";
        } catch(e) {error.textContent=e.message;}
      },"podcast-secondary");
      dialog.append(push,node("p","podcast-small","Na iPhonu a iPadu nejprve přidej tento web na plochu a otevři ho z jeho ikony. Pak zde povol upozornění."));
      dialog.append(button("Vypnout upozornění na tomto zařízení",async()=>{try{const reg=await navigator.serviceWorker.ready;const sub=await reg.pushManager.getSubscription();if(sub){await api("unsubscribe",{endpoint:sub.endpoint},null);await sub.unsubscribe();}error.textContent="Upozornění je vypnuté.";}catch(e){error.textContent=e.message;}},"podcast-secondary"));
      dialog.append(button("Odhlásit se",async()=>{await api("logout",{},null); session=null; settings=null; dialog.close(); panel?.querySelector("audio")?.pause(); lastState=""; panel?.querySelector(".podcast-content")?.replaceChildren(); await refresh();},"podcast-secondary"));
    } catch(e) {error.textContent=e.message;}
  }
  async function showScript() {
    try {
      const result=await api("script");
      const d=node("dialog","podcast-dialog podcast-script-dialog"); d.append(node("h2",null,"Scénář pro čtení"),button("Zavřít",()=>d.close(),"podcast-close"));
      d.append(node("p","podcast-small",`${result.script.chunks.length} kratších částí · přibližně ${result.script.estimatedMinutes} min. Anglické značky v závorkách řídí přednes.`));
      const text=node("pre","podcast-script",result.script.text); d.append(text); document.body.append(d); d.addEventListener("close",()=>d.remove()); d.showModal();
    } catch(e) {message(e.message);}
  }
  async function library() {
    try {
      const result=await api("library",undefined,null); const ready=result.jobs.filter(j=>j.status==="ready");
      const d=node("dialog","podcast-dialog"); d.append(node("h2",null,"Uložené podcasty"),button("Zavřít",()=>d.close(),"podcast-close"));
      if(!ready.length) d.append(node("p",null,"Zatím nemáš uložený žádný podcast."));
      for(const j of ready) d.append(button(`${label(j.id)} · ${Math.ceil(j.duration/60)} min`,()=>{panel.querySelector("audio")?.pause();activeId=j.id;epoch++;lastState="";previousStatus=null;status(j);d.close();},"podcast-library-item"));
      document.body.append(d);d.addEventListener("close",()=>d.remove());d.showModal();
    } catch(e) {message(e.message);}
  }
  window.BriefingPodcast = {mount(data, entry) {
    clearTimeout(poll); epoch++; panel?.querySelector("audio")?.pause(); activeId=entry?.id||null; lastState=""; previousStatus=null;
    const requested=new URLSearchParams(location.search).get("podcast");
    if(firstMount && validId(requested)) activeId=requested;
    firstMount=false;
    if(!activeId) return null;
    panel=node("section","podcast-panel");panel.setAttribute("aria-label","Podcast tohoto vydání");
    const actions=node("div","podcast-actions");actions.append(button("Připrav podcast",prepare,"podcast-button podcast-prepare"),button("Nastavení",openSettings,"podcast-secondary"));
    const statusText=node("p","podcast-status");statusText.setAttribute("role","status");
    panel.append(actions,statusText,node("div","podcast-content"));
    if(onService()) {
      const links=node("div","podcast-links");links.append(button("Scénář s přednesem",showScript,"podcast-text-button"),button("Uložené podcasty",library,"podcast-text-button"));panel.append(links);
      setTimeout(refresh,0);
      if(location.hash.startsWith("#setup=")) setTimeout(openSettings,0);
    } else statusText.textContent="Podcast a soukromé nastavení otevřeš na briefing.nacestach.online.";
    return panel;
  }};
})();
